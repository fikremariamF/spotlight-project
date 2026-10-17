<?php

namespace App\Support\Erp;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\StockType;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\InventoryService;

/**
 * The store's reports, each a table of rows worked out from its records, never stored.
 *
 * Column types: text, enum (a code; `labels` names the list the page turns it into words with),
 * int, money (santim; null when it cannot be worked out, such as profit without a cost price),
 * percent and date. Columns with `sum` get a total over every row, not just one page.
 */
final class Reports
{
    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        $money = fn (string $key, string $label, bool $sum = true) => ['key' => $key, 'label' => $label, 'type' => 'money', 'sum' => $sum];
        $int = fn (string $key, string $label, bool $sum = true) => ['key' => $key, 'label' => $label, 'type' => 'int', 'sum' => $sum];
        $text = fn (string $key, string $label) => ['key' => $key, 'label' => $label, 'type' => 'text'];

        return [
            'sales' => [
                'title' => 'Sales by day, week or month', 'group' => true, 'period' => true, 'sort' => 'period', 'dir' => 'asc',
                'about' => 'Marketplace orders count on the day they were delivered, shop sales on their sale date, returns on the day the goods came back.',
                'columns' => [
                    $text('period', 'Period'),
                    $int('marketplace_orders', 'Orders'), $money('marketplace_santim', 'Marketplace'),
                    $int('offline_sales', 'Shop sales'), $money('offline_santim', 'Shop'),
                    $money('returns_santim', 'Returns'), $money('net_sales_santim', 'Net sales'),
                ],
            ],
            'products' => [
                'title' => 'Product performance', 'period' => true, 'sort' => 'units', 'dir' => 'desc',
                'about' => 'Units and money per item. Profit is empty where a cost price is missing.',
                'columns' => [
                    $text('name', 'Item'), $text('option', 'Option'),
                    $int('units', 'Sold'), $int('marketplace_units', 'Marketplace'), $int('offline_units', 'Shop'), $int('returned_units', 'Returned'),
                    $money('revenue_santim', 'Sales'), $money('returned_santim', 'Returns'), $money('net_santim', 'Net'),
                    $money('cost_santim', 'Cost'), $money('gross_profit_santim', 'Gross profit'),
                ],
            ],
            'valuation' => [
                'title' => 'Inventory valuation', 'period' => false, 'sort' => 'value_cost_santim', 'dir' => 'desc',
                'about' => 'Units on hand now (available plus held for open orders) at cost price and at selling price. Hidden listings that still hold units are included.',
                'columns' => [
                    $text('name', 'Item'), $text('option', 'Option'), $text('sku', 'SKU'), ['key' => 'status', 'label' => 'Listing', 'type' => 'enum', 'labels' => 'listing_status'],
                    $int('on_hand', 'On hand'), $int('held', 'Held'),
                    $money('cost_price_santim', 'Cost price', false), $money('value_cost_santim', 'Value at cost'),
                    $money('price_santim', 'Price', false), $money('value_price_santim', 'Value at price'),
                ],
            ],
            'low_stock' => [
                'title' => 'Low and out of stock', 'period' => false, 'sort' => 'available', 'dir' => 'asc',
                'about' => 'Items kept in stock that are at or below their low-stock level now.',
                'columns' => [
                    $text('name', 'Item'), $text('option', 'Option'), $text('sku', 'SKU'), ['key' => 'state', 'label' => 'State', 'type' => 'enum', 'labels' => 'stock_state'],
                    $int('available', 'Available', false), $int('held', 'Held', false), $int('low_level', 'Low level', false), $int('reorder_quantity', 'Reorder qty', false),
                    $text('supplier', 'Supplier'),
                ],
            ],
            'movements' => [
                'title' => 'Stock movements', 'period' => true, 'sort' => 'entries', 'dir' => 'desc',
                'about' => 'Every stock change in the period, by kind. Download every line from Stock history.',
                'columns' => [
                    ['key' => 'type', 'label' => 'Kind', 'type' => 'enum', 'labels' => 'movement'],
                    $int('entries', 'Entries'), $int('units_in', 'Units in'), $int('units_out', 'Units out'),
                ],
            ],
            'purchases' => [
                'title' => 'Purchase history', 'period' => true, 'sort' => 'order_date', 'dir' => 'desc',
                'about' => 'Orders sent to suppliers, by order date. Drafts and cancelled orders are left out.',
                'columns' => [
                    $text('number', 'Order'), $text('supplier', 'Supplier'), ['key' => 'status', 'label' => 'Status', 'type' => 'enum', 'labels' => 'po_status'],
                    ['key' => 'order_date', 'label' => 'Ordered', 'type' => 'date'],
                    $money('total_santim', 'Total'), $money('received_value_santim', 'Received'), $money('invoiced_santim', 'Invoiced'),
                    $money('paid_santim', 'Paid'), $money('balance_santim', 'Still owed'),
                ],
            ],
            'suppliers' => [
                'title' => 'Supplier balances', 'period' => false, 'sort' => 'owed_santim', 'dir' => 'desc',
                'about' => 'What each supplier billed (or the goods received, until an invoice is recorded), what was paid and what is owed now. Below zero: paid ahead.',
                'columns' => [
                    $text('name', 'Supplier'), $money('bought_santim', 'Billed'), $money('received_not_invoiced_santim', 'Received, no invoice yet'),
                    $money('paid_santim', 'Paid'), $money('owed_santim', 'Owed'),
                ],
            ],
            'expenses' => [
                'title' => 'Expenses by category', 'period' => true, 'sort' => 'total_santim', 'dir' => 'desc',
                'about' => 'Recorded expenses by their date. Voided ones are left out. Stock bought from suppliers is under purchases, not here.',
                'columns' => [
                    $text('category', 'Category'), $int('count', 'Expenses'), $money('total_santim', 'Total'),
                    ['key' => 'share', 'label' => 'Share', 'type' => 'percent'],
                ],
            ],
            'profit' => [
                'title' => 'Gross and net profit', 'group' => true, 'period' => true, 'sort' => 'period', 'dir' => 'asc',
                'about' => 'An estimate from cost prices and recorded expenses, not accounts. Sales of items without a cost price are left out of profit.',
                'columns' => [
                    $text('period', 'Period'), $money('net_sales_santim', 'Net sales'), $money('cost_of_goods_santim', 'Cost of goods'),
                    $money('gross_profit_santim', 'Gross profit'), $money('delivery_santim', 'Delivery'), $money('commission_santim', 'Commission'),
                    $money('adjustments_santim', 'Marketplace adjustments'), $money('expenses_santim', 'Expenses'), $money('net_profit_santim', 'Net profit'),
                    $money('sales_without_cost_santim', 'Sales without cost'),
                ],
            ],
            'returns' => [
                'title' => 'Returns, cancellations and refunds', 'period' => true, 'sort' => 'date', 'dir' => 'desc',
                'about' => 'Goods brought back, marketplace orders cancelled, and the money given back. The marketplace refunds online payments; the store only for its own sales and cash orders.',
                'columns' => [
                    ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'kind', 'label' => 'Kind', 'type' => 'enum', 'labels' => 'return_kind'],
                    $text('document', 'Document'), $text('for', 'For'), $text('customer', 'Customer'),
                    $money('amount_santim', 'Value'), $money('money_back_santim', 'Money given back'),
                    ['key' => 'refund_by', 'label' => 'Given back by', 'type' => 'enum', 'labels' => 'refund_by'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'enum', 'labels' => 'return_status'], $text('reason', 'Reason'),
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function rows(string $report, int $storeId, Period $period, string $group): array
    {
        return match ($report) {
            'sales' => array_map(fn ($b) => [
                'period' => self::label($b, $group),
                'marketplace_orders' => $b['marketplace_orders'],
                'marketplace_santim' => $b['marketplace_items_santim'] + $b['marketplace_delivery_santim'],
                'offline_sales' => $b['offline_sales'],
                'offline_santim' => $b['offline_gross_santim'] - $b['offline_line_discounts_santim'] - $b['offline_sale_discounts_santim'] + $b['offline_delivery_santim'],
                'returns_santim' => $b['returns_goods_santim'] + $b['returns_delivery_santim'],
                'net_sales_santim' => $b['net_sales_santim'],
            ], Finance::for($storeId, $period, $group)['buckets']),
            'profit' => array_map(fn ($b) => ['period' => self::label($b, $group)] + array_intersect_key($b, array_flip([
                'net_sales_santim', 'cost_of_goods_santim', 'gross_profit_santim', 'delivery_santim', 'commission_santim', 'adjustments_santim',
                'expenses_santim', 'net_profit_santim', 'sales_without_cost_santim',
            ])), Finance::for($storeId, $period, $group)['buckets']),
            'products' => self::products($storeId, $period),
            'valuation' => self::valuation($storeId),
            'low_stock' => self::lowStock($storeId),
            'movements' => self::movements($storeId, $period),
            'purchases' => self::purchases($storeId, $period),
            'suppliers' => self::suppliers($storeId),
            'expenses' => self::expenses($storeId, $period),
            'returns' => self::returns($storeId, $period),
        };
    }

    /** "2026-10-17", "2026-10-12 to 2026-10-18" or "2026-10": sorts in date order as text. */
    private static function label(array $bucket, string $group): string
    {
        return match ($group) {
            'day' => $bucket['from'],
            'week' => $bucket['from'] === $bucket['to'] ? $bucket['from'] : "{$bucket['from']} to {$bucket['to']}",
            default => $bucket['key'],
        };
    }

    /** @return list<array<string, mixed>> */
    private static function products(int $storeId, Period $period): array
    {
        return array_map(fn ($p) => [
            'name' => $p['product_id'] ? $p['name'] : 'Custom and free-text lines',
            'option' => $p['label'],
            'units' => $p['units'], 'marketplace_units' => $p['marketplace_units'], 'offline_units' => $p['offline_units'], 'returned_units' => $p['returned_units'],
            'revenue_santim' => $p['revenue_santim'], 'returned_santim' => $p['returned_santim'], 'net_santim' => $p['net_santim'],
            'cost_santim' => $p['costed'] ? $p['cost_santim'] : null,
            'gross_profit_santim' => $p['costed'] ? $p['net_santim'] - $p['cost_santim'] : null,
        ], SalesReport::for($storeId, $period, productLimit: null)['products']);
    }

    /** Every stock item of every listing the store still has, with units on hand. */
    private static function stockItems(int $storeId, bool $withArchived): array
    {
        $products = Product::where('seller_profile_id', $storeId)->where('stock_type', StockType::InStock->value)
            ->when(! $withArchived, fn ($q) => $q->where('status', '!=', ProductStatus::Archived->value))
            ->with(['variants', 'supplier:id,name'])->orderBy('name')->get();
        $figures = new StockFigures($products, app(InventoryService::class));
        $items = [];
        foreach ($products as $p) {
            foreach ($figures->items($p) as $it) {
                $items[] = $it + ['name' => $p->name, 'status' => $p->status->value, 'supplier' => $p->supplier?->name];
            }
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private static function valuation(int $storeId): array
    {
        $rows = [];
        foreach (self::stockItems($storeId, withArchived: true) as $it) {
            $onHand = $it['available'] + $it['held'];
            if ($onHand <= 0) {
                continue;
            }
            $rows[] = [
                'name' => $it['name'], 'option' => $it['label'], 'sku' => $it['sku'], 'status' => $it['status'],
                'on_hand' => $onHand, 'held' => $it['held'],
                'cost_price_santim' => $it['cost_price_santim'], 'value_cost_santim' => $it['value_cost_santim'],
                'price_santim' => $it['price_santim'], 'value_price_santim' => $it['value_price_santim'],
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function lowStock(int $storeId): array
    {
        $rows = [];
        foreach (self::stockItems($storeId, withArchived: false) as $it) {
            if (! in_array($it['state'], ['low', 'out'], true)) {
                continue;
            }
            $rows[] = [
                'name' => $it['name'], 'option' => $it['label'], 'sku' => $it['sku'], 'state' => $it['state'],
                'available' => $it['available'], 'held' => $it['held'], 'low_level' => $it['low_level'], 'reorder_quantity' => $it['reorder_quantity'],
                'supplier' => $it['supplier'],
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function movements(int $storeId, Period $period): array
    {
        return StockMovement::where('seller_profile_id', $storeId)->whereBetween('created_at', $period->bounds())
            ->groupBy('type')
            ->selectRaw('type, count(*) as entries, sum(case when quantity > 0 then quantity else 0 end) as units_in, sum(case when quantity < 0 then -quantity else 0 end) as units_out')
            ->get()->map(fn ($r) => ['type' => $r->type, 'entries' => (int) $r->entries, 'units_in' => (int) $r->units_in, 'units_out' => (int) $r->units_out])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private static function purchases(int $storeId, Period $period): array
    {
        $orders = PurchaseOrder::where('seller_profile_id', $storeId)->whereNotIn('status', ['draft', 'cancelled'])
            ->whereDate('order_date', '>=', $period->from->toDateString())->whereDate('order_date', '<=', $period->to->toDateString())
            ->with('supplier:id,name')->get();
        $balances = SupplierBalances::forStore($storeId, $orders->pluck('supplier_id')->unique()->values()->all());

        return $orders->map(function (PurchaseOrder $po) use ($balances) {
            $f = $balances->order($po->id);

            return [
                'number' => $po->number, 'supplier' => $po->supplier?->name, 'status' => $po->status->value, 'order_date' => $po->order_date->toDateString(),
                'total_santim' => $po->total_santim, 'received_value_santim' => $f['received_value_santim'], 'invoiced_santim' => $f['invoiced_santim'],
                'paid_santim' => $f['paid_santim'], 'balance_santim' => $f['balance_santim'],
            ];
        })->all();
    }

    /** Suppliers with something bought or paid, and active ones with nothing yet. */
    private static function suppliers(int $storeId): array
    {
        $balances = SupplierBalances::forStore($storeId);
        $rows = [];
        foreach (Supplier::where('seller_profile_id', $storeId)->orderBy('name')->get(['id', 'name', 'is_active']) as $s) {
            $b = $balances->supplier($s->id);
            if (! $s->is_active && $b['bought_santim'] === 0 && $b['paid_santim'] === 0) {
                continue;
            }
            $rows[] = ['name' => $s->name, 'bought_santim' => $b['bought_santim'], 'received_not_invoiced_santim' => $b['received_not_invoiced_santim'],
                'paid_santim' => $b['paid_santim'], 'owed_santim' => $b['owed_santim']];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function expenses(int $storeId, Period $period): array
    {
        $cats = Expense::where('seller_profile_id', $storeId)->where('status', 'recorded')
            ->whereDate('spent_on', '>=', $period->from->toDateString())->whereDate('spent_on', '<=', $period->to->toDateString())
            ->groupBy('expense_category_id')->selectRaw('expense_category_id, count(*) as n, sum(amount_santim) as total')->get();
        $names = ExpenseCategory::whereIn('id', $cats->pluck('expense_category_id'))->pluck('name', 'id');
        $total = (int) $cats->sum('total');

        return $cats->map(fn ($c) => [
            'category' => $names[$c->expense_category_id] ?? 'Other', 'count' => (int) $c->n, 'total_santim' => (int) $c->total,
            'share' => $total > 0 ? round($c->total * 100 / $total, 1) : 0,
        ])->all();
    }

    /**
     * Returns recorded in the period, and marketplace orders cancelled in it with any refund the
     * marketplace owes or made for them. The store sees the customer's account name, never their
     * phone or address.
     *
     * @return list<array<string, mixed>>
     */
    private static function returns(int $storeId, Period $period): array
    {
        $rows = [];
        SaleReturn::where('seller_profile_id', $storeId)->where('status', 'recorded')
            ->whereDate('returned_on', '>=', $period->from->toDateString())->whereDate('returned_on', '<=', $period->to->toDateString())
            ->with(['sale:id,number,customer_name', 'order:id,number,customer_id', 'order.customer:id,name'])
            ->get()->each(function (SaleReturn $r) use (&$rows) {
                $rows[] = [
                    'date' => $r->returned_on->toDateString(), 'kind' => $r->order_id ? 'marketplace_return' : 'shop_return',
                    'document' => $r->number, 'for' => $r->sale?->number ?? $r->order?->number,
                    'customer' => $r->sale?->customer_name ?? $r->order?->customer?->name,
                    'amount_santim' => $r->goods_credit_santim + $r->delivery_credit_santim, 'money_back_santim' => $r->refund_santim,
                    'refund_by' => $r->refund_santim > 0 ? 'store' : null, 'status' => 'recorded', 'reason' => $r->reason,
                ];
            });

        Order::where('seller_profile_id', $storeId)->visibleToSeller()->where('status', OrderStatus::Cancelled->value)
            ->whereBetween('cancelled_at', $period->bounds())
            ->with(['customer:id,name', 'refund:id,order_id,amount_santim,status'])
            ->get(['id', 'number', 'customer_id', 'total_santim', 'cancelled_at', 'cancelled_by', 'cancel_reason'])
            ->each(function (Order $o) use (&$rows, $period) {
                $rows[] = [
                    'date' => $period->dayOf($o->cancelled_at), 'kind' => 'cancelled_order', 'document' => $o->number, 'for' => null,
                    'customer' => $o->customer?->name, 'amount_santim' => $o->total_santim,
                    'money_back_santim' => $o->refund ? $o->refund->amount_santim : 0,
                    'refund_by' => $o->refund ? 'marketplace' : null,
                    'status' => $o->refund ? 'refund_'.$o->refund->status : 'cancelled_by_'.$o->cancelled_by,
                    'reason' => $o->cancel_reason,
                ];
            });

        return $rows;
    }
}
