<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money the platform owes back to a customer. Created automatically; an admin marks it refunded
 * after sending the money (in the provider's dashboard or by transfer) with the reference.
 */
class Refund extends Model
{
    public const METHODS = ['chapa', 'bank_transfer', 'telebirr', 'cash', 'other'];

    protected $attributes = [
        'status' => 'due',
    ];

    protected function casts(): array
    {
        return [
            'amount_santim' => 'integer',
            'refunded_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function refunder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }
}
