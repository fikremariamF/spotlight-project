<?php

namespace App\Http\Controllers\Api\Seller\Erp;

use App\Http\Controllers\Api\Seller\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\SellerErpSetting;
use App\Services\AuditLogger;
use App\Support\Erp\StoreLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    use ResolvesStore;

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => self::data(SellerErpSetting::forSeller($this->currentStore($request)->id))]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate([
            'low_stock_level' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'low_stock_alerts' => ['sometimes', 'boolean'],
            'cost_from_purchases' => ['sometimes', 'boolean'],
            'sku_prefix' => ['sometimes', 'nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
        ], ['sku_prefix.regex' => 'Use letters and numbers only.']);

        // The row may be created by another ERP write at the same moment, so it is taken under the store lock.
        $settings = DB::transaction(function () use ($store, $data, $audit) {
            $settings = StoreLock::take($store->id);
            $settings->fill($data);
            if (array_key_exists('sku_prefix', $data)) {
                $settings->sku_prefix = $data['sku_prefix'] ? strtoupper($data['sku_prefix']) : null;
            }
            $changed = array_values(array_intersect(array_keys($settings->getDirty()), array_keys($data)));
            $settings->save();
            if ($changed) {
                $audit->log('erp.settings_updated', $store, ['fields' => $changed]);
            }

            return $settings;
        });

        return response()->json(['data' => self::data($settings)]);
    }

    /** @return array<string, mixed> */
    private static function data(SellerErpSetting $s): array
    {
        return [
            'low_stock_level' => $s->low_stock_level,
            'low_stock_alerts' => $s->low_stock_alerts,
            'cost_from_purchases' => $s->cost_from_purchases,
            'sku_prefix' => $s->sku_prefix,
        ];
    }
}
