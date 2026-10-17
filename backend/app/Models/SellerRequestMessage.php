<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message between Betoch and a store. A store's message may carry a proposal (price and terms).
 */
class SellerRequestMessage extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'price_santim' => 'integer',
            'quantity' => 'integer',
            'delivery_fee_santim' => 'integer',
            'production_days' => 'integer',
            'valid_until' => 'date',
            'quote_id' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SellerRequest::class, 'seller_request_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function hasProposal(): bool
    {
        return $this->price_santim !== null;
    }

    public function proposalTotalSantim(): int
    {
        return (int) $this->price_santim * (int) $this->quantity + (int) $this->delivery_fee_santim;
    }
}
