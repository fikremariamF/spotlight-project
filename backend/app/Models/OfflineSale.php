<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sale the store made outside the marketplace (at the shop, by phone). Totals are worked out on
 * the server (SalesService). Never edited line by line or deleted: a mistake is voided, which puts
 * the units back, and entered again.
 */
class OfflineSale extends Model
{
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'sale_date' => 'date',
            'due_date' => 'date',
            'subtotal_santim' => 'integer',
            'discount_santim' => 'integer',
            'delivery_santim' => 'integer',
            'total_santim' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OfflineSaleItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OfflineSalePayment::class)->orderBy('paid_on')->orderBy('id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }
}
