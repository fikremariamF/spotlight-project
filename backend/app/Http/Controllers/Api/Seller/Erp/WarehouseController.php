<?php

namespace App\Http\Controllers\Api\Seller\Erp;

use App\Http\Controllers\Api\Seller\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use App\Support\Erp\LocationStock;
use App\Support\Erp\StoreLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A store's locations (shop, warehouse, workshop). Every store has one default location that cannot
 * be closed; others can be added, edited, closed once empty, and reopened.
 */
class WarehouseController extends Controller
{
    use ResolvesStore;

    public const MAX_PER_STORE = 20;

    public function __construct(private readonly InventoryService $inventory) {}

    public function index(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $this->inventory->defaultWarehouse($store->id);
        $stock = LocationStock::forStore($store->id, $this->inventory);
        $inTransit = StockTransfer::where('seller_profile_id', $store->id)->where('status', 'in_transit')
            ->selectRaw('to_warehouse_id, count(*) as n')->groupBy('to_warehouse_id')->pluck('n', 'to_warehouse_id');

        $list = Warehouse::where('seller_profile_id', $store->id)->orderByDesc('is_default')->orderByDesc('is_active')->orderBy('name')->get()
            ->map(fn (Warehouse $w) => self::data($w) + $stock->totals($w->id) + ['incoming_transfers' => (int) ($inTransit[$w->id] ?? 0)]);

        return response()->json(['data' => $list, 'meta' => ['max' => self::MAX_PER_STORE]]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request);
        $warehouse = Warehouse::where('seller_profile_id', $store->id)->findOrFail($id);
        $stock = LocationStock::forStore($store->id, $this->inventory);

        return response()->json(['data' => self::data($warehouse) + $stock->totals($warehouse->id) + [
            'stock' => array_slice($stock->at($warehouse->id), 0, 500),
            'movements' => StockMovement::where('seller_profile_id', $store->id)->where('warehouse_id', $warehouse->id)
                ->with(['product:id,name,slug', 'warehouse:id,name', 'variant:id,size,color,material', 'order:id,number', 'user:id,name'])
                ->latest('id')->limit(15)->get()->map(fn ($m) => InventoryController::movement($m, withProduct: true)),
            'transfers' => StockTransfer::where('seller_profile_id', $store->id)
                ->where(fn ($q) => $q->where('from_warehouse_id', $warehouse->id)->orWhere('to_warehouse_id', $warehouse->id))
                ->with(['from:id,name', 'to:id,name'])->withSum('items as units', 'quantity')
                ->latest('id')->limit(10)->get()->map(fn ($t) => TransferController::summary($t)),
            'receipts' => PurchaseReceipt::where('seller_profile_id', $store->id)->where('warehouse_id', $warehouse->id)
                ->with('order:id,number,supplier_id', 'order.supplier:id,name')->withSum('items as units', 'quantity')
                ->latest('id')->limit(10)->get()->map(fn ($r) => [
                    'id' => $r->id, 'number' => $r->number, 'received_on' => $r->received_on->toDateString(), 'units' => (int) $r->units,
                    'purchase_order' => ['id' => $r->order->id, 'number' => $r->order->number], 'supplier' => $r->order->supplier?->name,
                ]),
        ]]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $this->validated($request);

        $warehouse = DB::transaction(function () use ($store, $data, $audit) {
            StoreLock::take($store->id);
            $this->inventory->defaultWarehouse($store->id);
            if (Warehouse::where('seller_profile_id', $store->id)->count() >= self::MAX_PER_STORE) {
                throw ValidationException::withMessages(['name' => 'A store can have up to '.self::MAX_PER_STORE.' locations.']);
            }
            $this->assertNameFree($store->id, $data['name']);
            $warehouse = new Warehouse($data);
            $warehouse->seller_profile_id = $store->id;
            $warehouse->is_default = false;
            $warehouse->is_active = true;
            $warehouse->save();
            $audit->log('erp.warehouse_created', $warehouse, ['name' => $warehouse->name]);

            return $warehouse;
        });

        return $this->show($request, $warehouse->id)->setStatusCode(201);
    }

    public function update(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $this->validated($request);

        DB::transaction(function () use ($store, $id, $data, $audit) {
            StoreLock::take($store->id);
            $warehouse = Warehouse::where('seller_profile_id', $store->id)->lockForUpdate()->findOrFail($id);
            $this->assertNameFree($store->id, $data['name'], $warehouse->id);
            $warehouse->fill($data);
            if ($warehouse->isDirty()) {
                $fields = array_keys($warehouse->getDirty());
                $warehouse->save();
                $audit->log('erp.warehouse_updated', $warehouse, ['fields' => $fields]);
            }
        });

        return $this->show($request, $id);
    }

    /**
     * Close a location: it stops taking stock and is hidden from pickers. Only when nothing is left
     * there and nothing is on its way to or from it.
     */
    public function close(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);

        DB::transaction(function () use ($store, $id, $audit) {
            StoreLock::take($store->id);
            $warehouse = Warehouse::where('seller_profile_id', $store->id)->lockForUpdate()->findOrFail($id);
            if ($warehouse->is_default) {
                throw ValidationException::withMessages(['warehouse' => 'Your main location cannot be closed. Rename it instead.']);
            }
            if (! $warehouse->is_active) {
                throw ValidationException::withMessages(['warehouse' => 'This location is already closed.']);
            }
            $units = (int) $warehouse->stock()->sum('quantity');
            if ($units > 0) {
                throw ValidationException::withMessages(['warehouse' => "{$units} units are still here. Move them to another location first."]);
            }
            $moving = StockTransfer::where('seller_profile_id', $store->id)->where('status', 'in_transit')
                ->where(fn ($q) => $q->where('from_warehouse_id', $warehouse->id)->orWhere('to_warehouse_id', $warehouse->id))->value('number');
            if ($moving) {
                throw ValidationException::withMessages(['warehouse' => "Transfer {$moving} is on its way to or from here. Receive or cancel it first."]);
            }
            $po = PurchaseOrder::where('seller_profile_id', $store->id)->where('warehouse_id', $warehouse->id)
                ->whereIn('status', ['draft', 'ordered', 'partially_received'])->value('number');
            if ($po) {
                throw ValidationException::withMessages(['warehouse' => "Purchase order {$po} is set to be delivered here. Change its location, or finish it, first."]);
            }
            $warehouse->is_active = false;
            $warehouse->save();
            $audit->log('erp.warehouse_closed', $warehouse);
        });

        return $this->show($request, $id);
    }

    public function reopen(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);

        DB::transaction(function () use ($store, $id, $audit) {
            StoreLock::take($store->id);
            $warehouse = Warehouse::where('seller_profile_id', $store->id)->lockForUpdate()->findOrFail($id);
            if (! $warehouse->is_active) {
                $warehouse->is_active = true;
                $warehouse->save();
                $audit->log('erp.warehouse_reopened', $warehouse);
            }
        });

        return $this->show($request, $id);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ]{7,19}$/'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'code.regex' => 'Use letters, numbers, dots or dashes.',
            'phone.regex' => 'Enter a phone number, for example 0911 234 567.',
        ]);
        $data['name'] = trim($data['name']);

        return $data;
    }

    private function assertNameFree(int $storeId, string $name, ?int $except = null): void
    {
        $taken = Warehouse::where('seller_profile_id', $storeId)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($except, fn ($q) => $q->whereKeyNot($except))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'You already have a location with this name.']);
        }
    }

    /** @return array<string, mixed> */
    public static function data(Warehouse $w): array
    {
        return [
            'id' => $w->id,
            'name' => $w->name,
            'code' => $w->code,
            'address_line' => $w->address_line,
            'contact_name' => $w->contact_name,
            'phone' => $w->phone,
            'notes' => $w->notes,
            'is_default' => $w->is_default,
            'is_active' => $w->is_active,
        ];
    }
}
