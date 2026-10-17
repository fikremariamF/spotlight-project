<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money a store paid a supplier. Voided (with a reason) rather than edited or deleted. */
class SupplierPayment extends Model
{
    public const METHODS = ['cash', 'bank_transfer', 'telebirr', 'cbe_birr', 'cheque', 'other'];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount_santim' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }
}
