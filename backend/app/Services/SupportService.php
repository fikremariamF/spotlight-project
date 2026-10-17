<?php

namespace App\Services;

use App\Enums\QuoteStatus;
use App\Enums\SellerRequestTopic;
use App\Enums\SupportStatus;
use App\Enums\SupportTopic;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quote;
use App\Models\SellerProfile;
use App\Models\SellerRequest;
use App\Models\SellerRequestMessage;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer support: customers write to Betoch, admins answer them and ask stores internally.
 *
 * Who sees what is decided here and in the resources, never in the browser:
 * - customers see their own inquiries and only rows with internal = false;
 * - stores see only requests addressed to them, never the customer's inquiry or contact details;
 * - a store's answer reaches the customer only when an admin writes it (or sends its proposal as an offer).
 *
 * Callers lock the inquiry or request row (lockForUpdate) before calling the write methods.
 */
class SupportService
{
    public function __construct(
        private readonly Alerts $alerts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * A customer's question. A second question with the same topic about the same listing or order,
     * while the first is still active, is added to it instead of opening a duplicate.
     *
     * @return array{0: SupportTicket, 1: bool} the inquiry and whether it is new
     */
    public function open(User $customer, SupportTopic $topic, ?string $subject, string $body, ?Product $product, ?Order $order, ?SellerProfile $store): array
    {
        return DB::transaction(function () use ($customer, $topic, $subject, $body, $product, $order, $store) {
            // Serialise this customer's inquiries so two quick submits cannot open two.
            User::whereKey($customer->id)->lockForUpdate()->first();

            $ticket = null;
            if ($product || $order) {
                $ticket = SupportTicket::where('customer_id', $customer->id)
                    ->where('topic', $topic->value)
                    ->where('product_id', $product?->id)
                    ->where('order_id', $order?->id)
                    ->whereIn('status', SupportStatus::activeValues())
                    ->lockForUpdate()
                    ->first();
            }
            $created = $ticket === null;

            if ($created) {
                $ticket = new SupportTicket;
                $ticket->customer_id = $customer->id;
                $ticket->topic = $topic;
                $ticket->subject = $subject ?: ($product?->name ?? ($order ? 'Order '.$order->number : $topic->label()));
                $ticket->product_id = $product?->id;
                $ticket->order_id = $order?->id;
                $ticket->seller_profile_id = $product?->seller_profile_id ?? $order?->seller_profile_id ?? $store?->id;
                $ticket->status = SupportStatus::New;
                $ticket->save();
                $this->audit->log('support.created', $ticket, ['topic' => $topic->value, 'product_id' => $product?->id, 'order_id' => $order?->id]);
                $this->alerts->supportOpened($ticket);
                $this->append($ticket, $customer, 'customer', 'message', false, $body);
            } else {
                $this->customerReply($ticket, $customer, $body);
            }

            return [$ticket, $created];
        });
    }

    public function customerReply(SupportTicket $ticket, User $customer, string $body): SupportMessage
    {
        if ($ticket->status === SupportStatus::Closed) {
            throw ValidationException::withMessages(['body' => 'This inquiry is closed. Start a new one if you still need help.']);
        }

        return DB::transaction(function () use ($ticket, $customer, $body) {
            // A reply means the customer still needs help, so a resolved or waiting inquiry is active again.
            if (in_array($ticket->status, [SupportStatus::Waiting, SupportStatus::Resolved], true)) {
                $this->changeStatus($ticket, null, SupportStatus::Open, 'The customer replied.');
            }
            $message = $this->append($ticket, $customer, 'customer', 'message', false, $body);
            $this->alerts->supportCustomerReplied($ticket);

            return $message;
        });
    }

    /**
     * An admin's reply to the customer. The first reply opens a new inquiry and, when nobody has it yet,
     * assigns it to the admin who answered.
     */
    public function adminReply(SupportTicket $ticket, User $admin, string $body, ?SupportStatus $then = null): SupportMessage
    {
        if ($ticket->status === SupportStatus::Closed && $then === null) {
            throw ValidationException::withMessages(['body' => 'This inquiry is closed. Reopen it first.']);
        }

        return DB::transaction(function () use ($ticket, $admin, $body, $then) {
            if ($ticket->assigned_to === null) {
                $this->assign($ticket, $admin, $admin);
            }
            // Reopening comes before the reply so the history reads in order.
            if ($ticket->status === SupportStatus::Closed) {
                $this->changeStatus($ticket, $admin, $then);
            }
            $message = $this->append($ticket, $admin, 'admin', 'message', false, $body);
            $ticket->first_response_at ??= $message->created_at;
            $ticket->save();

            $to = $then ?? ($ticket->status === SupportStatus::New ? SupportStatus::Open : null);
            if ($to !== null && $to !== $ticket->status) {
                $this->changeStatus($ticket, $admin, $to);
            }
            $this->audit->log('support.replied', $ticket, ['message_id' => $message->id]);
            $this->alerts->supportReplied($ticket);

            return $message;
        });
    }

    public function note(SupportTicket $ticket, User $admin, string $body): SupportMessage
    {
        $message = $this->append($ticket, $admin, 'admin', 'note', true, $body);
        $this->audit->log('support.note_added', $ticket, ['message_id' => $message->id]);

        return $message;
    }

    /**
     * Records the change in the inquiry's history (admins only) and the audit log.
     */
    public function changeStatus(SupportTicket $ticket, ?User $actor, SupportStatus $to, ?string $note = null): void
    {
        $from = $ticket->status;
        if ($from === $to) {
            return;
        }

        DB::transaction(function () use ($ticket, $actor, $from, $to, $note) {
            $ticket->status = $to;
            $ticket->resolved_at = $to === SupportStatus::Resolved ? now() : ($to === SupportStatus::Closed ? $ticket->resolved_at : null);
            $ticket->closed_at = $to === SupportStatus::Closed ? now() : null;
            $ticket->save();

            if ($to === SupportStatus::Closed) {
                // A closed inquiry cannot hold a live offer: whoever closes it ends any pending one.
                $ended = $actor?->id === $ticket->customer_id ? QuoteStatus::Declined : QuoteStatus::Withdrawn;
                $ticket->quotes()->where('status', QuoteStatus::Pending->value)
                    ->update(['status' => $ended->value, 'responded_at' => now()]);
            }

            $this->append($ticket, $actor, $actor ? ($actor->id === $ticket->customer_id ? 'customer' : 'admin') : 'system', 'event', true,
                'Status: '.$from->label().' to '.$to->label().($note ? '. '.$note : ''), meta: ['from' => $from->value, 'to' => $to->value]);
            $this->audit->log('support.status_changed', $ticket, ['from' => $from->value, 'to' => $to->value]);
        });
    }

    public function assign(SupportTicket $ticket, User $by, ?User $to): void
    {
        if ($ticket->assigned_to === $to?->id) {
            return;
        }
        $ticket->assigned_to = $to?->id;
        $ticket->save();
        $this->append($ticket, $by, 'admin', 'event', true, $to ? 'Assigned to '.$to->name : 'Unassigned', meta: ['assigned_to' => $to?->id]);
        $this->audit->log('support.assigned', $ticket, ['assigned_to' => $to?->id]);
        if ($to && $to->id !== $by->id) {
            $this->alerts->supportAssigned($ticket, $to);
        }
    }

    public function escalate(SupportTicket $ticket, User $admin, bool $on, ?string $reason): void
    {
        if ($on === ($ticket->escalated_at !== null) && (! $on || $reason === $ticket->escalation_reason)) {
            return;
        }
        $ticket->escalated_at = $on ? ($ticket->escalated_at ?? now()) : null;
        $ticket->escalation_reason = $on ? $reason : null;
        $ticket->save();
        $this->append($ticket, $admin, 'admin', 'event', true, $on ? 'Escalated'.($reason ? ': '.$reason : '') : 'No longer escalated',
            meta: ['escalated' => $on]);
        $this->audit->log($on ? 'support.escalated' : 'support.deescalated', $ticket, ['reason' => $reason]);
    }

    /**
     * Ask a store something. The listing or order, when given, must be the store's own.
     */
    public function ask(User $admin, SellerProfile $store, ?SupportTicket $ticket, SellerRequestTopic $topic, string $subject, string $body, ?Product $product, ?Order $order): SellerRequest
    {
        if ($product && $product->seller_profile_id !== $store->id) {
            throw ValidationException::withMessages(['product_id' => 'This listing belongs to another store.']);
        }
        if ($order && $order->seller_profile_id !== $store->id) {
            throw ValidationException::withMessages(['order_id' => 'This order belongs to another store.']);
        }
        if ($order && ! Order::whereKey($order->id)->visibleToSeller()->exists()) {
            throw ValidationException::withMessages(['order_id' => 'The store cannot see this order yet (it is waiting for online payment).']);
        }

        return DB::transaction(function () use ($admin, $store, $ticket, $topic, $subject, $body, $product, $order) {
            $request = new SellerRequest;
            $request->support_ticket_id = $ticket?->id;
            $request->seller_profile_id = $store->id;
            $request->product_id = $product?->id;
            $request->order_id = $order?->id;
            $request->topic = $topic;
            $request->subject = $subject;
            $request->created_by = $admin->id;
            $request->save();

            $this->requestMessage($request, $admin, 'admin', $body);
            if ($ticket) {
                // Working on an unassigned inquiry takes it, as replying does.
                if ($ticket->assigned_to === null) {
                    $this->assign($ticket, $admin, $admin);
                }
                $this->append($ticket, $admin, 'admin', 'event', true, "Asked {$store->store_name}: {$subject}", meta: ['seller_request_id' => $request->id]);
                if ($ticket->status === SupportStatus::New) {
                    $this->changeStatus($ticket, $admin, SupportStatus::Open);
                }
            }
            $this->audit->log('support.store_asked', $request, ['support_ticket_id' => $ticket?->id, 'seller_profile_id' => $store->id]);

            return $request;
        });
    }

    /**
     * A message in a store request. $proposal (store only) holds santim amounts and terms already validated.
     *
     * @param  array{price_santim?: int, quantity?: int, delivery_fee_santim?: int|null, production_days?: int|null, valid_until?: string}  $proposal
     */
    public function requestMessage(SellerRequest $request, ?User $author, string $role, string $body, array $proposal = []): SellerRequestMessage
    {
        if ($request->isClosed() && $role !== 'system') {
            throw ValidationException::withMessages(['body' => 'This request is closed.']);
        }

        return DB::transaction(function () use ($request, $author, $role, $body, $proposal) {
            $message = new SellerRequestMessage;
            $message->seller_request_id = $request->id;
            $message->author_id = $author?->id;
            $message->author_role = $role;
            $message->body = $body;
            if ($proposal) {
                $message->price_santim = $proposal['price_santim'];
                $message->quantity = $proposal['quantity'] ?? 1;
                $message->delivery_fee_santim = $proposal['delivery_fee_santim'] ?? null;
                $message->production_days = $proposal['production_days'] ?? null;
                $message->valid_until = $proposal['valid_until'];
            }
            $message->save();

            $request->last_message_id = $message->id;
            $request->last_message_at = $message->created_at;
            if ($role !== 'system') {
                $request->last_message_by = $role;
            }
            if ($role === 'seller') {
                // The store has seen everything up to its own message.
                $request->seller_last_read_id = $message->id;
                $request->status = SellerRequest::STATUS_ANSWERED;
            } elseif ($role === 'admin') {
                $request->status = SellerRequest::STATUS_OPEN;
            }
            $request->save();

            if ($role === 'seller') {
                if ($request->support_ticket_id) {
                    $ticket = SupportTicket::lockForUpdate()->find($request->support_ticket_id);
                    $request->loadMissing('seller');
                    $this->append($ticket, null, 'system', 'event', true,
                        $request->seller->store_name.' answered'.($proposal ? ' with a proposal of '.Alerts::etb($message->proposalTotalSantim()) : ''),
                        meta: ['seller_request_id' => $request->id, 'seller_message_id' => $message->id]);
                }
                $this->audit->log('support.store_replied', $request, ['message_id' => $message->id, 'proposal' => (bool) $proposal]);
                $this->alerts->storeAnswered($request);
            } elseif ($role === 'admin') {
                $this->alerts->storeAsked($request, $message);
            }

            return $message;
        });
    }

    public function closeRequest(SellerRequest $request, User $admin): void
    {
        if ($request->isClosed()) {
            return;
        }
        $request->status = SellerRequest::STATUS_CLOSED;
        $request->closed_at = now();
        $request->save();
        $this->audit->log('support.store_request_closed', $request);
    }

    /**
     * Send a store's proposal to the customer as a Betoch offer. Price, quantity, delivery and production
     * time are the store's and cannot be changed here; the admin words the title and description.
     * A newer offer from the same store on the same inquiry replaces a pending one.
     */
    public function relay(SellerRequest $request, SellerRequestMessage $proposal, User $admin, string $title, string $description, CarbonInterface $validUntil): Quote
    {
        $ticket = $request->support_ticket_id ? SupportTicket::lockForUpdate()->find($request->support_ticket_id) : null;
        $store = SellerProfile::publiclyVisible()->find($request->seller_profile_id);

        $error = match (true) {
            $proposal->seller_request_id !== $request->id => 'This proposal belongs to another request.',
            ! $proposal->hasProposal() || $proposal->author_role !== 'seller' => 'Only a store\'s proposal can be sent as an offer.',
            $proposal->quote_id !== null => 'This proposal has already been sent to the customer.',
            $ticket === null => 'This request is not linked to a customer inquiry.',
            $ticket->status === SupportStatus::Closed => 'The inquiry is closed. Reopen it first.',
            $store === null => 'The store is not active, so its offer cannot be sent.',
            $proposal->valid_until->endOfDay()->isPast() => 'The store\'s proposal has expired. Ask the store for a new one.',
            $validUntil->gt($proposal->valid_until) => 'The offer cannot stay open longer than the store agreed ('.$proposal->valid_until->toDateString().').',
            default => null,
        };
        if ($error) {
            throw ValidationException::withMessages(['proposal' => $error]);
        }

        return DB::transaction(function () use ($proposal, $request, $ticket, $admin, $title, $description, $validUntil) {
            $ticket->quotes()->where('seller_profile_id', $request->seller_profile_id)->where('status', QuoteStatus::Pending->value)
                ->update(['status' => QuoteStatus::Withdrawn->value, 'responded_at' => now()]);

            $quote = new Quote([
                'product_id' => $request->product_id ?? $ticket->product_id,
                'title' => $title,
                'description' => $description,
                'price_santim' => $proposal->price_santim,
                'quantity' => $proposal->quantity,
                'delivery_fee_santim' => $proposal->delivery_fee_santim,
                'production_days' => $proposal->production_days,
                'valid_until' => $validUntil->toDateString(),
            ]);
            $quote->support_ticket_id = $ticket->id;
            $quote->seller_request_id = $request->id;
            $quote->seller_profile_id = $request->seller_profile_id;
            $quote->save();

            $proposal->quote_id = $quote->id;
            $proposal->save();

            if ($ticket->assigned_to === null) {
                $this->assign($ticket, $admin, $admin);
            }
            $message = $this->append($ticket, $admin, 'admin', 'offer', false, 'Sent an offer: '.$quote->title.' for '.Alerts::etb($quote->totalSantim()).' in total.', $quote);
            $ticket->first_response_at ??= $message->created_at;
            $ticket->save();
            if ($ticket->status === SupportStatus::New) {
                $this->changeStatus($ticket, $admin, SupportStatus::Open);
            }
            $this->requestMessage($request, null, 'system', 'Betoch sent your proposal to the customer as an offer ('.Alerts::etb($quote->totalSantim()).').');
            $this->audit->log('quote.relayed', $quote, ['support_ticket_id' => $ticket->id, 'seller_request_id' => $request->id, 'total_santim' => $quote->totalSantim()]);
            $this->alerts->offerSent($quote);

            return $quote;
        });
    }

    /**
     * The customer accepted or declined an offer, or an admin withdrew it. Recorded on the inquiry
     * (the customer sees it) and on the store's request.
     */
    public function offerAnswered(Quote $quote, User $by, QuoteStatus $to): void
    {
        $ticket = SupportTicket::lockForUpdate()->find($quote->support_ticket_id);
        if (! $ticket) {
            return;
        }
        $isCustomer = $by->id === $ticket->customer_id;
        $verb = match ($to) {
            QuoteStatus::Accepted => 'Accepted the offer',
            QuoteStatus::Declined => 'Declined the offer',
            default => 'Withdrew the offer',
        };
        $this->append($ticket, $by, $isCustomer ? 'customer' : 'admin', 'offer', false, $verb.': '.$quote->title.'.', $quote);

        $request = $quote->seller_request_id ? SellerRequest::lockForUpdate()->find($quote->seller_request_id) : null;
        if ($request) {
            $text = match ($to) {
                QuoteStatus::Accepted => 'The customer accepted your offer "'.$quote->title.'". The order appears under Orders once they check out.',
                QuoteStatus::Declined => 'The customer declined your offer "'.$quote->title.'".',
                default => 'Betoch withdrew the offer "'.$quote->title.'".',
            };
            $this->requestMessage($request, null, 'system', $text);
        }
        $this->audit->log('quote.'.$to->value, $quote, ['support_ticket_id' => $ticket->id]);
        $this->alerts->offerAnswered($quote, $to);
    }

    /**
     * Write one row on an inquiry and move its pointers. Notes and history never touch what the customer sees.
     *
     * @param  array<string, mixed>  $meta
     */
    private function append(SupportTicket $ticket, ?User $author, string $role, string $kind, bool $internal, string $body, ?Quote $quote = null, array $meta = []): SupportMessage
    {
        $message = new SupportMessage;
        $message->support_ticket_id = $ticket->id;
        $message->author_id = $author?->id;
        $message->author_role = $role;
        $message->kind = $kind;
        $message->internal = $internal;
        $message->body = $body;
        $message->quote_id = $quote?->id;
        $message->meta = $meta ?: null;
        $message->save();

        $ticket->last_activity_at = $message->created_at;
        if (! $internal) {
            $ticket->last_public_message_id = $message->id;
            $ticket->last_public_message_at = $message->created_at;
            $ticket->last_public_by = $role === 'customer' ? 'customer' : 'admin';
            if ($role === 'customer') {
                $ticket->customer_last_read_id = $message->id;
            }
        }
        $ticket->save();

        return $message;
    }

    /**
     * Turn a proposal's birr strings into santim, with the same rules as the store's own quotes had.
     *
     * @param  array<string, mixed>  $data  validated input
     * @return array{price_santim: int, quantity: int, delivery_fee_santim: int|null, production_days: int|null, valid_until: string}
     */
    public static function proposalFrom(array $data, bool $storesSetFee): array
    {
        $price = Money::toSantim($data['price']);
        if ($price < 100) {
            throw ValidationException::withMessages(['price' => 'The price must be at least 1 birr.']);
        }
        $fee = isset($data['delivery_fee']) && $data['delivery_fee'] !== '' ? Money::toSantim($data['delivery_fee']) : null;
        // Commission is charged on the price, so while admins price delivery a store cannot move part
        // of the price into its own delivery charge. Free delivery is always fine.
        if ($fee !== null && $fee > 0 && ! $storesSetFee) {
            throw ValidationException::withMessages(['delivery_fee' => 'The marketplace sets delivery prices. Leave this empty, or offer free delivery.']);
        }

        return [
            'price_santim' => $price,
            'quantity' => (int) ($data['quantity'] ?? 1),
            'delivery_fee_santim' => $fee,
            'production_days' => isset($data['production_days']) ? (int) $data['production_days'] : null,
            'valid_until' => $data['valid_until'],
        ];
    }
}
