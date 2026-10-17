<?php

namespace App\Support\Erp;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\Seller\BalanceController;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\OfflineSale;
use App\Models\OfflineSaleItem;
use App\Models\OfflineSalePayment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\SellerLedgerEntry;
use App\Models\SupplierPayment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A store's money for a period, worked out from its records, for each day, week or month and in
 * total. Nothing here is stored: every figure can be traced to orders, shop sales, returns,
 * expenses, supplier payments and the marketplace ledger.
 *
 * Sales: marketplace orders count once, on the day they were delivered, at what the customer paid
 * (items and delivery). Shop sales count on their sale date; returns on the day the goods came back.
 * Profit is an estimate: item sales minus the cost prices frozen on each line, so lines without a
 * cost are left out of it (and reported) instead of being counted as pure profit. Commission is
 * what the marketplace charges on items of delivered orders. This is operational reporting, not
 * double-entry accounting.
 *
 * "Money in and out" lists what was recorded as received or paid in the period. It is not a bank
 * or cash balance: online sales reach the store only as payouts the marketplace records.
 */
final class Finance
{
    public const GROUPS = ['day', 'week', 'month'];

    /** Fields added up per bucket; the derived ones are worked out from these. */
    private const FIELDS = [
        'marketplace_orders', 'marketplace_items_santim', 'marketplace_delivery_santim', 'commission_santim',
        'offline_sales', 'offline_gross_santim', 'offline_line_discounts_santim', 'offline_sale_discounts_santim', 'offline_delivery_santim',
        'returns', 'returns_goods_santim', 'returns_delivery_santim',
        'costed_sales_santim', 'cost_of_sales_santim', 'sales_without_cost_santim', 'lines_without_cost',
        'returned_costed_santim', 'cost_back_santim',
        'expenses_santim', 'adjustments_santim',
        'in_cash_on_delivery_santim', 'in_shop_payments_santim', 'in_payouts_santim',
        'out_refunds_santim', 'out_suppliers_santim', 'out_expenses_santim', 'out_marketplace_santim',
    ];

    /** @return array<string, mixed> */
    public static function for(int $storeId, Period $period, string $group = 'month'): array
    {
        [$start, $end] = $period->bounds();
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();

        $buckets = [];
        foreach ($period->days() as $day) {
            $key = self::bucket($day, $group);
            $buckets[$key] ??= ['key' => $key, 'from' => $day] + array_fill_keys(self::FIELDS, 0);
            $buckets[$key]['to'] = $day;
        }
        $add = function (string $day, array $values) use (&$buckets, $group) {
            $key = self::bucket($day, $group);
            if (! isset($buckets[$key])) {
                return;
            }
            foreach ($values as $field => $amount) {
                $buckets[$key][$field] += (int) $amount;
            }
        };

        // Marketplace orders, on their delivery day.
        $orders = Order::where('seller_profile_id', $storeId)->where('status', OrderStatus::Delivered->value)->whereBetween('delivered_at', [$start, $end]);
        $orderLines = OrderItem::whereIn('order_id', (clone $orders)->select('id'))->groupBy('order_id')
            ->selectRaw(self::costSums('line_total_santim').', order_id')->get()->keyBy('order_id');
        foreach ($orders->get(['id', 'delivered_at', 'items_total_santim', 'delivery_fee_santim', 'commission_santim', 'total_santim', 'payment_method']) as $o) {
            $l = $orderLines[$o->id] ?? null;
            $add($period->dayOf($o->delivered_at), [
                'marketplace_orders' => 1, 'marketplace_items_santim' => $o->items_total_santim, 'marketplace_delivery_santim' => (int) $o->delivery_fee_santim,
                'commission_santim' => (int) $o->commission_santim,
                'costed_sales_santim' => $l?->costed, 'cost_of_sales_santim' => $l?->cost, 'sales_without_cost_santim' => $l?->uncosted, 'lines_without_cost' => $l?->uncosted_lines,
                // Cash on delivery is in the store's hands once delivered; online money comes as payouts.
                'in_cash_on_delivery_santim' => $o->payment_method === 'cod' ? $o->total_santim : 0,
            ]);
        }

        // Shop sales, on their sale date. Dates compared as dates: SQLite keeps a time part.
        $sales = OfflineSale::where('seller_profile_id', $storeId)->where('status', 'completed')
            ->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to);
        $saleLines = OfflineSaleItem::whereIn('offline_sale_id', (clone $sales)->select('id'))->groupBy('offline_sale_id')
            ->selectRaw(self::costSums('net_santim').', offline_sale_id, sum(quantity * unit_price_santim) as gross, sum(discount_santim) as line_discounts')->get()->keyBy('offline_sale_id');
        foreach ($sales->get(['id', 'sale_date', 'discount_santim', 'delivery_santim']) as $s) {
            $l = $saleLines[$s->id] ?? null;
            $add($s->sale_date->toDateString(), [
                'offline_sales' => 1, 'offline_gross_santim' => $l?->gross, 'offline_line_discounts_santim' => $l?->line_discounts,
                'offline_sale_discounts_santim' => $s->discount_santim, 'offline_delivery_santim' => $s->delivery_santim,
                'costed_sales_santim' => $l?->costed, 'cost_of_sales_santim' => $l?->cost, 'sales_without_cost_santim' => $l?->uncosted, 'lines_without_cost' => $l?->uncosted_lines,
            ]);
        }

        // Returns, on the day the goods came back. Restocked units are stock again, so their cost
        // stops counting as sold; written-off units stay a cost.
        $returns = SaleReturn::where('seller_profile_id', $storeId)->where('status', 'recorded')
            ->whereDate('returned_on', '>=', $from)->whereDate('returned_on', '<=', $to);
        $returnLines = SaleReturnItem::whereIn('sale_return_id', (clone $returns)->select('id'))->groupBy('sale_return_id')
            ->selectRaw('sale_return_id, sum(case when unit_cost_santim is not null then credit_santim else 0 end) as costed,
                sum(case when unit_cost_santim is not null and restocked = 1 then unit_cost_santim * quantity else 0 end) as cost_back')->get()->keyBy('sale_return_id');
        foreach ($returns->get(['id', 'returned_on', 'goods_credit_santim', 'delivery_credit_santim', 'refund_santim']) as $r) {
            $l = $returnLines[$r->id] ?? null;
            $add($r->returned_on->toDateString(), [
                'returns' => 1, 'returns_goods_santim' => $r->goods_credit_santim, 'returns_delivery_santim' => $r->delivery_credit_santim,
                'returned_costed_santim' => $l?->costed, 'cost_back_santim' => $l?->cost_back, 'out_refunds_santim' => $r->refund_santim,
            ]);
        }

        $expenses = Expense::where('seller_profile_id', $storeId)->where('status', 'recorded')
            ->whereDate('spent_on', '>=', $from)->whereDate('spent_on', '<=', $to)->get(['spent_on', 'amount_santim', 'expense_category_id']);
        foreach ($expenses as $e) {
            $add($e->spent_on->toDateString(), ['expenses_santim' => $e->amount_santim, 'out_expenses_santim' => $e->amount_santim]);
        }

        foreach (OfflineSalePayment::where('seller_profile_id', $storeId)->where('status', 'recorded')
            ->whereDate('paid_on', '>=', $from)->whereDate('paid_on', '<=', $to)->get(['paid_on', 'amount_santim']) as $p) {
            $add($p->paid_on->toDateString(), ['in_shop_payments_santim' => $p->amount_santim]);
        }
        foreach (SupplierPayment::where('seller_profile_id', $storeId)->where('status', 'recorded')
            ->whereDate('paid_on', '>=', $from)->whereDate('paid_on', '<=', $to)->get(['paid_on', 'amount_santim']) as $p) {
            $add($p->paid_on->toDateString(), ['out_suppliers_santim' => $p->amount_santim]);
        }

        // The marketplace ledger: positive amounts are what the store owes the marketplace.
        $ledger = SellerLedgerEntry::where('seller_profile_id', $storeId)->whereBetween('created_at', [$start, $end])->get(['type', 'amount_santim', 'created_at']);
        foreach ($ledger as $entry) {
            $add($period->dayOf($entry->created_at), match ($entry->type) {
                'adjustment' => ['adjustments_santim' => $entry->amount_santim],
                'payout' => ['in_payouts_santim' => $entry->amount_santim],
                'payment' => ['out_marketplace_santim' => -$entry->amount_santim],
                default => [],
            });
        }

        $rows = array_map(self::derive(...), array_values($buckets));
        $total = ['key' => 'total', 'from' => $from, 'to' => $to] + array_fill_keys(self::FIELDS, 0);
        foreach ($buckets as $b) {
            foreach (self::FIELDS as $field) {
                $total[$field] += $b[$field];
            }
        }

        $byCategory = $expenses->groupBy('expense_category_id')->map(fn ($g) => ['total_santim' => (int) $g->sum('amount_santim'), 'count' => $g->count()]);
        $names = ExpenseCategory::whereIn('id', $byCategory->keys())->pluck('name', 'id');

        return [
            'range' => $period->toArray(),
            'group' => $group,
            'total' => self::derive($total),
            'buckets' => $rows,
            'expenses_by_category' => $byCategory->map(fn ($c, $id) => ['id' => (int) $id, 'name' => $names[$id] ?? 'Other'] + $c)
                ->sortByDesc('total_santim')->values()->all(),
            'marketplace' => self::marketplace($storeId, $ledger),
            'owed' => self::owed($storeId),
        ];
    }

    /** The bucket a local day falls in: the day, the Monday of its week, or its month. */
    public static function bucket(string $day, string $group): string
    {
        return match ($group) {
            'day' => $day,
            'week' => CarbonImmutable::createFromFormat('!Y-m-d', $day)->startOfWeek(CarbonInterface::MONDAY)->toDateString(),
            default => substr($day, 0, 7),
        };
    }

    /** Sales of lines with a cost, their cost, and sales of lines without one, per group. */
    private static function costSums(string $amount): string
    {
        return "sum(case when unit_cost_santim is not null then {$amount} else 0 end) as costed,
            sum(case when unit_cost_santim is not null then unit_cost_santim * quantity else 0 end) as cost,
            sum(case when unit_cost_santim is null then {$amount} else 0 end) as uncosted,
            sum(case when unit_cost_santim is null then 1 else 0 end) as uncosted_lines";
    }

    /**
     * The statement for one bucket or the total, from its added-up fields.
     *
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private static function derive(array $b): array
    {
        $gross = $b['marketplace_items_santim'] + $b['offline_gross_santim'];
        $discounts = $b['offline_line_discounts_santim'] + $b['offline_sale_discounts_santim'];
        $netItems = $gross - $discounts - $b['returns_goods_santim'];
        $delivery = $b['marketplace_delivery_santim'] + $b['offline_delivery_santim'] - $b['returns_delivery_santim'];
        $costOfGoods = $b['cost_of_sales_santim'] - $b['cost_back_santim'];
        // Item sales with a cost price, less returns of such lines, minus what those goods cost.
        $grossProfit = $b['costed_sales_santim'] - $b['returned_costed_santim'] - $costOfGoods;
        $netProfit = $grossProfit + $delivery - $b['commission_santim'] - $b['adjustments_santim'] - $b['expenses_santim'];
        $in = $b['in_cash_on_delivery_santim'] + $b['in_shop_payments_santim'] + $b['in_payouts_santim'];
        $out = $b['out_refunds_santim'] + $b['out_suppliers_santim'] + $b['out_expenses_santim'] + $b['out_marketplace_santim'];

        return $b + [
            'sales' => $b['marketplace_orders'] + $b['offline_sales'],
            'gross_sales_santim' => $gross,
            'discounts_santim' => $discounts,
            'net_items_santim' => $netItems,
            'delivery_santim' => $delivery,
            'net_sales_santim' => $netItems + $delivery,
            // Item sales without a cost price, after their returns: left out of gross profit, so
            // net item sales - this - cost of goods = gross profit.
            'net_items_without_cost_santim' => $b['sales_without_cost_santim'] - ($b['returns_goods_santim'] - $b['returned_costed_santim']),
            'cost_of_goods_santim' => $costOfGoods,
            'gross_profit_santim' => $grossProfit,
            'net_profit_santim' => $netProfit,
            'money_in_santim' => $in,
            'money_out_santim' => $out,
            'money_net_santim' => $in - $out,
        ];
    }

    /**
     * The store's account with the marketplace: the balance now (positive: the store owes
     * commission; negative: the marketplace owes the store for online sales), what moved in the
     * period, and online money not owed yet because the order is not delivered.
     *
     * @return array<string, mixed>
     */
    private static function marketplace(int $storeId, $ledger): array
    {
        $sum = fn (string $type) => (int) $ledger->where('type', $type)->sum('amount_santim');

        return [
            'now' => BalanceController::summary($storeId),
            'period' => [
                'commission_charged_santim' => $sum('commission'),
                'online_sales_credited_santim' => -$sum('sale'),
                'payouts_received_santim' => $sum('payout'),
                'payments_made_santim' => -$sum('payment'),
                'adjustments_santim' => $sum('adjustment'),
            ],
            'online_waiting_santim' => (int) Order::where('seller_profile_id', $storeId)->where('payment_method', 'online')->where('payment_status', 'paid')
                ->whereNotIn('status', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value])->sum('paid_santim'),
            'online_waiting_orders' => Order::where('seller_profile_id', $storeId)->where('payment_method', 'online')->where('payment_status', 'paid')
                ->whereNotIn('status', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value])->count(),
        ];
    }

    /** @return array<string, mixed> */
    private static function owed(int $storeId): array
    {
        $suppliers = SupplierBalances::forStore($storeId);

        return [
            'customers' => SaleFigures::customers($storeId),
            'suppliers' => [
                'owed_santim' => $suppliers->totalOwed(),
                'paid_ahead_santim' => $suppliers->totalPaidAhead(),
                'received_not_invoiced_santim' => $suppliers->totalReceivedNotInvoiced(),
            ],
        ];
    }
}
