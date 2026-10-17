<?php

namespace App\Http\Controllers\Api\Seller\Erp;

use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Api\Seller\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Services\PurchasingService;
use App\Support\Erp\SupplierBalances;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Purchase orders: drafts, sending, receiving goods, cancelling and closing. */
class PurchaseController extends Controller
{
    use ResolvesStore;

    private const MONEY = 'regex:/^\d{1,9}(\.\d{1,2})?$/';

    public function __construct(private readonly PurchasingService $purchasing) {}

    public function index(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $data = $request->validate([
            'status' => ['nullable', Rule::in([...array_column(PurchaseOrderStatus::cases(), 'value'), 'open', 'late'])],
            'supplier_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $today = CarbonImmutable::now(config('marketplace.timezone'))->toDateString();
        $base = PurchaseOrder::where('seller_profile_id', $store->id);
        $page = (clone $base)
            ->when($data['status'] ?? null, fn ($q, $s) => match ($s) {
                'open' => $q->whereIn('status', ['ordered', 'partially_received']),
                'late' => $q->whereIn('status', ['ordered', 'partially_received'])->whereDate('expected_date', '<', $today),
                default => $q->where('status', $s),
            })
            ->when($data['supplier_id'] ?? null, fn ($q, $s) => $q->where('supplier_id', $s))
            ->when(trim((string) ($data['q'] ?? '')), fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'like', '%'.str_replace(['%', '_'], '', $t).'%')
                ->orWhere('supplier_reference', 'like', '%'.str_replace(['%', '_'], '', $t).'%')))
            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('order_date', '>=', $d))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('order_date', '<=', $d))
            ->with('supplier:id,name')->latest('order_date')->latest('id')->paginate(25)->withQueryString();
        $balances = SupplierBalances::forStore($store->id, $page->getCollection()->pluck('supplier_id')->unique()->values()->all());

        return response()->json([
            'data' => $page->getCollection()->map(fn ($po) => self::summary($po, $balances->order($po->id)))->values(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'counts' => [
                    'draft' => (clone $base)->where('status', 'draft')->count(),
                    'open' => (clone $base)->whereIn('status', ['ordered', 'partially_received'])->count(),
                    'late' => (clone $base)->whereIn('status', ['ordered', 'partially_received'])->whereDate('expected_date', '<', $today)->count(),
                ],
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request);
        $po = PurchaseOrder::where('seller_profile_id', $store->id)
            ->with(['supplier', 'warehouse:id,name,address_line,is_active', 'items', 'creator:id,name'])->findOrFail($id);
        $figures = SupplierBalances::forStore($store->id, [$po->supplier_id])->order($po->id);
        $receipts = PurchaseReceipt::where('purchase_order_id', $po->id)->with(['items.orderItem:id,description', 'warehouse:id,name', 'creator:id,name'])->get();
        $landed = PurchasingService::landedUnitCosts($po, $po->items);

        // array_merge, not +: the full supplier replaces the summary's name-only one.
        return response()->json(['data' => array_merge(self::summary($po, $figures), [
            'supplier' => SupplierController::data($po->supplier),
            'warehouse' => ['id' => $po->warehouse->id, 'name' => $po->warehouse->name, 'address_line' => $po->warehouse->address_line, 'is_active' => $po->warehouse->is_active],
            'supplier_reference' => $po->supplier_reference,
            'subtotal_santim' => $po->subtotal_santim,
            'discount_santim' => $po->discount_santim,
            'charges_santim' => $po->charges_santim,
            'charges_note' => $po->charges_note,
            'notes' => $po->notes,
            'cancel_reason' => $po->cancel_reason,
            'closed_short' => $po->closed_short,
            'by' => $po->creator?->name,
            'items' => $po->items->map(fn ($i) => [
                'id' => $i->id, 'product_id' => $i->product_id, 'variant_id' => $i->variant_id, 'description' => $i->description,
                'quantity' => $i->quantity, 'unit_cost_santim' => $i->unit_cost_santim, 'discount_santim' => $i->discount_santim,
                'line_total_santim' => $i->line_total_santim, 'received_quantity' => $i->received_quantity,
                'outstanding' => $po->status->isOpen() ? $i->outstanding() : 0, 'landed_unit_cost_santim' => $landed[$i->id],
            ]),
            'receipts' => $receipts->map(fn ($r) => [
                'id' => $r->id, 'number' => $r->number, 'received_on' => $r->received_on->toDateString(), 'warehouse' => $r->warehouse?->name,
                'note' => $r->note, 'by' => $r->creator?->name,
                'items' => $r->items->map(fn ($ri) => ['description' => $ri->orderItem?->description, 'quantity' => $ri->quantity, 'unit_cost_santim' => $ri->unit_cost_santim]),
            ]),
            'invoices' => SupplierInvoice::where('purchase_order_id', $po->id)->with('order:id,number')->orderBy('id')->get()->map(fn ($i) => SupplierController::invoice($i)),
            'payments' => SupplierPayment::where('purchase_order_id', $po->id)->with('order:id,number')->orderBy('id')->get()->map(fn ($p) => SupplierController::payment($p)),
            // For the printed order.
            'store' => ['name' => $store->store_name, 'phone' => $store->phone, 'tin' => $store->tin_number, 'address' => $store->address_line],
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $po = $this->purchasing->saveDraft($store, null, $this->validated($request), $request->user());

        return $this->show($request, $po->id)->setStatusCode(201);
    }

    /** A draft takes the full form; a sent order only its expected date, reference and notes. */
    public function update(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $status = PurchaseOrder::where('seller_profile_id', $store->id)->findOrFail($id)->status;
        if ($status === PurchaseOrderStatus::Draft) {
            $this->purchasing->saveDraft($store, $id, $this->validated($request), $request->user());
        } else {
            $data = $request->validate([
                'expected_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'supplier_reference' => ['sometimes', 'nullable', 'string', 'max:60'],
                'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ]);
            $this->purchasing->updateDetails($store, $id, $data, $request->user());
        }

        return $this->show($request, $id);
    }

    public function order(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $this->purchasing->markOrdered($store, $id, $request->user());

        return $this->show($request, $id);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $this->purchasing->cancel($store, $id, $data['reason'], $request->user());

        return $this->show($request, $id);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $this->purchasing->closeShort($store, $id, $data['reason'], $request->user());

        return $this->show($request, $id);
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.PurchasingService::MAX_LINES],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'warehouse_id' => ['nullable', 'integer'],
            'received_on' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:255'],
            'request_id' => ['nullable', 'string', 'max:64'],
        ]);
        $this->purchasing->receive($store, $id, $data, $request->user());

        return $this->show($request, $id);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['required', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'order_date' => ['nullable', 'date_format:Y-m-d'],
            'expected_date' => ['nullable', 'date_format:Y-m-d'],
            'supplier_reference' => ['nullable', 'string', 'max:60'],
            'discount' => ['nullable', 'string', self::MONEY],
            'charges' => ['nullable', 'string', self::MONEY],
            'charges_note' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:'.PurchasingService::MAX_LINES],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_cost' => ['required', 'string', self::MONEY],
            'items.*.discount' => ['nullable', 'string', self::MONEY],
        ], [
            'items.*.unit_cost.regex' => 'Enter the cost in birr, for example 8000 or 8000.50.',
            'items.*.discount.regex' => 'Enter the discount in birr.',
            'discount.regex' => 'Enter the discount in birr.',
            'charges.regex' => 'Enter the charges in birr.',
        ]);
    }

    /**
     * @param  array<string, int>  $figures  from SupplierBalances::order()
     * @return array<string, mixed>
     */
    public static function summary(PurchaseOrder $po, array $figures): array
    {
        $today = CarbonImmutable::now(config('marketplace.timezone'))->toDateString();

        return [
            'id' => $po->id,
            'number' => $po->number,
            'status' => $po->status->value,
            'status_label' => $po->status->label(),
            'supplier' => $po->relationLoaded('supplier') ? ['id' => $po->supplier_id, 'name' => $po->supplier?->name] : ['id' => $po->supplier_id],
            'order_date' => $po->order_date->toDateString(),
            'expected_date' => $po->expected_date?->toDateString(),
            'late' => $po->status->isOpen() && $po->expected_date && $po->expected_date->toDateString() < $today,
            'total_santim' => $po->total_santim,
            'total' => Money::toBirr($po->total_santim),
            'received_value_santim' => $figures['received_value_santim'],
            'invoiced_santim' => $figures['invoiced_santim'],
            'payable_santim' => $figures['payable_santim'],
            'paid_santim' => $figures['paid_santim'],
            'balance_santim' => $figures['balance_santim'],
        ];
    }
}
