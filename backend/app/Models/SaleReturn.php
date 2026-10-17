<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods a customer brought back, from an offline sale or a delivered marketplace order. For a
 * marketplace order this is the store's own record: the order, its payment and the commission stay
 * as they are, and Betoch support handles any change to them.
 */
class SaleReturn extends Model
{
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'returned_on' => 'date',
            'goods_credit_santim' => 'integer',
            'delivery_credit_santim' => 'integer',
            'refund_santim' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class)->orderBy('id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(OfflineSale::class, 'offline_sale_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function creditSantim(): int
    {
        return $this->goods_credit_santim + $this->delivery_credit_santim;
    }

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }
}
