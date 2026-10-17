<?php

namespace App\Http\Controllers\Api\Seller\Concerns;

use App\Enums\SellerStatus;
use App\Models\SellerProfile;
use Illuminate\Http\Request;

/**
 * The signed-in seller's store. Every seller lookup starts here, so ids from another store never
 * resolve. Suspended and rejected stores may read their records but not change them.
 */
trait ResolvesStore
{
    protected function currentStore(Request $request, bool $writable = false): SellerProfile
    {
        $profile = $request->user()->sellerProfile;
        abort_unless($profile, 403, 'No seller profile found for this account.');

        if ($writable && in_array($profile->status, [SellerStatus::Suspended, SellerStatus::Rejected], true)) {
            abort(403, 'Your store is '.$profile->status->value.'. Contact support.');
        }

        return $profile;
    }
}
