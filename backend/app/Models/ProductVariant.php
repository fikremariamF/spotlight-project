<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One option of a listing (a size, color or material, or a mix), with its own stock. All attributes
 * are set explicitly by the controllers; stock only through InventoryService.
 */
class ProductVariant extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'price_santim' => 'integer',
            'cost_price_santim' => 'integer',
            'stock_quantity' => 'integer',
            'min_stock_level' => 'integer',
            'reorder_quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** "Grey, 3-seater, Oak": what a customer picks and what an order line shows. */
    public function label(): string
    {
        return implode(', ', array_filter([$this->color, $this->size, $this->material], fn ($v) => filled($v)));
    }

    /** The option's own price, or the listing's. */
    public function priceSantim(Product $product): int
    {
        return $this->price_santim ?? (int) $product->price_santim;
    }
}
