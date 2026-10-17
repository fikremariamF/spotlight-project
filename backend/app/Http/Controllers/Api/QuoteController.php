<?php

namespace App\Http\Controllers\Api;

use App\Enums\QuoteStatus;
use App\Enums\SupportStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Models\Conversation;
use App\Models\Quote;
use App\Models\SellerProfile;
use App\Models\SupportTicket;
use App\Services\Alerts;
use App\Services\AuditLogger;
use App\Services\SupportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customers accept or decline priced offers. New offers come from Betoch inside a support inquiry
 * (a store's proposal, sent by an admin). Quotes a store sent in its own chat before support messaging
 * can still be answered and ordered until they expire; that chat itself is read-only.
 */
class QuoteController extends Controller
{
    public function __construct(
        private readonly SupportService $support,
        private readonly AuditLogger $audit,
        private readonly Alerts $alerts,
    ) {}

    /**
     * A store withdraws a quote it sent in its old chat. Offers sent through support are withdrawn by Betoch.
     */
    public function withdraw(Request $request, int $id): QuoteResource
    {
        $seller = $request->user()->sellerProfile;

        $quote = DB::transaction(function () use ($seller, $id) {
            $quote = Quote::where('seller_profile_id', $seller?->id ?? 0)->whereNotNull('conversation_id')->lockForUpdate()->findOrFail($id);
            $this->assertPending($quote);
            $quote->status = QuoteStatus::Withdrawn;
            $quote->responded_at = now();
            $quote->save();
            $this->audit->log('quote.withdrawn', $quote, ['conversation_id' => $quote->conversation_id]);

            return $quote;
        });

        return new QuoteResource($quote);
    }

    public function accept(Request $request, int $id): QuoteResource
    {
        return $this->respond($request, $id, QuoteStatus::Accepted);
    }

    public function decline(Request $request, int $id): QuoteResource
    {
        return $this->respond($request, $id, QuoteStatus::Declined);
    }

    /**
     * Move a pending, unexpired quote to its final status exactly once, under row locks
     * (inquiry or conversation first, then the quote, the same order everywhere).
     */
    private function respond(Request $request, int $id, QuoteStatus $to): QuoteResource
    {
        $customer = $request->user();

        $quote = DB::transaction(function () use ($customer, $id, $to) {
            $refs = Quote::forCustomer($customer->id)->whereKey($id)->first(['id', 'conversation_id', 'support_ticket_id']) ?? abort(404);
            $ticket = $refs->support_ticket_id ? SupportTicket::lockForUpdate()->findOrFail($refs->support_ticket_id) : null;
            $conversation = $ticket ? null : Conversation::lockForUpdate()->findOrFail($refs->conversation_id);
            $quote = Quote::lockForUpdate()->findOrFail($id);
            $this->assertPending($quote);

            // Accepting creates a commitment, so the store must still be active. Declining is always allowed
            // so nobody is stuck with a live offer.
            if ($to === QuoteStatus::Accepted) {
                $open = $ticket ? $ticket->status !== SupportStatus::Closed : $conversation->isOpen();
                if (! $open || ! SellerProfile::publiclyVisible()->whereKey($quote->seller_profile_id)->exists()) {
                    throw ValidationException::withMessages(['quote' => 'This offer can no longer be accepted.']);
                }
            }

            $quote->status = $to;
            $quote->responded_at = now();
            $quote->save();

            if ($ticket) {
                $this->support->offerAnswered($quote, $customer, $to);
            } else {
                $this->audit->log('quote.'.$to->value, $quote, ['conversation_id' => $quote->conversation_id]);
                $this->alerts->quoteAnswered($quote, $to->value);
            }

            return $quote;
        });

        return new QuoteResource($quote->load('seller'));
    }

    private function assertPending(Quote $quote): void
    {
        $current = $quote->effectiveStatus();
        if ($current !== QuoteStatus::Pending) {
            throw ValidationException::withMessages(['quote' => 'This quote is already '.$current->value.'.']);
        }
    }
}
