<?php

namespace App\Http\Controllers\Api\Seller\Erp;

use App\Enums\TransferStatus;
use App\Http\Controllers\Api\Seller\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use App\Services\StockTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Stock moved between the store's locations: list, send, receive, cancel. */
class TransferController extends Controller
{
    use ResolvesStore;

    public function __construct(private readonly StockTransferService $transfers) {}

    public function index(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(TransferStatus::class)],
            'warehouse_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $page = StockTransfer::where('seller_profile_id', $store->id)
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['warehouse_id'] ?? null, fn ($q, $w) => $q->where(fn ($q) => $q->where('from_warehouse_id', $w)->orWhere('to_warehouse_id', $w)))
            ->with(['from:id,name', 'to:id,name'])->withSum('items as units', 'quantity')
            ->latest('id')->paginate(25)->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($t) => self::summary($t))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'in_transit' => StockTransfer::where('seller_profile_id', $store->id)->where('status', 'in_transit')->count()],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request);
        $transfer = StockTransfer::where('seller_profile_id', $store->id)
            ->with(['from:id,name', 'to:id,name', 'items', 'creator:id,name', 'receiver:id,name'])->withSum('items as units', 'quantity')
            ->findOrFail($id);

        return response()->json(['data' => self::summary($transfer) + [
            'items' => $transfer->items->map(fn ($i) => [
                'id' => $i->id, 'product_id' => $i->product_id, 'variant_id' => $i->variant_id, 'description' => $i->description,
                'quantity' => $i->quantity, 'received_quantity' => $i->received_quantity,
            ]),
            'by' => $transfer->creator?->name,
            'received_by' => $transfer->receiver?->name,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate([
            'from_warehouse_id' => ['required', 'integer'],
            'to_warehouse_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:'.StockTransferService::MAX_LINES],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:255'],
            'received_now' => ['sometimes', 'boolean'],
            'request_id' => ['nullable', 'string', 'max:64'],
        ]);
        $transfer = $this->transfers->create($store->id, $data, $request->user());

        return $this->show($request, $transfer->id)->setStatusCode(201);
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate([
            'items' => ['nullable', 'array', 'max:'.StockTransferService::MAX_LINES],
            'items.*.id' => ['required', 'integer'],
            'items.*.received_quantity' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);
        $received = collect($data['items'] ?? [])->mapWithKeys(fn ($i) => [(int) $i['id'] => (int) $i['received_quantity']])->all();
        $this->transfers->receive($store->id, $id, $received, $request->user());

        return $this->show($request, $id);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $this->transfers->cancel($store->id, $id, $request->user());

        return $this->show($request, $id);
    }

    /** @return array<string, mixed> */
    public static function summary(StockTransfer $t): array
    {
        return [
            'id' => $t->id,
            'number' => $t->number,
            'status' => $t->status->value,
            'from' => ['id' => $t->from_warehouse_id, 'name' => $t->from?->name],
            'to' => ['id' => $t->to_warehouse_id, 'name' => $t->to?->name],
            'units' => (int) $t->units,
            'note' => $t->note,
            'dispatched_at' => $t->dispatched_at?->toIso8601String(),
            'received_at' => $t->received_at?->toIso8601String(),
            'cancelled_at' => $t->cancelled_at?->toIso8601String(),
        ];
    }
}
