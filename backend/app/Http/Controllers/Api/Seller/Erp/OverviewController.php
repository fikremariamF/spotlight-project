<?php

namespace App\Http\Controllers\Api\Seller\Erp;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Http\Controllers\Api\Seller\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceiptItem;
use App\Models\StockMovement;
use App\Services\InventoryService;
use App\Support\Erp\Finance;
use App\Support\Erp\Period;
use App\Support\Erp\SalesReport;
use App\Support\Erp\StockFigures;
use App\Support\Erp\SupplierBalances;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The ERP dashboard. Stock figures are as of now; sales figures cover the chosen period and
 * count marketplace orders once, on the day they were delivered, and offline sales on their sale
 * date. Profit here is an estimate from the cost prices the store entered, not accounting.
 */
class OverviewController extends Controller
{
    use ResolvesStore;

    public function __construct(private readonly InventoryService $inventory) {}

    public function __invoke(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $period = Period::fromRequest($request);

        return response()->json(['data' => [
            'range' => $period->toArray(),
            'stock' => $this->stock($store->id),
            'sales' => $this->sales($store->id, $period),
            'orders' => [
                'open' => Order::where('seller_profile_id', $store->id)->visibleToSeller()
                    ->whereNotIn('status', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value])->count(),
                'pending_confirmation' => Order::where('seller_profile_id', $store->id)->visibleToSeller()
                    ->where('status', OrderStatus::PendingConfirmation->value)->count(),
            ],
            'purchasing' => $this->purchasing($store->id, $period),
            'recent_movements' => StockMovement::where('seller_profile_id', $store->id)
                ->with(['product:id,name,slug', 'warehouse:id,name', 'variant:id,size,color,material', 'order:id,number', 'user:id,name'])
                ->latest('id')->limit(8)->get()->map(fn ($m) => InventoryController::movement($m, withProduct: true)),
            'activity' => AuditLog::where('user_id', $request->user()->id)
                ->where(fn ($q) => $q->where('action', 'like', 'inventory.%')->orWhere('action', 'like', 'erp.%')->orWhere('action', 'like', 'product.%'))
                ->latest('id')->limit(8)->get(['id', 'action', 'meta', 'created_at'])
                ->map(fn ($a) => ['id' => $a->id, 'action' => $a->action, 'created_at' => $a->created_at?->toIso8601String()]),
        ]]);
    }

    /**
     * Stock now. Hidden (archived) listings are left out of the counts and alerts, but units they
     * still hold are the store's goods, so they count in the stock value.
     *
     * @return array<string, mixed>
     */
    private function stock(int $storeId): array
    {
        $products = Product::where('seller_profile_id', $storeId)->with('variants')->get();
        $figures = new StockFigures($products, $this->inventory);
        $items = [];
        $hidden = [];
        foreach ($products as $p) {
            foreach ($figures->items($p) as $it) {
                $it += ['product_id' => $p->id, 'name' => $p->name];
                if ($p->status === ProductStatus::Archived) {
                    $hidden[] = $it;
                } else {
                    $items[] = $it;
                }
            }
        }
        $tracked = array_filter($items, fn ($i) => $i['tracked']);
        $hiddenTracked = array_filter($hidden, fn ($i) => $i['tracked'] && $i['available'] + $i['held'] > 0);
        $alerts = array_values(array_filter($tracked, fn ($i) => in_array($i['state'], ['low', 'out'], true)));
        usort($alerts, fn ($a, $b) => $a['available'] <=> $b['available'] ?: strcmp($a['name'], $b['name']));
        $valued = [...$tracked, ...$hiddenTracked];

        return [
            'listings' => $products->where('status', '!=', ProductStatus::Archived)->count(),
            'items' => count($items),
            'available' => array_sum(array_column($tracked, 'available')),
            'held' => array_sum(array_column($tracked, 'held')),
            'value_cost_santim' => array_sum(array_map(fn ($i) => (int) $i['value_cost_santim'], $valued)),
            'value_price_santim' => array_sum(array_column($valued, 'value_price_santim')),
            'missing_cost' => count(array_filter($valued, fn ($i) => $i['cost_price_santim'] === null)),
            'hidden_units' => array_sum(array_map(fn ($i) => $i['available'] + $i['held'], $hiddenTracked)),
            'low' => count(array_filter($tracked, fn ($i) => $i['state'] === 'low')),
            'out' => count(array_filter($tracked, fn ($i) => $i['state'] === 'out')),
            'alerts' => array_map(fn ($i) => array_intersect_key($i, array_flip(['product_id', 'variant_id', 'name', 'label', 'sku', 'available', 'held', 'low_level', 'reorder_quantity', 'state'])), array_slice($alerts, 0, 8)),
        ];
    }

    /**
     * Orders waiting for goods, money owed to suppliers (as of now) and goods received in the period.
     *
     * @return array<string, mixed>
     */
    private function purchasing(int $storeId, Period $period): array
    {
        $today = now(config('marketplace.timezone'))->toDateString();
        $open = PurchaseOrder::where('seller_profile_id', $storeId)->whereIn('status', ['ordered', 'partially_received']);
        $balances = SupplierBalances::forStore($storeId);
        $received = PurchaseReceiptItem::join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_receipt_items.purchase_receipt_id')
            ->where('purchase_receipts.seller_profile_id', $storeId)
            // Dates compared as dates: SQLite keeps them with a time part.
            ->whereDate('purchase_receipts.received_on', '>=', $period->from->toDateString())
            ->whereDate('purchase_receipts.received_on', '<=', $period->to->toDateString())
            ->selectRaw('sum(purchase_receipt_items.quantity * purchase_receipt_items.unit_cost_santim) as value, sum(purchase_receipt_items.quantity) as units')
            ->first();

        return [
            'open_orders' => (clone $open)->count(),
            'late_orders' => (clone $open)->whereDate('expected_date', '<', $today)->count(),
            'open_value_santim' => (int) (clone $open)->sum('total_santim'),
            'owed_santim' => $balances->totalOwed(),
            'received_not_invoiced_santim' => $balances->totalReceivedNotInvoiced(),
            'received_value_santim' => (int) $received->value,
            'received_units' => (int) $received->units,
            'recent' => PurchaseOrder::where('seller_profile_id', $storeId)->with('supplier:id,name')->latest('id')->limit(5)->get()
                ->map(fn ($po) => PurchaseController::summary($po, $balances->order($po->id))),
        ];
    }

    /**
     * Marketplace and offline sales for the period, less returns (see SalesReport).
     *
     * @return array<string, mixed>
     */
    private function sales(int $storeId, Period $period): array
    {
        $report = SalesReport::for($storeId, $period);
        $finance = Finance::for($storeId, $period, 'month')['total'];
        $top = array_values(array_filter($report['products'], fn ($p) => $p['product_id'] !== null && $p['units'] > 0));

        return [
            'marketplace' => $report['marketplace'],
            'offline' => $report['offline'],
            'returns' => $report['returns'],
            'totals' => $report['totals'],
            // The same figures as Finance & profit, so both pages agree.
            'finance' => array_intersect_key($finance, array_flip(['cost_of_goods_santim', 'gross_profit_santim', 'commission_santim', 'adjustments_santim', 'expenses_santim', 'net_profit_santim', 'sales_without_cost_santim', 'lines_without_cost'])),
            'days' => $report['days'],
            'top_products' => array_map(fn ($p) => [
                'product_id' => $p['product_id'], 'variant_id' => $p['variant_id'], 'name' => $p['name'], 'label' => $p['label'],
                'units' => $p['units'], 'revenue_santim' => $p['revenue_santim'],
            ], array_slice($top, 0, 5)),
        ];
    }
}
