<?php

namespace App\Support\Erp;

use App\Models\SellerErpSetting;

/**
 * One lock per store for ERP writes that number documents or check a location: purchase orders,
 * receipts, transfers, closing a location. Taken first, before any purchase order, transfer or
 * listing row, so these writes cannot deadlock with each other or with checkout (which never takes it).
 */
final class StoreLock
{
    public static function take(int $sellerProfileId): SellerErpSetting
    {
        $settings = SellerErpSetting::where('seller_profile_id', $sellerProfileId)->lockForUpdate()->first();
        if ($settings) {
            return $settings;
        }
        SellerErpSetting::insertOrIgnore(['seller_profile_id' => $sellerProfileId, 'created_at' => now(), 'updated_at' => now()]);

        return SellerErpSetting::where('seller_profile_id', $sellerProfileId)->lockForUpdate()->firstOrFail();
    }

    /** The next running number of a store's documents in $table. Call while holding the lock. */
    public static function next(string $model, int $sellerProfileId): int
    {
        return (int) $model::where('seller_profile_id', $sellerProfileId)->max('sequence') + 1;
    }

    public static function number(string $prefix, int $sequence): string
    {
        return $prefix.'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
