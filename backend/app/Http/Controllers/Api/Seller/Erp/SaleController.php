<?php

namespace App\Http\Controllers\Api\Seller\Erp;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Seller\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\OfflineSale;
use App\Models\OfflineSaleItem;
use App\Models\OfflineSalePayment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\SellerProfile;
use App\Services\AuditLogger;
use App\Services\SalesService;
use App\Support\Erp\Period;
use App\Support\Erp\SaleFigures;
use App\Support\Erp\SalesReport;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The store's sales: delivered marketplace orders (read from the main order records, never copied)
 * and sales it made itself outside the marketplace, with invoices, payments and returns.
 *
 * A store sees a marketplace customer's account name and area here, as on its orders page, but
 * never their phone or street.
 */
class SaleController extends Controller
{
    use ResolvesStore;

    private const MONEY = 'regex:/^\d{1,9}(\.\d{1,2})?$/';

    private const MONEY_MESSAGE = 'Enter the amount in birr, for example 2500 or 2500.50.';

    /** Marketplace orders a store can print a receipt for: confirmed until delivered. */
    private const RECEIPT_STATUSES = [OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Ready, OrderStatus::Dispatched, OrderStatus::Delivered];

    public function __construct(private readonly SalesService $sales) {}

    /**
     * Sales in the period, newest first: delivered marketplace orders by their delivery day and
     * offline sales by their sale date. "Owing" lists offline sales with money still owed, of any date.
     */
    public function index(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $period = Period::fromRequest($request);
        $data = $request->validate([
            'source' => ['nullable', Rule::in(['all', 'marketplace', 'offline'])],
            'status' => ['nullable', Rule::in(['completed', 'voided', 'owing'])],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $source = $data['source'] ?? 'all';
        $status = $data['status'] ?? null;
        $term = trim((string) ($data['q'] ?? ''));
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
        [$start, $end] = $period->bounds();

        $keys = collect();
        // Voided and owing apply to offline sales only: a delivered marketplace order is paid and final.
        if ($source !== 'offline' && ! in_array($status, ['voided', 'owing'], true)) {
            $keys = $keys->concat(Order::where('seller_profile_id', $store->id)->where('status', OrderStatus::Delivered->value)
                ->whereBetween('delivered_at', [$start, $end])
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw("LOWER(number) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereHas('customer', fn ($c) => $c->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like]))))
                ->get(['id', 'delivered_at'])
                ->map(fn ($o) => ['kind' => 'marketplace', 'id' => $o->id, 'day' => $period->dayOf($o->delivered_at), 'at' => $o->delivered_at->getTimestamp()]));
        }
        if ($source !== 'marketplace') {
            $sales = OfflineSale::where('seller_profile_id', $store->id)
                // What customers still owe is a question about now, so it covers sales of any date.
                ->when($status !== 'owing', fn ($q) => $q->whereDate('sale_date', '>=', $period->from->toDateString())->whereDate('sale_date', '<=', $period->to->toDateString()))
                ->when($status, fn ($q, $s) => $q->where('status', $s === 'voided' ? 'voided' : 'completed'))
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw("LOWER(number) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(customer_name) LIKE ? ESCAPE '!'", [$like])->orWhereRaw("customer_phone LIKE ? ESCAPE '!'", [$like])))
                ->get(['id', 'status', 'total_santim', 'sale_date', 'created_at']);
            if ($status === 'owing') {
                $money = SaleFigures::money($sales->pluck('id')->all());
                $sales = $sales->filter(fn ($s) => SaleFigures::balance($s, $money[$s->id]) > 0);
            }
            $keys = $keys->concat($sales->map(fn ($s) => ['kind' => 'offline', 'id' => $s->id, 'day' => $s->sale_date->toDateString(), 'at' => $s->created_at->getTimestamp()]));
        }

        $keys = $keys->sort(fn ($a, $b) => strcmp($b['day'], $a['day']) ?: $b['at'] <=> $a['at'] ?: $b['id'] <=> $a['id'])->values();
        $page = (int) ($data['page'] ?? 1);
        $paginator = new LengthAwarePaginator($keys->slice(($page - 1) * 25, 25)->values(), $keys->count(), 25, $page);
        $slice = collect($paginator->items());

        $orders = Order::whereIn('id', $slice->where('kind', 'marketplace')->pluck('id'))->with(['customer:id,name', 'items'])->get()->keyBy('id');
        $orderReturns = SaleReturn::whereIn('order_id', $orders->keys())->where('status', 'recorded')
            ->selectRaw('order_id, sum(goods_credit_santim + delivery_credit_santim) as credit')->groupBy('order_id')->pluck('credit', 'order_id');
        $offline = OfflineSale::whereIn('id', $slice->where('kind', 'offline')->pluck('id'))->with('items')->get()->keyBy('id');
        $money = SaleFigures::money($offline->keys()->all());

        $rows = $slice->map(fn ($k) => $k['kind'] === 'marketplace'
            ? self::orderRow($orders[$k['id']], (int) ($orderReturns[$k['id']] ?? 0), $period)
            : self::offlineRow($offline[$k['id']], $money[$k['id']]));

        return response()->json([
            'data' => $rows->values(),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total(), 'range' => $period->toArray()],
        ]);
    }

    /**
     * Totals, sales by day and by item for the period; what customers owe on offline sales and the
     * marketplace orders still on their way (as of now).
     */
    public function report(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $report = SalesReport::for($store->id, Period::fromRequest($request));

        $report['customers'] = SaleFigures::customers($store->id);
        $report['in_progress'] = Order::where('seller_profile_id', $store->id)->visibleToSeller()
            ->whereIn('status', [OrderStatus::PendingConfirmation->value, OrderStatus::Confirmed->value, OrderStatus::Processing->value, OrderStatus::Ready->value, OrderStatus::Dispatched->value])
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        return response()->json(['data' => $report]);
    }

    /** Every sale line and return line in the period, for a spreadsheet. */
    public function export(Request $request, AuditLogger $audit): StreamedResponse
    {
        $store = $this->currentStore($request);
        $period = Period::fromRequest($request);
        [$start, $end] = $period->bounds();
        $audit->log('erp.sales_exported', $store, $period->toArray());
        $birr = fn (?int $s) => $s === null ? '' : Money::toBirr($s);
        // Names and descriptions people typed must not run as spreadsheet formulas.
        $text = ReportController::cell(...);

        return response()->streamDownload(function () use ($store, $period, $start, $end, $birr, $text) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'type', 'document', 'for', 'customer', 'item', 'quantity', 'amount_etb', 'unit_cost_etb', 'back_in_stock'], escape: '');
            Order::where('seller_profile_id', $store->id)->where('status', OrderStatus::Delivered->value)->whereBetween('delivered_at', [$start, $end])
                ->with(['customer:id,name', 'items'])->orderBy('delivered_at')->chunk(200, function ($orders) use ($out, $period, $birr, $text) {
                    foreach ($orders as $o) {
                        foreach ($o->items as $i) {
                            fputcsv($out, [$period->dayOf($o->delivered_at), 'marketplace_sale', $o->number, '', $text($o->customer?->name),
                                $text($i->name.($i->variant_label ? " ({$i->variant_label})" : '')), $i->quantity, $birr($i->line_total_santim), $birr($i->unit_cost_santim), ''], escape: '');
                        }
                        if ($o->delivery_fee_santim) {
                            fputcsv($out, [$period->dayOf($o->delivered_at), 'marketplace_sale', $o->number, '', $text($o->customer?->name), 'Delivery', '', $birr($o->delivery_fee_santim), '', ''], escape: '');
                        }
                    }
                });
            OfflineSale::where('seller_profile_id', $store->id)->where('status', 'completed')
                ->whereDate('sale_date', '>=', $period->from->toDateString())->whereDate('sale_date', '<=', $period->to->toDateString())
                ->with('items')->orderBy('sale_date')->orderBy('id')->chunk(200, function ($sales) use ($out, $birr, $text) {
                    foreach ($sales as $s) {
                        foreach ($s->items as $i) {
                            fputcsv($out, [$s->sale_date->toDateString(), 'offline_sale', $s->number, '', $text($s->customer_name), $text($i->description), $i->quantity, $birr($i->net_santim), $birr($i->unit_cost_santim), ''], escape: '');
                        }
                        if ($s->delivery_santim) {
                            fputcsv($out, [$s->sale_date->toDateString(), 'offline_sale', $s->number, '', $text($s->customer_name), 'Delivery', '', $birr($s->delivery_santim), '', ''], escape: '');
                        }
                    }
                });
            SaleReturn::where('seller_profile_id', $store->id)->where('status', 'recorded')
                ->whereDate('returned_on', '>=', $period->from->toDateString())->whereDate('returned_on', '<=', $period->to->toDateString())
                ->with(['items', 'sale:id,number,customer_name', 'order:id,number,customer_id', 'order.customer:id,name'])->orderBy('returned_on')->orderBy('id')
                ->chunk(200, function ($returns) use ($out, $birr, $text) {
                    foreach ($returns as $r) {
                        $for = $r->sale?->number ?? $r->order?->number;
                        $customer = $r->sale?->customer_name ?? $r->order?->customer?->name;
                        foreach ($r->items as $i) {
                            fputcsv($out, [$r->returned_on->toDateString(), 'return', $r->number, $for, $text($customer), $text($i->description), -$i->quantity, $birr(-$i->credit_santim), $birr($i->unit_cost_santim), $i->restocked ? 'yes' : 'no'], escape: '');
                        }
                        if ($r->delivery_credit_santim) {
                            fputcsv($out, [$r->returned_on->toDateString(), 'return', $r->number, $for, $text($customer), 'Delivery', '', $birr(-$r->delivery_credit_santim), '', ''], escape: '');
                        }
                    }
                });
            fclose($out);
        }, 'sales-'.$period->from->toDateString().'-to-'.$period->to->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function showOffline(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request);
        $sale = OfflineSale::where('seller_profile_id', $store->id)->with(['items', 'warehouse:id,name,is_active', 'creator:id,name'])->findOrFail($id);

        return response()->json(['data' => $this->offline($sale, $store)]);
    }

    public function store(Request $request): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
            'sale_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'items' => ['required', 'array', 'min:1', 'max:'.SalesService::MAX_LINES],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.unit_price' => ['required', 'string', self::MONEY],
            'items.*.discount' => ['nullable', 'string', self::MONEY],
            'discount' => ['nullable', 'string', self::MONEY],
            'delivery' => ['nullable', 'string', self::MONEY],
            'paid_now' => ['nullable', 'string', self::MONEY],
            'payment_method' => ['nullable', Rule::in(OfflineSalePayment::METHODS)],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'request_id' => ['nullable', 'string', 'max:64'],
        ] + $this->detailRules(), $this->messages());
        $sale = $this->sales->record($store, $data, $request->user());

        return $this->showOffline($request, $sale->id)->setStatusCode(201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate($this->detailRules(), $this->messages());
        $this->sales->updateDetails($store, $id, $data, $request->user());

        return $this->showOffline($request, $id);
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $this->sales->voidSale($store, $id, $data['reason'], $request->user());

        return $this->showOffline($request, $id);
    }

    public function storePayment(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate([
            'amount' => ['required', 'string', self::MONEY],
            'paid_on' => ['nullable', 'date_format:Y-m-d'],
            'method' => ['required', Rule::in(OfflineSalePayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'request_id' => ['nullable', 'string', 'max:64'],
        ], $this->messages());
        $this->sales->recordPayment($store, $id, $data, $request->user());

        return $this->showOffline($request, $id)->setStatusCode(201);
    }

    public function voidPayment(Request $request, int $paymentId): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $payment = $this->sales->voidPayment($store, $paymentId, $data['reason'], $request->user());

        return $this->showOffline($request, $payment->offline_sale_id);
    }

    public function storeOfflineReturn(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $this->sales->recordReturn($store, 'offline', $id, $this->returnData($request), $request->user());

        return $this->showOffline($request, $id)->setStatusCode(201);
    }

    /** A marketplace order as a sale: its lines, receipt details and the store's returns of it. */
    public function showOrder(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request);
        $order = Order::where('seller_profile_id', $store->id)->visibleToSeller()
            ->whereIn('status', array_map(fn ($s) => $s->value, self::RECEIPT_STATUSES))
            ->with(['items', 'customer:id,name', 'checkout:id,area_name'])->findOrFail($id);
        $returned = SaleFigures::returned('order_item_id', $order->items->pluck('id')->all());
        $returns = SaleReturn::where('order_id', $order->id)->with(['items', 'warehouse:id,name', 'creator:id,name'])->orderBy('id')->get();
        $recorded = $returns->where('status', 'recorded');
        $credited = (int) $recorded->sum('delivery_credit_santim');

        return response()->json(['data' => [
            'kind' => 'marketplace',
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'placed_at' => $order->created_at?->toIso8601String(),
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'date' => CarbonImmutable::instance($order->delivered_at ?? $order->confirmed_at ?? $order->created_at)->setTimezone(config('marketplace.timezone'))->toDateString(),
            'customer' => ['name' => $order->customer?->name, 'area' => $order->checkout?->area_name],
            'fulfillment' => $order->fulfillment,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'items' => $order->items->map(fn (OrderItem $i) => [
                'id' => $i->id,
                'description' => $i->name.($i->variant_label ? " ({$i->variant_label})" : ''),
                'product_id' => $i->product_id,
                'quantity' => $i->quantity,
                'unit_price_santim' => $i->unit_price_santim,
                'discount_santim' => 0,
                'line_total_santim' => $i->line_total_santim,
                'returned' => $returned[$i->id] ?? 0,
                'returnable' => $i->quantity - ($returned[$i->id] ?? 0),
                'stock_item' => $i->product_id !== null && $i->quote_id === null,
            ])->values(),
            'items_total_santim' => $order->items_total_santim,
            'delivery_santim' => (int) $order->delivery_fee_santim,
            'total_santim' => $order->total_santim,
            'commission_santim' => $order->commission_santim,
            'returned_santim' => (int) $recorded->sum(fn ($r) => $r->creditSantim()),
            'refunded_santim' => (int) $recorded->sum('refund_santim'),
            'delivery_credit_left_santim' => $order->payment_method === 'online' ? 0 : max(0, (int) $order->delivery_fee_santim - $credited),
            'can_return' => $order->status === OrderStatus::Delivered,
            'returns' => $returns->map(fn ($r) => self::saleReturn($r))->values(),
            'store' => self::storeInfo($store),
        ]]);
    }

    public function storeOrderReturn(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $this->sales->recordReturn($store, 'order', $id, $this->returnData($request), $request->user());

        return $this->showOrder($request, $id)->setStatusCode(201);
    }

    public function voidReturn(Request $request, int $returnId): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $return = $this->sales->voidReturn($store, $returnId, $data['reason'], $request->user());

        return $return->offline_sale_id ? $this->showOffline($request, $return->offline_sale_id) : $this->showOrder($request, $return->order_id);
    }

    /** @return array<string, mixed> */
    private function returnData(Request $request): array
    {
        return $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.SalesService::MAX_LINES],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:10000'],
            'items.*.restock' => ['nullable', 'boolean'],
            'warehouse_id' => ['nullable', 'integer'],
            'returned_on' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'delivery_credit' => ['nullable', 'string', self::MONEY],
            'refund' => ['nullable', 'string', self::MONEY],
            'refund_method' => ['nullable', Rule::in(OfflineSalePayment::METHODS)],
            'request_id' => ['nullable', 'string', 'max:64'],
        ], $this->messages());
    }

    /** @return array<string, list<mixed>> */
    private function detailRules(): array
    {
        return [
            'customer_name' => ['nullable', 'string', 'max:100'],
            'customer_phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ]{7,19}$/'],
            'customer_tin' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'regex' => self::MONEY_MESSAGE,
            'customer_phone.regex' => 'Enter a phone number, for example 0911 234 567.',
            'customer_tin.regex' => 'Use digits only.',
            'items.*.quantity.min' => 'Enter at least 1.',
            'sale_date.after_or_equal' => 'Use a date from 2000 on.',
        ];
    }

    /** @return array<string, mixed> */
    private function offline(OfflineSale $sale, SellerProfile $store): array
    {
        $money = SaleFigures::of($sale);
        $returned = SaleFigures::returned('offline_sale_item_id', $sale->items->pluck('id')->all());
        $today = CarbonImmutable::now(config('marketplace.timezone'))->toDateString();

        return self::offlineRow($sale, $money) + [
            'due_date' => $sale->due_date?->toDateString(),
            'overdue' => $money['balance_santim'] > 0 && $sale->due_date && $sale->due_date->toDateString() < $today,
            'customer_phone' => $sale->customer_phone,
            'customer_tin' => $sale->customer_tin,
            'note' => $sale->note,
            'warehouse' => $sale->warehouse ? ['id' => $sale->warehouse->id, 'name' => $sale->warehouse->name, 'is_active' => $sale->warehouse->is_active] : null,
            'items' => $sale->items->map(fn (OfflineSaleItem $i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'variant_id' => $i->variant_id,
                'description' => $i->description,
                'quantity' => $i->quantity,
                'unit_price_santim' => $i->unit_price_santim,
                'discount_santim' => $i->discount_santim,
                'line_total_santim' => $i->line_total_santim,
                'net_santim' => $i->net_santim,
                'unit_cost_santim' => $i->unit_cost_santim,
                'stock_item' => $i->stock_taken,
                'returned' => $returned[$i->id] ?? 0,
                'returnable' => $i->quantity - ($returned[$i->id] ?? 0),
            ])->values(),
            'subtotal_santim' => $sale->subtotal_santim,
            'discount_santim' => $sale->discount_santim,
            'delivery_santim' => $sale->delivery_santim,
            'delivery_credit_left_santim' => max(0, $sale->delivery_santim - $money['delivery_credited_santim']),
            'credited_santim' => $money['credited_santim'],
            'refunded_santim' => $money['refunded_santim'],
            'can_return' => ! $sale->isVoided(),
            'payments' => OfflineSalePayment::where('offline_sale_id', $sale->id)->with('creator:id,name')->orderBy('paid_on')->orderBy('id')->get()
                ->map(fn ($p) => [
                    'id' => $p->id, 'amount_santim' => $p->amount_santim, 'paid_on' => $p->paid_on->toDateString(), 'method' => $p->method,
                    'reference' => $p->reference, 'note' => $p->note, 'status' => $p->status, 'void_reason' => $p->void_reason, 'by' => $p->creator?->name,
                ])->values(),
            'returns' => SaleReturn::where('offline_sale_id', $sale->id)->with(['items', 'warehouse:id,name', 'creator:id,name'])->orderBy('id')->get()
                ->map(fn ($r) => self::saleReturn($r))->values(),
            'void_reason' => $sale->void_reason,
            'voided_at' => $sale->voided_at?->toIso8601String(),
            'by' => $sale->creator?->name,
            'store' => self::storeInfo($store),
        ];
    }

    /** @return array<string, mixed> */
    private static function offlineRow(OfflineSale $sale, array $money): array
    {
        return [
            'kind' => 'offline',
            'id' => $sale->id,
            'number' => $sale->number,
            'status' => $sale->status,
            'date' => $sale->sale_date->toDateString(),
            'customer' => ['name' => $sale->customer_name],
            'items_count' => $sale->items->count(),
            'summary' => self::summary($sale->items->pluck('description')->all()),
            'total_santim' => $sale->total_santim,
            'paid_santim' => $sale->isVoided() ? 0 : $money['paid_santim'] - $money['refunded_santim'],
            'balance_santim' => SaleFigures::balance($sale, $money),
            'returned_santim' => $money['credited_santim'],
            'payment_method' => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function orderRow(Order $order, int $returned, Period $period): array
    {
        return [
            'kind' => 'marketplace',
            'id' => $order->id,
            'number' => $order->number,
            'status' => 'delivered',
            'date' => $period->dayOf($order->delivered_at),
            'customer' => ['name' => $order->customer?->name],
            'items_count' => $order->items->count(),
            'summary' => self::summary($order->items->map(fn ($i) => $i->name.($i->variant_label ? " ({$i->variant_label})" : ''))->all()),
            'total_santim' => $order->total_santim,
            // Delivered: cash was collected at the door, or Betoch holds the online payment.
            'paid_santim' => $order->total_santim,
            'balance_santim' => 0,
            'returned_santim' => $returned,
            'payment_method' => $order->payment_method,
        ];
    }

    /** "Sofa, Coffee table and 2 more" */
    private static function summary(array $names): string
    {
        $first = array_slice($names, 0, 2);
        $more = count($names) - count($first);

        return implode(', ', $first).($more > 0 ? " and {$more} more" : '');
    }

    /** @return array<string, mixed> */
    private static function saleReturn(SaleReturn $r): array
    {
        return [
            'id' => $r->id,
            'number' => $r->number,
            'status' => $r->status,
            'returned_on' => $r->returned_on->toDateString(),
            'warehouse' => $r->warehouse?->name,
            'goods_credit_santim' => $r->goods_credit_santim,
            'delivery_credit_santim' => $r->delivery_credit_santim,
            'credit_santim' => $r->creditSantim(),
            'refund_santim' => $r->refund_santim,
            'refund_method' => $r->refund_method,
            'reason' => $r->reason,
            'void_reason' => $r->void_reason,
            'by' => $r->creator?->name,
            'items' => $r->items->map(fn (SaleReturnItem $i) => [
                'id' => $i->id, 'description' => $i->description, 'quantity' => $i->quantity, 'restocked' => $i->restocked, 'credit_santim' => $i->credit_santim,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private static function storeInfo(SellerProfile $store): array
    {
        return ['name' => $store->store_name, 'phone' => $store->phone, 'tin' => $store->tin_number, 'address' => $store->address_line];
    }
}
