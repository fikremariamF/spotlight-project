<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineSaleItem extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_santim' => 'integer',
            'discount_santim' => 'integer',
            'line_total_santim' => 'integer',
            'net_santim' => 'integer',
            'unit_cost_santim' => 'integer',
            'stock_taken' => 'boolean',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(OfflineSale::class, 'offline_sale_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class)->withTrashed();
    }
}
