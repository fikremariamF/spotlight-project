<?php

namespace App\Http\Controllers\Api\Seller\Erp;

use App\Http\Controllers\Api\Seller\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Services\AuditLogger;
use App\Services\PurchasingService;
use App\Support\Erp\StoreLock;
use App\Support\Erp\SupplierBalances;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The store's suppliers with what it bought from each, paid and still owes. Private to the store.
 */
class SupplierController extends Controller
{
    use ResolvesStore;

    private const MONEY = 'regex:/^\d{1,9}(\.\d{1,2})?$/';

    public function __construct(private readonly PurchasingService $purchasing) {}

    public function index(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'owing' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['name', 'owed', 'recent'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'brief' => ['nullable', 'boolean'],
        ]);
        // Every supplier's name, for pickers: no figures, no paging.
        if ($request->boolean('brief')) {
            return response()->json(['data' => Supplier::where('seller_profile_id', $store->id)->orderBy('name')->limit(2000)
                ->get(['id', 'name', 'is_active', 'payment_days'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'is_active' => $s->is_active, 'payment_days' => $s->payment_days])]);
        }
        $suppliers = Supplier::where('seller_profile_id', $store->id)
            ->when(trim((string) ($data['q'] ?? '')), function ($q, $term) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
                $q->where(fn ($w) => $w->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like])->orWhereRaw("LOWER(contact_person) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$like])->orWhereRaw("phone LIKE ? ESCAPE '!'", [$like]));
            })
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('is_active', $s === 'active'))
            ->withCount(['purchaseOrders as open_orders' => fn ($q) => $q->whereIn('status', ['ordered', 'partially_received'])])
            ->withMax('purchaseOrders as last_order_date', 'order_date')
            ->limit(2000)->get();
        $balances = SupplierBalances::forStore($store->id);
        $rows = $suppliers->map(fn (Supplier $s) => self::data($s) + $balances->supplier($s->id) + [
            'open_orders' => (int) $s->open_orders, 'last_order_date' => $s->last_order_date,
        ]);
        if ($request->boolean('owing')) {
            $rows = $rows->filter(fn ($r) => $r['owed_santim'] > 0);
        }
        $rows = match ($data['sort'] ?? 'name') {
            'owed' => $rows->sortByDesc('owed_santim'),
            'recent' => $rows->sortByDesc(fn ($r) => $r['last_order_date'] ?? ''),
            default => $rows->sortBy(fn ($r) => mb_strtolower($r['name'])),
        };
        $page = (int) ($data['page'] ?? 1);
        $paginator = new LengthAwarePaginator($rows->slice(($page - 1) * 25, 25)->values(), $rows->count(), 25, $page);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total(),
                'owed_santim' => $balances->totalOwed(), 'received_not_invoiced_santim' => $balances->totalReceivedNotInvoiced()],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request);
        $supplier = Supplier::where('seller_profile_id', $store->id)->findOrFail($id);
        $balances = SupplierBalances::forStore($store->id, [$supplier->id]);
        $today = CarbonImmutable::now(config('marketplace.timezone'))->toDateString();

        $orders = PurchaseOrder::where('supplier_id', $supplier->id)->latest('id')->limit(30)->get()
            ->map(fn ($po) => PurchaseController::summary($po, $balances->order($po->id)));
        $invoices = SupplierInvoice::where('supplier_id', $supplier->id)->with('order:id,number')->latest('invoice_date')->latest('id')->limit(50)->get()
            ->map(function ($inv) use ($balances, $today) {
                $row = self::invoice($inv);
                // Overdue: past its due date while the order (or, without one, the supplier) still has money owed.
                $owing = $inv->purchase_order_id ? $balances->order($inv->purchase_order_id)['balance_santim'] > 0 : $balances->supplier($inv->supplier_id)['owed_santim'] > 0;
                $row['overdue'] = ! $inv->isVoided() && $inv->due_date && $inv->due_date->toDateString() < $today && $owing;

                return $row;
            });
        $payments = SupplierPayment::where('supplier_id', $supplier->id)->with('order:id,number')->latest('paid_on')->latest('id')->limit(50)->get()
            ->map(fn ($p) => self::payment($p));

        // Listings marked as usually bought here, and anything ever ordered from this supplier.
        $orderedIds = PurchaseOrderItem::whereHas('order', fn ($q) => $q->where('supplier_id', $supplier->id)->where('status', '!=', 'cancelled'))
            ->distinct()->pluck('product_id');
        $products = Product::where('seller_profile_id', $store->id)
            ->where(fn ($q) => $q->where('supplier_id', $supplier->id)->orWhereIn('id', $orderedIds))
            ->with('primaryImage')->orderBy('name')->limit(100)->get()
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'image' => $p->primaryImage?->thumbUrl(),
                'preferred' => $p->supplier_id === $supplier->id, 'cost_price_santim' => $p->cost_price_santim]);

        return response()->json(['data' => self::data($supplier) + $balances->supplier($supplier->id) + [
            'orders' => $orders,
            'invoices' => $invoices,
            'payments' => $payments,
            'products' => $products,
            'can_delete' => ! $supplier->purchaseOrders()->exists() && ! $supplier->invoices()->exists() && ! $supplier->payments()->exists(),
        ]]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $this->validated($request);

        $supplier = DB::transaction(function () use ($store, $data, $audit) {
            StoreLock::take($store->id);
            $this->assertNameFree($store->id, $data['name']);
            $supplier = new Supplier($data);
            $supplier->seller_profile_id = $store->id;
            $supplier->is_active = true;
            $supplier->save();
            $audit->log('erp.supplier_created', $supplier, ['name' => $supplier->name]);

            return $supplier;
        });

        return $this->show($request, $supplier->id)->setStatusCode(201);
    }

    public function update(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $this->validated($request);
        $active = $request->validate(['is_active' => ['sometimes', 'boolean']]);

        DB::transaction(function () use ($store, $id, $data, $active, $audit) {
            StoreLock::take($store->id);
            $supplier = Supplier::where('seller_profile_id', $store->id)->lockForUpdate()->findOrFail($id);
            $this->assertNameFree($store->id, $data['name'], $supplier->id);
            $supplier->fill($data);
            if (array_key_exists('is_active', $active)) {
                $supplier->is_active = (bool) $active['is_active'];
            }
            if ($supplier->isDirty()) {
                $fields = array_keys($supplier->getDirty());
                $supplier->save();
                $audit->log('erp.supplier_updated', $supplier, ['fields' => $fields]);
            }
        });

        return $this->show($request, $id);
    }

    /** Only a supplier with no orders, invoices or payments; otherwise mark it inactive. */
    public function destroy(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);

        DB::transaction(function () use ($store, $id, $audit) {
            $supplier = Supplier::where('seller_profile_id', $store->id)->lockForUpdate()->findOrFail($id);
            if ($supplier->purchaseOrders()->exists() || $supplier->invoices()->exists() || $supplier->payments()->exists()) {
                throw ValidationException::withMessages(['supplier' => 'This supplier has orders, invoices or payments on record. Mark it inactive instead.']);
            }
            $audit->log('erp.supplier_deleted', $supplier, ['name' => $supplier->name]);
            $supplier->delete();
        });

        return response()->json(['message' => 'Supplier deleted.']);
    }

    public function storeInvoice(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        Supplier::where('seller_profile_id', $store->id)->findOrFail($id);
        $data = $request->validate([
            'purchase_order_id' => ['nullable', 'integer'],
            'invoice_number' => ['required', 'string', 'max:60', 'regex:/\S/'],
            'invoice_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'amount' => ['required', 'string', self::MONEY],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['amount.regex' => 'Enter the amount in birr, for example 25000 or 25000.50.']);
        $invoice = $this->purchasing->recordInvoice($store, $id, $data, $request->user());

        return response()->json(['data' => self::invoice($invoice->load('order:id,number'))], 201);
    }

    public function voidInvoice(Request $request, int $invoiceId): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return response()->json(['data' => self::invoice($this->purchasing->voidInvoice($store, $invoiceId, $data['reason'], $request->user())->load('order:id,number'))]);
    }

    public function storePayment(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        Supplier::where('seller_profile_id', $store->id)->findOrFail($id);
        $data = $request->validate([
            'purchase_order_id' => ['nullable', 'integer'],
            'amount' => ['required', 'string', self::MONEY],
            'paid_on' => ['nullable', 'date_format:Y-m-d'],
            'method' => ['required', Rule::in(SupplierPayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'request_id' => ['nullable', 'string', 'max:64'],
        ], ['amount.regex' => 'Enter the amount in birr, for example 25000 or 25000.50.']);
        $payment = $this->purchasing->recordPayment($store, $id, $data, $request->user());

        return response()->json(['data' => self::payment($payment->load('order:id,number'))], 201);
    }

    public function voidPayment(Request $request, int $paymentId): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return response()->json(['data' => self::payment($this->purchasing->voidPayment($store, $paymentId, $data['reason'], $request->user())->load('order:id,number'))]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ]{7,19}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/'],
            'payment_terms' => ['nullable', 'string', 'max:120'],
            'payment_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'phone.regex' => 'Enter a phone number, for example 0911 234 567.',
            'tin.regex' => 'Use digits only.',
        ]);
        $data['name'] = trim($data['name']);

        return $data;
    }

    private function assertNameFree(int $storeId, string $name, ?int $except = null): void
    {
        $taken = Supplier::where('seller_profile_id', $storeId)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($except, fn ($q) => $q->whereKeyNot($except))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'You already have a supplier with this name.']);
        }
    }

    /** @return array<string, mixed> */
    public static function data(Supplier $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'contact_person' => $s->contact_person,
            'phone' => $s->phone,
            'email' => $s->email,
            'address' => $s->address,
            'tin' => $s->tin,
            'payment_terms' => $s->payment_terms,
            'payment_days' => $s->payment_days,
            'notes' => $s->notes,
            'is_active' => $s->is_active,
        ];
    }

    /** @return array<string, mixed> */
    public static function invoice(SupplierInvoice $i): array
    {
        return [
            'id' => $i->id,
            'invoice_number' => $i->invoice_number,
            'invoice_date' => $i->invoice_date->toDateString(),
            'due_date' => $i->due_date?->toDateString(),
            'amount_santim' => $i->amount_santim,
            'note' => $i->note,
            'status' => $i->status,
            'void_reason' => $i->void_reason,
            'purchase_order' => $i->order ? ['id' => $i->order->id, 'number' => $i->order->number] : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function payment(SupplierPayment $p): array
    {
        return [
            'id' => $p->id,
            'amount_santim' => $p->amount_santim,
            'paid_on' => $p->paid_on->toDateString(),
            'method' => $p->method,
            'reference' => $p->reference,
            'note' => $p->note,
            'status' => $p->status,
            'void_reason' => $p->void_reason,
            'purchase_order' => $p->order ? ['id' => $p->order->id, 'number' => $p->order->number] : null,
        ];
    }
}
