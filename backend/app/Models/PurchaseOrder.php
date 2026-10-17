<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An order a store places with a supplier. Totals are worked out on the server from the lines
 * (PurchasingService); stock changes only when goods are received.
 */
class PurchaseOrder extends Model
{
    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'sequence' => 'integer',
            'order_date' => 'date',
            'expected_date' => 'date',
            'subtotal_santim' => 'integer',
            'discount_santim' => 'integer',
            'charges_santim' => 'integer',
            'total_santim' => 'integer',
            'closed_short' => 'boolean',
            'ordered_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class)->orderBy('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
