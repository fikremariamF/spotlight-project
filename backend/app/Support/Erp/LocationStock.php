<?php

namespace App\Support\Erp;

use App\Enums\StockType;
use App\Models\Product;
use App\Models\WarehouseStock;
use App\Services\InventoryService;

/**
 * Available units of every stock item of a store at each of its locations, in two queries. The
 * default location holds what is not counted elsewhere (see InventoryService).
 */
final class LocationStock
{
    /**
     * @param  array<string, array<string, mixed>>  $items  "productId:variantId" => item
     * @param  array<int, array<string, int>>  $at  warehouse id => item key => units
     */
    private function __construct(public readonly array $items, private readonly array $at) {}

    public static function forStore(int $storeId, InventoryService $inventory): self
    {
        $products = Product::where('seller_profile_id', $storeId)->where('stock_type', StockType::InStock->value)
            ->with(['variants', 'primaryImage'])->orderBy('name')->get();
        $rows = WarehouseStock::whereIn('product_id', $products->pluck('id'))->where('quantity', '>', 0)
            ->get(['warehouse_id', 'product_id', 'variant_id', 'quantity']);
        $default = $inventory->defaultWarehouse($storeId)->id;

        $items = [];
        $at = [];
        $elsewhere = [];
        foreach ($rows as $r) {
            $key = $r->product_id.':'.(int) $r->variant_id;
            $at[$r->warehouse_id][$key] = (int) $r->quantity;
            $elsewhere[$key] = ($elsewhere[$key] ?? 0) + (int) $r->quantity;
        }
        foreach ($products as $p) {
            $variants = $p->variants->isEmpty() ? [null] : $p->variants->all();
            foreach ($variants as $v) {
                $key = $p->id.':'.(int) $v?->id;
                $available = $inventory->available($p, $v);
                $items[$key] = [
                    'product_id' => $p->id, 'variant_id' => $v?->id, 'name' => $p->name, 'label' => $v?->label() ?: null,
                    'sku' => $v ? $v->sku : $p->sku, 'image' => $p->primaryImage?->thumbUrl(), 'status' => $p->status->value,
                    'cost_price_santim' => InventoryService::unitCost($p, $v), 'available' => $available,
                ];
                $at[$default][$key] = max(0, $available - ($elsewhere[$key] ?? 0));
            }
        }
        // Rows of options removed since, or of listings no longer kept in stock, are not stock items.
        foreach ($at as $w => $keys) {
            $at[$w] = array_filter(array_intersect_key($keys, $items));
        }

        return new self($items, $at);
    }

    /** @return list<array<string, mixed>> items with units at this location, most first */
    public function at(int $warehouseId): array
    {
        $list = [];
        foreach ($this->at[$warehouseId] ?? [] as $key => $units) {
            $list[] = $this->items[$key] + ['quantity' => $units];
        }
        usort($list, fn ($a, $b) => $b['quantity'] <=> $a['quantity'] ?: strcmp($a['name'], $b['name']));

        return $list;
    }

    /** @return array{units: int, items: int, value_cost_santim: int, missing_cost: int} */
    public function totals(int $warehouseId): array
    {
        $units = $value = $missing = 0;
        foreach ($this->at[$warehouseId] ?? [] as $key => $n) {
            $units += $n;
            $cost = $this->items[$key]['cost_price_santim'];
            $cost === null ? $missing++ : $value += $n * $cost;
        }

        return ['units' => $units, 'items' => count($this->at[$warehouseId] ?? []), 'value_cost_santim' => $value, 'missing_cost' => $missing];
    }
}
