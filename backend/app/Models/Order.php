<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One seller's part of a checkout. Totals are snapshots; nothing is mass assignable.
 */
class Order extends Model
{
    protected $attributes = [
        'status' => 'pending_confirmation',
        'payment_status' => 'unpaid',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'items_total_santim' => 'integer',
            'delivery_fee_santim' => 'integer',
            'total_santim' => 'integer',
            'paid_santim' => 'integer',
            'commission_rate_bp' => 'integer',
            'commission_santim' => 'integer',
            'paid_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class);
    }

    /**
     * Orders a store can see: cash orders, and online orders once paid. An online order that was
     * never paid (still waiting, or expired) is the customer's business only.
     */
    public function scopeVisibleToSeller(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->where('payment_method', '!=', 'online')->orWhereNotNull('paid_at'));
    }

    public function deliveryFeeConfirmed(): bool
    {
        return $this->fulfillment === 'pickup' || $this->delivery_fee_santim !== null;
    }

    /**
     * Commission rounded half up in integer santim: no floats touch money.
     */
    public static function commissionFor(int $itemsTotalSantim, int $rateBp): int
    {
        // Split the total so the multiplication cannot overflow 64-bit integers on very large orders.
        return intdiv($itemsTotalSantim, 10000) * $rateBp + intdiv(($itemsTotalSantim % 10000) * $rateBp + 5000, 10000);
    }
}
