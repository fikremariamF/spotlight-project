<?php

namespace App\Support\Erp;

use App\Models\OfflineSale;
use App\Models\OfflineSalePayment;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use Carbon\CarbonImmutable;

/**
 * Money and returns of offline sales, in a few queries for any number of sales.
 *
 * What the customer owes = total - returns credited - (paid - given back). Below zero means the
 * store holds money for the customer (a return credited more than was refunded). A voided sale
 * owes nothing.
 */
final class SaleFigures
{
    /**
     * @param  list<int>  $saleIds
     * @return array<int, array{paid_santim: int, credited_santim: int, refunded_santim: int, delivery_credited_santim: int, returns: int}>
     */
    public static function money(array $saleIds): array
    {
        $out = array_fill_keys($saleIds, ['paid_santim' => 0, 'credited_santim' => 0, 'refunded_santim' => 0, 'delivery_credited_santim' => 0, 'returns' => 0]);
        if (! $saleIds) {
            return $out;
        }
        foreach (OfflineSalePayment::whereIn('offline_sale_id', $saleIds)->where('status', 'recorded')
            ->selectRaw('offline_sale_id, sum(amount_santim) as paid')->groupBy('offline_sale_id')->get() as $r) {
            $out[$r->offline_sale_id]['paid_santim'] = (int) $r->paid;
        }
        foreach (SaleReturn::whereIn('offline_sale_id', $saleIds)->where('status', 'recorded')
            ->selectRaw('offline_sale_id, sum(goods_credit_santim + delivery_credit_santim) as credit, sum(delivery_credit_santim) as delivery, sum(refund_santim) as refund, count(*) as n')
            ->groupBy('offline_sale_id')->get() as $r) {
            $out[$r->offline_sale_id]['credited_santim'] = (int) $r->credit;
            $out[$r->offline_sale_id]['delivery_credited_santim'] = (int) $r->delivery;
            $out[$r->offline_sale_id]['refunded_santim'] = (int) $r->refund;
            $out[$r->offline_sale_id]['returns'] = (int) $r->n;
        }

        return $out;
    }

    /** @return array{paid_santim: int, credited_santim: int, refunded_santim: int, delivery_credited_santim: int, returns: int, balance_santim: int} */
    public static function of(OfflineSale $sale, ?array $money = null): array
    {
        $m = $money ?? self::money([$sale->id])[$sale->id];

        return $m + ['balance_santim' => self::balance($sale, $m)];
    }

    public static function balance(OfflineSale $sale, array $m): int
    {
        return $sale->isVoided() ? 0 : $sale->total_santim - $m['credited_santim'] - ($m['paid_santim'] - $m['refunded_santim']);
    }

    /**
     * What customers owe the store on its offline sales as of now, how many of those sales are past
     * their pay-by date, and the credit the store holds for customers (returns credited beyond what
     * was given back).
     *
     * @return array{owe_santim: int, owing_sales: int, overdue_sales: int, credit_santim: int}
     */
    public static function customers(int $storeId): array
    {
        $open = OfflineSale::where('seller_profile_id', $storeId)->where('status', 'completed')->get(['id', 'status', 'total_santim', 'due_date']);
        $money = self::money($open->pluck('id')->all());
        $today = CarbonImmutable::now(config('marketplace.timezone'))->toDateString();
        $owing = $open->filter(fn ($s) => self::balance($s, $money[$s->id]) > 0);

        return [
            'owe_santim' => (int) $owing->sum(fn ($s) => self::balance($s, $money[$s->id])),
            'owing_sales' => $owing->count(),
            'overdue_sales' => $owing->filter(fn ($s) => $s->due_date && $s->due_date->toDateString() < $today)->count(),
            'credit_santim' => (int) -$open->sum(fn ($s) => min(0, self::balance($s, $money[$s->id]))),
        ];
    }

    /**
     * Units already brought back per sale line (offline_sale_item_id) or order line (order_item_id),
     * counting only returns that were not voided.
     *
     * @param  'offline_sale_item_id'|'order_item_id'  $column
     * @param  list<int>  $lineIds
     * @return array<int, int>
     */
    public static function returned(string $column, array $lineIds): array
    {
        if (! $lineIds) {
            return [];
        }

        return SaleReturnItem::whereIn($column, $lineIds)
            ->whereHas('saleReturn', fn ($q) => $q->where('status', 'recorded'))
            ->selectRaw("{$column} as line, sum(quantity) as n")->groupBy($column)->pluck('n', 'line')
            ->map(fn ($n) => (int) $n)->all();
    }
}
