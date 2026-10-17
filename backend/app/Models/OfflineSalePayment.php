<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money a customer paid the store for an offline sale. Voided (with a reason) rather than edited or deleted. */
class OfflineSalePayment extends Model
{
    public const METHODS = SupplierPayment::METHODS;

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount_santim' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(OfflineSale::class, 'offline_sale_id');
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
