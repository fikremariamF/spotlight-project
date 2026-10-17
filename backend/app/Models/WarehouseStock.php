<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Available units of one listing at one non-default location. Changed only by InventoryService. */
class WarehouseStock extends Model
{
    protected $table = 'warehouse_stock';

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
