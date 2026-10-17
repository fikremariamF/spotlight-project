<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only history of available stock. quantity is signed: negative takes units out.
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    // opening: count when tracking started. edit: changed on the listing form. count: stock count
    // correction. damaged / lost: written off. returned: back from a customer. found: extra units.
    // order_reserved / order_released: taken by an order and given back when it is cancelled.
    // purchase_received: goods from a supplier. transfer_out / transfer_in: moved between locations;
    // transfer_returned: a transfer cancelled on the way, back at the location it left.
    // offline_sale / offline_sale_voided: sold outside the marketplace, and put back when that sale is
    // voided. sale_returned / sale_return_voided: a customer brought units back, and that return undone.
    public const TYPES = [
        'opening', 'edit', 'count', 'damaged', 'lost', 'returned', 'found',
        'order_reserved', 'order_released',
        'purchase_received', 'transfer_out', 'transfer_in', 'transfer_returned',
        'offline_sale', 'offline_sale_voided', 'sale_returned', 'sale_return_voided',
    ];

    /** Adjustments a store may record by hand, and which way each one moves stock. */
    public const MANUAL = ['count' => 0, 'damaged' => -1, 'lost' => -1, 'returned' => 1, 'found' => 1];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'available_after' => 'integer',
            'location_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class)->withTrashed();
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
