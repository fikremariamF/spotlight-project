<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItem extends Model
{
    protected function casts(): array
    {
        return [
            'unit_price_santim' => 'integer',
            'unit_cost_santim' => 'integer',
            'quantity' => 'integer',
            'line_total_santim' => 'integer',
            'production_days' => 'integer',
            'reserved_quantity' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class)->withTrashed();
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }
}
