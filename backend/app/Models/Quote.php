<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// conversation (old store chats) or support inquiry, seller and status are set by the code that creates it.
#[Fillable(['product_id', 'title', 'description', 'price_santim', 'quantity', 'delivery_fee_santim', 'production_days', 'valid_until'])]
class Quote extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'conversation_id' => 'integer',
            'support_ticket_id' => 'integer',
            'price_santim' => 'integer',
            'delivery_fee_santim' => 'integer',
            'quantity' => 'integer',
            'production_days' => 'integer',
            'valid_until' => 'date',
            'responded_at' => 'datetime',
        ];
    }

    /** Quotes from before support messaging: sent by the store in its chat with the customer. */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** Offers Betoch sent on a store's behalf, inside the customer's support inquiry. */
    public function supportTicket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class);
    }

    public function sellerRequest(): BelongsTo
    {
        return $this->belongsTo(SellerRequest::class);
    }

    /**
     * Quotes made out to this customer, through either path.
     */
    public function scopeForCustomer(Builder $query, int $customerId): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereHas('conversation', fn (Builder $c) => $c->where('customer_id', $customerId))
            ->orWhereHas('supportTicket', fn (Builder $t) => $t->where('customer_id', $customerId)));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * Stored status, except a pending quote past its validity date reports as expired.
     * Validity is inclusive: a quote valid until today can still be accepted today.
     */
    public function effectiveStatus(): QuoteStatus
    {
        if ($this->status === QuoteStatus::Pending && $this->valid_until->endOfDay()->isPast()) {
            return QuoteStatus::Expired;
        }

        return $this->status;
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }

    /**
     * Last moment an accepted quote can be ordered: the end of its validity date, or a grace
     * period after acceptance when the customer accepted close to that date.
     */
    public function orderDeadline(): CarbonInterface
    {
        $grace = $this->responded_at?->copy()->addDays((int) config('marketplace.quotes.order_grace_days'));
        $end = $this->valid_until->copy()->endOfDay();

        return $grace && $grace->gt($end) ? $grace : $end;
    }

    /**
     * Whether the quote is already part of an order that has not been cancelled.
     */
    public function isOrdered(): bool
    {
        return OrderItem::where('quote_id', $this->id)
            ->whereHas('order', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->exists();
    }

    /**
     * Unit price for a cart line: the quote price covers `quantity` pieces as one line.
     */
    public function totalSantim(): int
    {
        return $this->price_santim * $this->quantity + (int) $this->delivery_fee_santim;
    }
}
