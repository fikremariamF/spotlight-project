<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place where a store keeps stock. The default location holds every available unit that is not
 * counted at another location (see InventoryService), so it has no warehouse_stock rows of its own.
 */
#[Fillable(['name', 'code', 'address_line', 'contact_name', 'phone', 'notes'])]
class Warehouse extends Model
{
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_profile_id');
    }

    public function stock(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }
}
