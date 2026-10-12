<?php

namespace App\Http\Controllers\Api;

use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Models\Conversation;
use App\Models\Quote;
use App\Services\Alerts;
use App\Services\AuditLogger;
use App\Services\ConversationService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Priced offers inside a conversation. Sellers send and withdraw; customers accept or decline.
 * Sending a new quote withdraws the seller's earlier pending quote in the same conversation,
 * so the customer can only ever accept the latest terms.
 */
class QuoteController extends Controller
{
    public const MAX_VALID_DAYS = 60;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly AuditLogger $audit,
        private readonly Alerts $alerts,
    ) {}

    public function store(Request $request, int $conversationId): JsonResponse
    {
        $money = 'regex:/^\d{1,10}(\.\d{1,2})?$/';
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'description' => ['required', 'string', 'min:10', 'max:3000'],
            'price' => ['required', 'string', $money],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'delivery_fee' => ['nullable', 'string', $money],
            'production_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'valid_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addDays(self::MAX_VALID_DAYS)->toDateString()],
        ]);
        if (Money::toSantim($data['price']) < 100) {
            throw ValidationException::withMessages(['price' => 'The price must be at least 1 birr.']);
        }

        $seller = $request->user()->sellerProfile;

        $quote = DB::transaction(function () use ($request, $seller, $conversationId, $data) {
            $conversation = Conversation::where('seller_profile_id', $seller?->id ?? 0)->lockForUpdate()->findOrFail($conversationId);
            $this->conversations->assertWritable($conversation);

            $conversation->quotes()->where('status', QuoteStatus::Pending->value)
                ->update(['status' => QuoteStatus::Withdrawn->value, 'responded_at' => now()]);

            $quote = new Quote([
                'product_id' => $conversation->product_id,
                'title' => trim($data['title']),
                'description' => trim($data['description']),
                'price_santim' => Money::toSantim($data['price']),
                'quantity' => $data['quantity'] ?? 1,
                'delivery_fee_santim' => isset($data['delivery_fee']) ? Money::toSantim($data['delivery_fee']) : null,
                'production_days' => $data['production_days'] ?? null,
                'valid_until' => $data['valid_until'],
            ]);
            $quote->conversation_id = $conversation->id;
            $quote->seller_profile_id = $conversation->seller_profile_id;
            $quote->save();

            $this->conversations->post($conversation, $request->user(), 'seller',
                'Sent a quote: '.$quote->title.' for ETB '.Money::toBirr($quote->totalSantim()).' in total.', $quote);
            $this->audit->log('quote.sent', $quote, ['conversation_id' => $conversation->id, 'total_santim' => $quote->totalSantim()]);
            $this->alerts->quoteSent($quote);

            return $quote;
        });

        return (new QuoteResource($quote->refresh()))->response()->setStatusCode(201);
    }

    public function withdraw(Request $request, int $id): QuoteResource
    {
        $seller = $request->user()->sellerProfile;

        return $this->respond($request, $id, 'seller', QuoteStatus::Withdrawn,
            fn () => Quote::where('seller_profile_id', $seller?->id ?? 0), 'Withdrew the quote');
    }

    public function accept(Request $request, int $id): QuoteResource
    {
        return $this->respond($request, $id, 'customer', QuoteStatus::Accepted,
            fn () => $this->customerQuotes($request), 'Accepted the quote');
    }

    public function decline(Request $request, int $id): QuoteResource
    {
        return $this->respond($request, $id, 'customer', QuoteStatus::Declined,
            fn () => $this->customerQuotes($request), 'Declined the quote');
    }

    private function customerQuotes(Request $request)
    {
        return Quote::whereHas('conversation', fn ($q) => $q->where('customer_id', $request->user()->id));
    }

    /**
     * Move a pending, unexpired quote to its final status exactly once, under a row lock,
     * and note it in the conversation.
     */
    private function respond(Request $request, int $id, string $side, QuoteStatus $to, \Closure $scope, string $verb): QuoteResource
    {
        $quote = DB::transaction(function () use ($request, $id, $side, $to, $scope, $verb) {
            // Lock the conversation before the quote, the same order store() uses, to avoid deadlocks.
            $conversationId = $scope()->whereKey($id)->value('conversation_id') ?? abort(404);
            $conversation = Conversation::lockForUpdate()->findOrFail($conversationId);
            $quote = $scope()->lockForUpdate()->findOrFail($id);

            $current = $quote->effectiveStatus();
            if ($current !== QuoteStatus::Pending) {
                throw ValidationException::withMessages(['quote' => 'This quote is already '.$current->value.'.']);
            }
            // Accepting creates a commitment, so the store and thread must still be active. Declining or
            // withdrawing is always allowed so nobody is stuck with a live offer.
            if ($to === QuoteStatus::Accepted) {
                $this->conversations->assertWritable($conversation);
            }

            $quote->status = $to;
            $quote->responded_at = now();
            $quote->save();

            if ($conversation->isOpen()) {
                $this->conversations->post($conversation, $request->user(), $side, $verb.': '.$quote->title.'.', $quote);
            }
            $this->audit->log('quote.'.$to->value, $quote, ['conversation_id' => $conversation->id]);
            if ($to === QuoteStatus::Accepted || $to === QuoteStatus::Declined) {
                $this->alerts->quoteAnswered($quote, $to->value);
            }

            return $quote;
        });

        return new QuoteResource($quote);
    }
}
