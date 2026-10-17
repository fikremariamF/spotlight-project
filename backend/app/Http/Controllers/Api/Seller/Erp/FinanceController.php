<?php

namespace App\Http\Controllers\Api\Seller\Erp;

use App\Http\Controllers\Api\Seller\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\Erp\Finance;
use App\Support\Erp\Period;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance & profit: the store's sales, costs and estimated profit for a period, what it is owed
 * and owes now, and its account with the marketplace (see Finance).
 */
class FinanceController extends Controller
{
    use ResolvesStore;

    /** Statement fields in the CSV, in order. */
    private const CSV = [
        'marketplace_orders', 'offline_sales', 'gross_sales_santim', 'discounts_santim', 'returns_goods_santim', 'net_items_santim',
        'delivery_santim', 'net_sales_santim', 'cost_of_goods_santim', 'gross_profit_santim', 'commission_santim', 'adjustments_santim',
        'expenses_santim', 'net_profit_santim', 'sales_without_cost_santim', 'lines_without_cost',
        'money_in_santim', 'money_out_santim',
    ];

    public function show(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $period = Period::fromRequest($request, 'month');

        return response()->json(['data' => Finance::for($store->id, $period, self::group($request, $period))]);
    }

    /** One row per day, week or month and a total row, for a spreadsheet. */
    public function export(Request $request, AuditLogger $audit): StreamedResponse
    {
        $store = $this->currentStore($request);
        $period = Period::fromRequest($request, 'month');
        $group = self::group($request, $period);
        $finance = Finance::for($store->id, $period, $group);
        $audit->log('erp.finance_exported', $store, $period->toArray() + ['group' => $group]);
        $head = array_map(fn ($f) => str_replace('_santim', '_etb', $f), self::CSV);

        return response()->streamDownload(function () use ($finance, $head) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['period', 'from', 'to', ...$head], escape: '');
            foreach ([...$finance['buckets'], $finance['total']] as $row) {
                fputcsv($out, [$row['key'], $row['from'], $row['to'], ...array_map(
                    fn ($f) => str_ends_with($f, '_santim') ? Money::toBirr($row[$f]) : $row[$f], self::CSV,
                )], escape: '');
            }
            fclose($out);
        }, 'finance-'.$period->from->toDateString().'-to-'.$period->to->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Day, week or month; by default days up to a month, weeks up to four months, then months. */
    public static function group(Request $request, Period $period): string
    {
        $group = $request->validate(['group' => ['nullable', Rule::in(Finance::GROUPS)]])['group'] ?? null;
        $days = count($period->days());

        return $group ?? ($days <= 31 ? 'day' : ($days <= 124 ? 'week' : 'month'));
    }
}
