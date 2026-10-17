<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money a store spent running the business. The amount, date and payment method are fixed once
 * recorded: a mistake is voided (with a reason) and entered again.
 */
class Expense extends Model
{
    public const METHODS = SupplierPayment::METHODS;

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'spent_on' => 'date',
            'amount_santim' => 'integer',
            'attachment_size' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
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
