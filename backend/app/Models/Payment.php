<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One attempt to pay a checkout online. Only the provider's verify answer ever marks it paid.
 */
class Payment extends Model
{
    public const STATUSES = ['pending', 'paid', 'failed', 'cancelled', 'expired'];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $hidden = ['checkout_url'];

    protected function casts(): array
    {
        return [
            'amount_santim' => 'integer',
            'paid_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
