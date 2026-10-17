<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost_santim' => 'integer',
            'discount_santim' => 'integer',
            'line_total_santim' => 'integer',
            'received_quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class)->withTrashed();
    }

    public function outstanding(): int
    {
        return max(0, $this->quantity - $this->received_quantity);
    }
}
