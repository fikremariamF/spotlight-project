<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A store's own ERP preferences. Read through forSeller(), which fills in defaults. */
#[Fillable(['low_stock_level', 'low_stock_alerts', 'cost_from_purchases', 'sku_prefix'])]
class SellerErpSetting extends Model
{
    protected $attributes = [
        'low_stock_level' => 2,
        'low_stock_alerts' => true,
        'cost_from_purchases' => true,
    ];

    protected function casts(): array
    {
        return [
            'low_stock_level' => 'integer',
            'low_stock_alerts' => 'boolean',
            'cost_from_purchases' => 'boolean',
        ];
    }

    public static function forSeller(int $sellerProfileId): self
    {
        $settings = self::where('seller_profile_id', $sellerProfileId)->first();
        if (! $settings) {
            $settings = new self;
            $settings->seller_profile_id = $sellerProfileId;
        }

        return $settings;
    }
}
