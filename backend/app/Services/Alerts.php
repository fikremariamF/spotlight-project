<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Refund;
use App\Models\Review;
use App\Models\SellerLedgerEntry;
use App\Models\SellerProfile;
use App\Models\SellerRequest;
use App\Models\SellerRequestMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\Alert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Decides who hears about what, and on which channels. Callers only report what happened.
 *
 * Everything is sent after the surrounding database transaction commits, so a rolled-back
 * action never produces an alert. Support replies have their own unread badge, so customers hear
 * about them by email (rate-limited) rather than in-app.
 */
class Alerts
{
    public function orderPlaced(Checkout $checkout): void
    {
        $this->afterCommit(function () use ($checkout) {
            $checkout = Checkout::with(['customer', 'orders.items', 'orders.seller.user'])->find($checkout->id);
            if (! $checkout) {
                return;
            }

            foreach ($checkout->orders as $order) {
                $items = $order->items->sum('quantity');
                $where = $order->fulfillment === 'pickup' ? 'pickup' : 'delivery to '.$checkout->area_name;
                $this->notify($order->seller?->user, new Alert(
                    kind: 'order.new',
                    title: "New order {$order->number}",
                    body: self::plural($items, 'item').', '.self::etb($order->items_total_santim).", {$where}. Confirm it so the customer knows it is coming.",
                    url: "/seller/orders/{$order->id}",
                    channels: ['database', 'mail', 'sms'],
                    sms: "New order {$order->number}: ".self::plural($items, 'item').', '.self::etb($order->items_total_santim).'. Confirm it: '.Alert::link("/seller/orders/{$order->id}"),
                ));
            }

            $lines = $checkout->orders->map(fn (Order $o) => "{$o->number} from {$o->seller?->store_name}: ".self::etb($o->total_santim).($o->fulfillment === 'delivery' && $o->delivery_fee_santim === null ? ' plus delivery (the store will confirm the fee)' : ''))->all();
            $online = $checkout->isOnline();
            $this->notify($checkout->customer, new Alert(
                kind: 'checkout.placed',
                title: $online ? 'Payment received: '.self::etb($checkout->grand_total_santim) : 'We received your order',
                body: $online ? 'Thank you. Each store will now confirm its part.' : 'Each store will confirm its part. You pay cash on delivery.',
                url: '/orders',
                channels: ['mail'],
                mailLines: $lines,
            ));
        });
    }

    public function orderStatusChanged(Order $order, OrderStatus $from, OrderStatus $to, string $role): void
    {
        $this->afterCommit(function () use ($order, $to, $role) {
            $order = Order::with(['customer', 'checkout', 'seller.user'])->find($order->id);
            if (! $order) {
                return;
            }
            $store = $order->seller?->store_name ?? 'The store';
            $customerUrl = "/orders/{$order->id}";
            $phone = $order->checkout?->phone;
            $paid = $order->payment_method === 'online';

            $toCustomer = fn (string $kind, string $title, string $body, array $channels, ?string $sms = null) => $this->notify($order->customer, new Alert(
                kind: $kind, title: $title, body: $body, url: $customerUrl, channels: $channels,
                sms: $sms ? $sms.' '.Alert::link($customerUrl) : null, fallbackPhone: $phone,
            ));

            match ($to) {
                OrderStatus::Confirmed => $toCustomer('order.confirmed', "{$store} confirmed order {$order->number}",
                    'Total '.self::etb($order->total_santim).($paid ? ', already paid online.' : ', paid in cash '.($order->fulfillment === 'pickup' ? 'at pickup.' : 'on delivery.')),
                    ['database', 'mail', 'sms'],
                    "{$store} confirmed {$order->number}. Total ".self::etb($order->total_santim).'.'),
                OrderStatus::Processing => $toCustomer('order.processing', "{$store} is preparing order {$order->number}", 'We will tell you when it is ready.', ['database']),
                OrderStatus::Ready => $order->fulfillment === 'pickup'
                    ? $toCustomer('order.ready', "Order {$order->number} is ready for pickup", "Collect it from {$store}.".($paid ? ' It is already paid.' : ' Pay '.self::etb($order->total_santim).' at pickup.'), ['database', 'sms'],
                        "{$order->number} is ready for pickup at {$store}.".($paid ? ' Already paid.' : ' Pay '.self::etb($order->total_santim).'.'))
                    : $toCustomer('order.ready', "Order {$order->number} is ready", "{$store} will send it out soon.", ['database']),
                OrderStatus::Dispatched => $toCustomer('order.dispatched', "Order {$order->number} is on the way",
                    $paid ? 'It is already paid; there is nothing to pay on delivery.' : 'Have '.self::etb($order->total_santim).' ready in cash.', ['database', 'sms'],
                    "{$order->number} from {$store} is on the way.".($paid ? ' Already paid.' : ' Have '.self::etb($order->total_santim).' ready.')),
                OrderStatus::Delivered => $toCustomer('order.delivered', "Order {$order->number} was delivered",
                    'Thank you for your order. You can now rate the items you received.', ['database', 'mail']),
                OrderStatus::Cancelled => $this->orderCancelled($order, $role, $toCustomer),
                default => null,
            };
        });
    }

    private function orderCancelled(Order $order, string $role, \Closure $toCustomer): void
    {
        $reason = $order->cancel_reason ? 'Reason: '.$order->cancel_reason : 'No reason was given.';

        if ($role !== 'customer') {
            $who = $role === 'admin' ? 'The marketplace' : ($order->seller?->store_name ?? 'The store');
            $toCustomer('order.cancelled', "Order {$order->number} was cancelled", "{$who} cancelled it. {$reason}", ['database', 'mail', 'sms'],
                "{$who} cancelled {$order->number}.");
        }
        if ($role !== 'seller') {
            $who = $role === 'admin' ? 'The marketplace' : 'The customer';
            $url = "/seller/orders/{$order->id}";
            $this->notify($order->seller?->user, new Alert(
                kind: 'order.cancelled',
                title: "Order {$order->number} was cancelled",
                body: "{$who} cancelled it. {$reason} Its stock is back on your listings.",
                url: $url,
                channels: ['database', 'mail', 'sms'],
                sms: "{$who} cancelled {$order->number}. Do not send it. ".Alert::link($url),
            ));
        }
    }

    public function refundDue(Refund $refund): void
    {
        $this->afterCommit(function () use ($refund) {
            $refund = Refund::with(['customer', 'order'])->find($refund->id);
            if (! $refund) {
                return;
            }
            $what = $refund->order ? "order {$refund->order->number}" : 'your payment';
            $this->notify($refund->customer, new Alert(
                kind: 'refund.due',
                title: 'Refund on the way: '.self::etb($refund->amount_santim),
                body: "We will send back the money for {$what}. {$refund->reason}.",
                url: $refund->order ? "/orders/{$refund->order_id}" : '/orders',
                channels: ['database', 'mail', 'sms'],
                sms: 'We will refund '.self::etb($refund->amount_santim)." for {$what}.",
            ));
            $this->notifyAdmins(new Alert(
                kind: 'admin.refund_due',
                title: 'Refund to send: '.self::etb($refund->amount_santim),
                body: ($refund->customer?->name ?? 'A customer').": {$refund->reason}. Send it, then mark it refunded in Admin > Payments.",
                url: '/admin/payments',
                channels: ['database', 'mail'],
            ));
        });
    }

    public function refundCompleted(Refund $refund): void
    {
        $this->afterCommit(function () use ($refund) {
            $refund = Refund::with(['customer', 'order'])->find($refund->id);
            if (! $refund) {
                return;
            }
            $this->notify($refund->customer, new Alert(
                kind: 'refund.sent',
                title: 'Refund sent: '.self::etb($refund->amount_santim),
                body: ($refund->order ? "For order {$refund->order->number}." : 'For your payment.').($refund->reference ? " Reference: {$refund->reference}." : ''),
                url: $refund->order ? "/orders/{$refund->order_id}" : '/orders',
                channels: ['database', 'mail'],
            ));
        });
    }

    /**
     * An online checkout was cancelled before payment: by the customer, an admin, or because time ran out.
     */
    public function checkoutCancelledUnpaid(Checkout $checkout, string $role): void
    {
        if ($role === 'customer') {
            return;
        }
        $this->afterCommit(function () use ($checkout, $role) {
            $checkout = Checkout::with('customer')->find($checkout->id);
            $this->notify($checkout?->customer, new Alert(
                kind: 'checkout.expired',
                title: $role === 'system' ? 'Your order was cancelled: payment not completed' : 'Your unpaid order was cancelled',
                body: $role === 'system' ? 'The time to pay ran out, so the items were released. They are back in your cart if you still want them.' : 'The marketplace cancelled it before payment. Nothing was charged.',
                url: $role === 'system' ? '/cart' : '/orders',
                channels: ['database', 'mail'],
            ));
        });
    }

    public function payoutRecorded(SellerLedgerEntry $entry, int $stillOwedSantim): void
    {
        $this->afterCommit(function () use ($entry, $stillOwedSantim) {
            $seller = SellerProfile::with('user')->find($entry->seller_profile_id);
            $this->notify($seller?->user, new Alert(
                kind: 'balance.payout',
                title: 'Payout sent: '.self::etb($entry->amount_santim),
                body: ($entry->reference ? "Reference {$entry->reference}. " : '').($stillOwedSantim > 0 ? 'We still owe you '.self::etb($stillOwedSantim).'.' : 'Your online sales are fully paid out.'),
                url: '/seller/balance',
                channels: ['database', 'mail', 'sms'],
                sms: 'Payout sent: '.self::etb($entry->amount_santim).($entry->reference ? ", ref {$entry->reference}" : '').'.',
            ));
        });
    }

    /**
     * A customer opened an inquiry: every admin sees it in the bell; the support inbox shows the count.
     */
    public function supportOpened(SupportTicket $ticket): void
    {
        $this->afterCommit(function () use ($ticket) {
            $ticket = SupportTicket::with('product:id,name')->find($ticket->id);
            if (! $ticket) {
                return;
            }
            $this->notifyAdmins(new Alert(
                kind: 'support.new',
                title: 'New inquiry: '.$ticket->topic->label(),
                body: $ticket->subject,
                url: "/admin/support/{$ticket->id}",
                channels: ['database'],
            ));
        });
    }

    /**
     * The customer wrote again. Only the admin handling it hears about it (unassigned ones show in the inbox).
     */
    public function supportCustomerReplied(SupportTicket $ticket): void
    {
        $this->afterCommit(function () use ($ticket) {
            $ticket = SupportTicket::with('assignee')->find($ticket->id);
            if (! $ticket?->assignee) {
                return;
            }
            $this->notify($ticket->assignee, new Alert(
                kind: 'support.customer_replied',
                title: "Customer replied on inquiry {$ticket->reference()}",
                body: $ticket->subject,
                url: "/admin/support/{$ticket->id}",
                channels: $this->cooldown("support:{$ticket->id}", $ticket->assignee) ? ['database', 'mail'] : ['database'],
            ));
        });
    }

    /**
     * Betoch answered the customer. The Support badge shows it in the app, so this is email only,
     * at most one per inquiry in the cooldown.
     */
    public function supportReplied(SupportTicket $ticket): void
    {
        $this->afterCommit(function () use ($ticket) {
            $ticket = SupportTicket::with('customer')->find($ticket->id);
            if (! $ticket?->customer || ! $this->cooldown("support:{$ticket->id}", $ticket->customer)) {
                return;
            }
            $this->notify($ticket->customer, new Alert(
                kind: 'support.reply',
                title: 'Betoch Support answered your inquiry',
                body: $ticket->subject.'. Read the answer in Support.',
                url: "/support/{$ticket->id}",
                channels: ['mail'],
            ));
        });
    }

    public function supportAssigned(SupportTicket $ticket, User $admin): void
    {
        $this->afterCommit(fn () => $this->notify($admin, new Alert(
            kind: 'support.assigned',
            title: "Inquiry {$ticket->reference()} is now yours",
            body: $ticket->subject,
            url: "/admin/support/{$ticket->id}",
            channels: ['database'],
        )));
    }

    /**
     * Betoch asked the store something, or followed up. No customer details: the admin wrote the text.
     */
    public function storeAsked(SellerRequest $request, SellerRequestMessage $message): void
    {
        $this->afterCommit(function () use ($request, $message) {
            $request = SellerRequest::with('seller.user')->find($request->id);
            $user = $request?->seller?->user;
            if (! $user) {
                return;
            }
            $first = (int) $request->messages()->where('author_role', 'admin')->min('id') === $message->id;
            $channels = $this->cooldown("store-request:{$request->id}", $user) ? ['database', 'mail'] : ['database'];
            // A customer may be waiting on the answer, so a new request also texts the store, at most
            // once a day. Follow-ups never text. The text leaves out the subject the admin typed.
            $hours = (int) config('marketplace.alerts.store_request_sms_cooldown_hours');
            if ($first && Cache::add("alerts:store-request-sms:{$request->seller_profile_id}", true, now()->addHours($hours))) {
                $channels[] = 'sms';
            }
            $this->notify($user, new Alert(
                kind: 'store_request.new',
                title: $first ? 'Betoch has a question for your store' : 'New message from Betoch',
                body: $request->subject.'. Answer it in Admin requests.',
                url: "/seller/requests/{$request->id}",
                channels: $channels,
                sms: 'Betoch has a question for your store. Answer it: '.Alert::link("/seller/requests/{$request->id}"),
            ));
        });
    }

    /**
     * The store answered: the admin who asked, and whoever handles the inquiry, hear about it.
     */
    public function storeAnswered(SellerRequest $request): void
    {
        $this->afterCommit(function () use ($request) {
            $request = SellerRequest::with(['seller', 'creator', 'ticket.assignee'])->find($request->id);
            if (! $request) {
                return;
            }
            $url = $request->support_ticket_id ? "/admin/support/{$request->support_ticket_id}" : "/admin/support/requests/{$request->id}";
            $admins = collect([$request->creator, $request->ticket?->assignee])->filter()->unique('id');
            foreach ($admins as $admin) {
                $this->notify($admin, new Alert(
                    kind: 'store_request.answered',
                    title: ($request->seller?->store_name ?? 'A store').' answered your request',
                    body: $request->subject,
                    url: $url,
                    channels: $this->cooldown("store-answer:{$request->id}", $admin) ? ['database', 'mail'] : ['database'],
                ));
            }
        });
    }

    public function offerSent(Quote $quote): void
    {
        $this->afterCommit(function () use ($quote) {
            $quote = Quote::with('supportTicket.customer')->find($quote->id);
            if (! $quote?->supportTicket) {
                return;
            }
            $this->notify($quote->supportTicket->customer, new Alert(
                kind: 'quote.new',
                title: 'Betoch sent you an offer',
                body: "{$quote->title}: ".self::etb($quote->totalSantim()).'. Accept it in Support to order at this price.',
                url: "/support/{$quote->support_ticket_id}",
                channels: ['database', 'mail'],
            ));
        });
    }

    /**
     * An offer Betoch sent was accepted, declined or withdrawn. The store is told without the customer's name.
     */
    public function offerAnswered(Quote $quote, QuoteStatus $to): void
    {
        $this->afterCommit(function () use ($quote, $to) {
            $quote = Quote::with(['seller.user', 'supportTicket.assignee'])->find($quote->id);
            if (! $quote?->supportTicket || $to === QuoteStatus::Withdrawn) {
                return;
            }
            $verb = $to === QuoteStatus::Accepted ? 'accepted' : 'declined';
            $this->notify($quote->seller?->user, new Alert(
                kind: 'quote.answered',
                title: "The customer {$verb} your offer",
                body: $quote->title.($to === QuoteStatus::Accepted ? '. You will see it under Orders when they check out.' : ''),
                url: $quote->seller_request_id ? "/seller/requests/{$quote->seller_request_id}" : '/seller/orders',
                channels: ['database', 'mail'],
            ));
            $this->notify($quote->supportTicket->assignee, new Alert(
                kind: 'support.offer_answered',
                title: "Customer {$verb} the offer on inquiry {$quote->supportTicket->reference()}",
                body: $quote->title,
                url: "/admin/support/{$quote->support_ticket_id}",
                channels: ['database'],
            ));
        });
    }

    /**
     * A quote from before support messaging (sent in a store chat) was accepted or declined.
     *
     * @param  'accepted'|'declined'  $verb
     */
    public function quoteAnswered(Quote $quote, string $verb): void
    {
        $this->afterCommit(function () use ($quote, $verb) {
            $quote = Quote::with('seller.user')->find($quote->id);
            if (! $quote) {
                return;
            }
            $this->notify($quote->seller?->user, new Alert(
                kind: 'quote.answered',
                title: "A customer {$verb} your quote",
                body: $quote->title.($verb === 'accepted' ? '. You will see it under Orders when they check out.' : ''),
                url: '/seller/orders',
                channels: ['database', 'mail'],
            ));
        });
    }

    public function sellerReviewed(SellerProfile $seller, string $action, ?string $reason): void
    {
        $this->afterCommit(function () use ($seller, $action, $reason) {
            $seller = SellerProfile::with('user')->find($seller->id);
            if (! $seller) {
                return;
            }
            $why = $reason ? "Reason: {$reason}" : null;
            [$title, $body, $channels, $url] = match ($action) {
                'approve' => ["Your store {$seller->store_name} is approved", 'You can now add listings and receive orders.', ['database', 'mail', 'sms'], '/seller'],
                'reinstate' => ["Your store {$seller->store_name} is active again", 'Your listings are visible again.', ['database', 'mail', 'sms'], '/seller'],
                'reject' => ['Your store application was not approved', $why ?? 'Contact us for details.', ['database', 'mail'], '/seller'],
                'suspend' => ["Your store {$seller->store_name} is suspended", ($why ? $why.' ' : '').'Your listings are hidden until the store is reinstated.', ['database', 'mail', 'sms'], '/seller'],
                default => [null, null, [], null],
            };
            if ($title) {
                $this->notify($seller->user, new Alert(kind: "seller.{$action}", title: $title, body: $body, url: $url, channels: $channels));
            }
        });
    }

    public function sellerApplied(SellerProfile $seller): void
    {
        $this->afterCommit(fn () => $this->notifyAdmins(new Alert(
            kind: 'admin.seller_applied',
            title: "New store application: {$seller->store_name}",
            body: 'Review it in Admin > Sellers.',
            url: '/admin/sellers?status=pending',
            channels: ['database', 'mail'],
        )));
    }

    public function listingSubmitted(Product $product): void
    {
        $this->afterCommit(fn () => $this->notifyAdmins(new Alert(
            kind: 'admin.listing_submitted',
            title: "Listing waiting for review: {$product->name}",
            body: 'Review it in Admin > Listings.',
            url: '/admin/listings',
            channels: ['database'],
        )));
    }

    public function listingReviewed(Product $product, string $action, ?string $note): void
    {
        $this->afterCommit(function () use ($product, $action, $note) {
            $product = Product::with('seller.user')->find($product->id);
            if (! $product) {
                return;
            }
            $approved = $action === 'approve';
            $this->notify($product->seller?->user, new Alert(
                kind: $approved ? 'listing.approved' : 'listing.rejected',
                title: $approved ? "Your listing {$product->name} is live" : "Your listing {$product->name} needs changes",
                body: $approved ? 'Customers can now find and buy it.' : ($note ? "Note from the marketplace: {$note}" : 'Edit it and submit it again.'),
                url: $approved ? "/products/{$product->slug}" : "/seller/products/{$product->id}/edit",
                channels: ['database', 'mail'],
            ));
        });
    }

    public function reviewPosted(Review $review): void
    {
        $this->afterCommit(function () use ($review) {
            $review = Review::with(['seller.user', 'orderItem:id,name'])->find($review->id);
            if (! $review) {
                return;
            }
            $this->notify($review->seller?->user, new Alert(
                kind: 'review.new',
                title: "New {$review->rating}-star review",
                body: 'For '.($review->orderItem?->name ?? 'an item').'. You can reply publicly.',
                url: '/seller/reviews',
                channels: ['database'],
            ));
        });
    }

    public function reviewReplied(Review $review): void
    {
        $this->afterCommit(function () use ($review) {
            $review = Review::with(['customer', 'seller', 'orderItem:id,name'])->find($review->id);
            if (! $review) {
                return;
            }
            $this->notify($review->customer, new Alert(
                kind: 'review.reply',
                title: ($review->seller?->store_name ?? 'The store').' replied to your review',
                body: 'For '.($review->orderItem?->name ?? 'an item').'.',
                url: "/orders/{$review->order_id}",
                channels: ['database'],
            ));
        });
    }

    public function paymentRecorded(SellerLedgerEntry $entry, int $balanceSantim): void
    {
        $this->afterCommit(function () use ($entry, $balanceSantim) {
            $seller = SellerProfile::with('user')->find($entry->seller_profile_id);
            $this->notify($seller?->user, new Alert(
                kind: 'balance.payment',
                title: 'Commission payment recorded: '.self::etb(abs($entry->amount_santim)),
                body: 'You now owe '.self::etb(max(0, $balanceSantim)).'.',
                url: '/seller/balance',
                channels: ['database', 'mail'],
            ));
        });
    }

    /**
     * True at most once per key and person in the message cooldown, so a busy thread sends one email, not twenty.
     */
    private function cooldown(string $key, User $user): bool
    {
        $minutes = (int) config('marketplace.alerts.message_email_cooldown_minutes');

        return Cache::add("alerts:mail:{$key}:{$user->id}", true, now()->addMinutes($minutes));
    }

    /**
     * One alert, one person. The id is a time-ordered UUID so the in-app list keeps alerts written
     * in the same second in the order they happened.
     */
    private function notify(?User $user, Alert $alert): void
    {
        if (! $user) {
            return;
        }
        $alert = clone $alert;
        $alert->id = (string) Str::uuid7();
        try {
            Notification::send($user, $alert);
        } catch (\Throwable $e) {
            // The order, message or review is already saved; a failing mail server or SMS
            // provider must not turn that into an error for the person who did it.
            report($e);
        }
    }

    private function notifyAdmins(Alert $alert): void
    {
        User::where('role', Role::Admin)->where('status', UserStatus::Active)->orderBy('id')
            ->each(fn (User $admin) => $this->notify($admin, $alert));
    }

    private function afterCommit(\Closure $send): void
    {
        DB::afterCommit($send);
    }

    public static function etb(int $santim): string
    {
        $birr = number_format(intdiv($santim, 100));
        $cents = $santim % 100;

        return 'ETB '.$birr.($cents ? '.'.str_pad((string) $cents, 2, '0', STR_PAD_LEFT) : '');
    }

    private static function plural(int $n, string $word): string
    {
        return $n.' '.$word.($n === 1 ? '' : 's');
    }
}
