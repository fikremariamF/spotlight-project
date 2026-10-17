<?php

namespace App\Support\Erp;

use App\Enums\OrderStatus;
use App\Models\OfflineSale;
use App\Models\OfflineSaleItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;

/**
 * What a store sold in a period: marketplace orders on the day they were delivered, offline sales
 * on their sale date, and returns on the day the goods came back. Voided sales and returns are left
 * out. Profit is an estimate from the cost prices the store entered: lines without a cost are left
 * out of it rather than counted as profit.
 */
final class SalesReport
{
    /** @return array<string, mixed> */
    /** @param  int|null  $productLimit  how many product rows to return; null for all */
    public static function for(int $storeId, Period $period, ?int $productLimit = 300): array
    {
        [$start, $end] = $period->bounds();
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();
        $days = array_fill_keys($period->days(), ['orders' => 0, 'sales' => 0, 'marketplace_santim' => 0, 'offline_santim' => 0, 'returns_santim' => 0]);

        // Marketplace orders, on their delivery day.
        $orders = Order::where('seller_profile_id', $storeId)->where('status', OrderStatus::Delivered->value)
            ->whereBetween('delivered_at', [$start, $end])
            ->get(['id', 'delivered_at', 'items_total_santim', 'delivery_fee_santim', 'commission_santim']);
        foreach ($orders as $o) {
            $day = $period->dayOf($o->delivered_at);
            if (isset($days[$day])) {
                $days[$day]['orders']++;
                $days[$day]['marketplace_santim'] += $o->items_total_santim;
            }
        }
        $orderLines = fn () => OrderItem::whereIn('order_id', $orders->pluck('id'));
        $mCosted = $orderLines()->whereNotNull('unit_cost_santim')->selectRaw('sum(unit_cost_santim * quantity) as c, sum(line_total_santim) as r')->first();
        $marketplace = [
            'orders' => $orders->count(),
            'items_santim' => (int) $orders->sum('items_total_santim'),
            'delivery_santim' => (int) $orders->sum('delivery_fee_santim'),
            'commission_santim' => (int) $orders->sum('commission_santim'),
            'cost_of_goods_santim' => (int) $mCosted->c,
            'lines_without_cost' => $orderLines()->whereNull('unit_cost_santim')->count(),
            'sales_without_cost_santim' => (int) $orderLines()->whereNull('unit_cost_santim')->sum('line_total_santim'),
            'gross_profit_santim' => (int) $mCosted->r - (int) $mCosted->c,
        ];

        // Offline sales, on their sale date. Dates compared as dates: SQLite keeps them with a time part.
        $sales = OfflineSale::where('seller_profile_id', $storeId)->where('status', 'completed')
            ->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to)
            ->get(['id', 'sale_date', 'subtotal_santim', 'discount_santim', 'delivery_santim', 'total_santim']);
        foreach ($sales as $s) {
            $day = $s->sale_date->toDateString();
            if (isset($days[$day])) {
                $days[$day]['sales']++;
                $days[$day]['offline_santim'] += $s->subtotal_santim - $s->discount_santim;
            }
        }
        $saleLines = fn () => OfflineSaleItem::whereIn('offline_sale_id', $sales->pluck('id'));
        $oCosted = $saleLines()->whereNotNull('unit_cost_santim')->selectRaw('sum(unit_cost_santim * quantity) as c, sum(net_santim) as r')->first();
        $offline = [
            'sales' => $sales->count(),
            'items_santim' => (int) $sales->sum('subtotal_santim') - (int) $sales->sum('discount_santim'),
            'discount_santim' => (int) $sales->sum('discount_santim'),
            'delivery_santim' => (int) $sales->sum('delivery_santim'),
            'cost_of_goods_santim' => (int) $oCosted->c,
            'lines_without_cost' => $saleLines()->whereNull('unit_cost_santim')->count(),
            'sales_without_cost_santim' => (int) $saleLines()->whereNull('unit_cost_santim')->sum('net_santim'),
            'gross_profit_santim' => (int) $oCosted->r - (int) $oCosted->c,
        ];

        // Returns, on the day the goods came back (of sales from any period).
        $returns = SaleReturn::where('seller_profile_id', $storeId)->where('status', 'recorded')
            ->whereDate('returned_on', '>=', $from)->whereDate('returned_on', '<=', $to)
            ->get(['id', 'returned_on', 'offline_sale_id', 'order_id', 'goods_credit_santim', 'delivery_credit_santim', 'refund_santim']);
        foreach ($returns as $r) {
            $day = $r->returned_on->toDateString();
            if (isset($days[$day])) {
                $days[$day]['returns_santim'] += $r->creditSantim();
            }
        }
        $returnLines = fn () => SaleReturnItem::whereIn('sale_return_id', $returns->pluck('id'));
        // A return takes back the line's profit: its credit no longer counts as sales, and restocked
        // units are stock again, so their cost no longer counts as sold.
        $costedCredit = (int) $returnLines()->whereNotNull('unit_cost_santim')->sum('credit_santim');
        $costBack = (int) $returnLines()->whereNotNull('unit_cost_santim')->where('restocked', true)->selectRaw('sum(unit_cost_santim * quantity) as c')->value('c');
        $returned = [
            'returns' => $returns->count(),
            'units' => (int) $returnLines()->sum('quantity'),
            'goods_santim' => (int) $returns->sum('goods_credit_santim'),
            'delivery_santim' => (int) $returns->sum('delivery_credit_santim'),
            'refund_santim' => (int) $returns->sum('refund_santim'),
            'marketplace_santim' => (int) $returns->whereNotNull('order_id')->sum(fn ($r) => $r->creditSantim()),
            'offline_santim' => (int) $returns->whereNotNull('offline_sale_id')->sum(fn ($r) => $r->creditSantim()),
            'gross_profit_effect_santim' => $costBack - $costedCredit,
        ];

        $items = $marketplace['items_santim'] + $offline['items_santim'];
        $delivery = $marketplace['delivery_santim'] + $offline['delivery_santim'];
        $credits = $returned['goods_santim'] + $returned['delivery_santim'];

        return [
            'range' => $period->toArray(),
            'totals' => [
                'sales' => $marketplace['orders'] + $offline['sales'],
                'items_santim' => $items,
                'delivery_santim' => $delivery,
                'returns_santim' => $credits,
                'net_santim' => $items + $delivery - $credits,
                'gross_profit_santim' => $marketplace['gross_profit_santim'] + $offline['gross_profit_santim'] + $returned['gross_profit_effect_santim'],
                'lines_without_cost' => $marketplace['lines_without_cost'] + $offline['lines_without_cost'],
            ],
            'marketplace' => $marketplace,
            'offline' => $offline,
            'returns' => $returned,
            'days' => collect($days)->map(fn ($d, $date) => ['date' => $date, 'items_santim' => $d['marketplace_santim'] + $d['offline_santim']] + $d)->values()->all(),
            'products' => self::products($orders->pluck('id')->all(), $sales->pluck('id')->all(), $returns->pluck('id')->all(), $productLimit),
        ];
    }

    /**
     * Units and money per stock item, most sold first. Custom-quote lines and free-text lines are
     * one "other" row, since they are not stock items.
     *
     * @return list<array<string, mixed>>
     */
    private static function products(array $orderIds, array $saleIds, array $returnIds, ?int $limit): array
    {
        $rows = [];
        $add = function ($productId, $variantId, string $source, int $units, int $money, int $cost, bool $costed) use (&$rows) {
            $key = $productId ? $productId.':'.(int) $variantId : 'other';
            $rows[$key] ??= [
                'product_id' => $productId ? (int) $productId : null, 'variant_id' => $productId && $variantId ? (int) $variantId : null,
                'name' => null, 'label' => null,
                'units' => 0, 'marketplace_units' => 0, 'offline_units' => 0, 'returned_units' => 0,
                'revenue_santim' => 0, 'returned_santim' => 0, 'cost_santim' => 0, 'costed' => true,
            ];
            $row = &$rows[$key];
            if ($source === 'returned') {
                $row['returned_units'] += $units;
                $row['returned_santim'] += $money;
                $row['cost_santim'] -= $cost;
            } else {
                $row['units'] += $units;
                $row[$source.'_units'] += $units;
                $row['revenue_santim'] += $money;
                $row['cost_santim'] += $cost;
                $row['costed'] = $row['costed'] && $costed;
            }
        };

        $sums = 'sum(quantity) as units, sum(coalesce(unit_cost_santim, 0) * quantity) as cost, min(case when unit_cost_santim is null then 0 else 1 end) as costed';
        $stock = 'case when quote_id is null then product_id end';
        foreach (OrderItem::whereIn('order_id', $orderIds)
            ->selectRaw("{$stock} as pid, case when quote_id is null then variant_id end as vid, sum(line_total_santim) as money, {$sums}")
            ->groupByRaw("{$stock}, case when quote_id is null then variant_id end")->get() as $l) {
            $add($l->pid, $l->vid, 'marketplace', (int) $l->units, (int) $l->money, (int) $l->cost, (bool) $l->costed);
        }
        foreach (OfflineSaleItem::whereIn('offline_sale_id', $saleIds)
            ->selectRaw("product_id as pid, variant_id as vid, sum(net_santim) as money, {$sums}")->groupBy('product_id', 'variant_id')->get() as $l) {
            $add($l->pid, $l->vid, 'offline', (int) $l->units, (int) $l->money, (int) $l->cost, (bool) $l->costed);
        }
        // Restocked units count against the cost again; written-off ones stay sold.
        foreach (SaleReturnItem::whereIn('sale_return_id', $returnIds)
            ->selectRaw('product_id as pid, variant_id as vid, sum(quantity) as units, sum(credit_santim) as money,
                sum(case when restocked = 1 then coalesce(unit_cost_santim, 0) * quantity else 0 end) as cost')
            ->groupBy('product_id', 'variant_id')->get() as $l) {
            $add($l->pid, $l->vid, 'returned', (int) $l->units, (int) $l->money, (int) $l->cost, true);
        }

        // Names as the listings are called now; deleted listings and removed options keep theirs.
        $names = Product::withTrashed()->whereIn('id', array_filter(array_column($rows, 'product_id')))->pluck('name', 'id');
        $labels = ProductVariant::withTrashed()->whereIn('id', array_filter(array_column($rows, 'variant_id')))->get()
            ->mapWithKeys(fn (ProductVariant $v) => [$v->id => $v->label() ?: null]);
        foreach ($rows as &$row) {
            $row['name'] = $row['product_id'] ? ($names[$row['product_id']] ?? 'Deleted listing') : null;
            $row['label'] = $row['variant_id'] ? ($labels[$row['variant_id']] ?? null) : null;
            $row['net_santim'] = $row['revenue_santim'] - $row['returned_santim'];
        }
        unset($row);

        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => ($a['product_id'] === null) <=> ($b['product_id'] === null) ?: $b['units'] <=> $a['units'] ?: $b['net_santim'] <=> $a['net_santim']);

        return $limit === null ? $rows : array_slice($rows, 0, $limit);
    }
}
