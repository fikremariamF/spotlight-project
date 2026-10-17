<?php

namespace App\Http\Controllers\Api\Seller\Erp;

use App\Enums\ProductStatus;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Seller\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerErpSetting;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use App\Support\Erp\StockFigures;
use App\Support\Erp\StoreLock;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The store's stock items: its listings and their options, with stock, value and history.
 * Everything is scoped to the signed-in store.
 */
class InventoryController extends Controller
{
    use ResolvesStore;

    public const SORTS = ['name', 'stock', 'updated', 'sku', 'price'];

    public function __construct(private readonly InventoryService $inventory) {}

    public function index(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $filters = $this->filters($request);

        $page = $this->query($store->id, $filters)
            ->with(['variants', 'primaryImage', 'category:id,name', 'supplier:id,name'])
            ->paginate(min(100, (int) ($filters['per_page'] ?? 25)))->withQueryString();
        $figures = new StockFigures($page->getCollection(), $this->inventory);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Product $p) => $this->row($p, $figures))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $store = $this->currentStore($request);
        $product = $store->products()->with(['variants', 'primaryImage', 'category:id,name', 'supplier:id,name'])->findOrFail($id);
        $figures = new StockFigures(collect([$product]), $this->inventory);
        $row = $this->row($product, $figures);

        $variants = $product->variants->isEmpty() ? [null] : $product->variants->all();
        foreach ($row['items'] as $i => $item) {
            $row['items'][$i]['locations'] = collect($this->inventory->locations($product, $variants[$i], all: true))
                ->map(fn ($l) => ['warehouse_id' => $l[0]->id, 'name' => $l[0]->name, 'is_default' => $l[0]->is_default, 'is_active' => $l[0]->is_active, 'quantity' => $l[1]])
                ->filter(fn ($l) => $l['is_active'] || $l['quantity'] > 0)->values();
        }
        $row['movements'] = StockMovement::where('seller_profile_id', $store->id)->where('product_id', $product->id)
            ->with(['warehouse:id,name', 'variant:id,size,color,material', 'order:id,number', 'user:id,name'])
            ->latest('id')->limit(20)->get()->map(fn ($m) => self::movement($m));

        return response()->json(['data' => $row]);
    }

    /**
     * SKU, cost price and reorder levels of a listing, or of one option (variant_id).
     */
    public function update(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $money = 'regex:/^\d{1,10}(\.\d{1,2})?$/';
        $data = $request->validate([
            'variant_id' => ['nullable', 'integer'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/'],
            'cost_price' => ['sometimes', 'nullable', 'string', $money],
            'min_stock_level' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'reorder_quantity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'supplier_id' => ['sometimes', 'nullable', 'integer'],
        ], [
            'sku.regex' => 'Use letters, numbers, dots, dashes or slashes, starting with a letter or number.',
            'cost_price.regex' => 'Enter the cost in birr, for example 8000 or 8000.50.',
        ]);

        DB::transaction(function () use ($store, $id, $data, $audit) {
            $product = $this->inventory->lock($store->id, $id);
            $variant = $this->inventory->variant($product, $data['variant_id'] ?? null);
            if (! $variant && $this->inventory->hasVariants($product) && ! empty(array_intersect(array_keys($data), ['sku', 'min_stock_level', 'reorder_quantity']))) {
                // Cost price may be set on the listing as a fallback for options without their own.
                throw ValidationException::withMessages(['variant_id' => 'This listing has options. Set the SKU and stock levels on each option.']);
            }
            if (array_key_exists('supplier_id', $data)) {
                // The usual supplier belongs to the listing, whichever option the form was on.
                $product->supplier_id = $data['supplier_id'] === null ? null
                    : (Supplier::where('seller_profile_id', $store->id)->find($data['supplier_id'])?->id
                        ?? throw ValidationException::withMessages(['supplier_id' => 'Choose one of your suppliers.']));
                if ($product->isDirty('supplier_id')) {
                    $product->save();
                    $audit->log('inventory.item_updated', $product, ['fields' => ['supplier_id']]);
                }
            }
            $target = $variant ?? $product;
            if (array_key_exists('sku', $data)) {
                $sku = $data['sku'] === null || trim($data['sku']) === '' ? null : trim($data['sku']);
                if ($sku !== null) {
                    $this->assertSkuFree($store->id, $sku, $product->id, $variant?->id);
                }
                $target->sku = $sku;
            }
            if (array_key_exists('cost_price', $data)) {
                $target->cost_price_santim = $data['cost_price'] === null || $data['cost_price'] === '' ? null : Money::toSantim($data['cost_price']);
            }
            foreach (['min_stock_level', 'reorder_quantity'] as $field) {
                if (array_key_exists($field, $data)) {
                    $target->{$field} = $data[$field];
                }
            }
            $changed = $target->getDirty();
            if ($changed) {
                // Plain save: these fields are private to the store and never send a listing back to review.
                $target->save();
                $audit->log('inventory.item_updated', $product, ['variant_id' => $variant?->id, 'fields' => array_keys($changed)]);
            }
        });

        return $this->show($request, $id);
    }

    /**
     * Record a stock count, damage, loss, return or found units at one location.
     */
    public function adjust(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);
        $data = $request->validate([
            'variant_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'type' => ['required', Rule::in(array_keys(StockMovement::MANUAL))],
            'quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:255'],
            // The available units the store saw; a mismatch means an order came in meanwhile.
            'expected_available' => ['nullable', 'integer', 'min:0'],
        ]);
        if (in_array($data['type'], ['damaged', 'lost'], true) && blank($data['note'] ?? null)) {
            throw ValidationException::withMessages(['note' => 'Say what happened, for your records.']);
        }

        DB::transaction(function () use ($request, $store, $id, $data, $audit) {
            // Taken before the listing, so a location cannot be closed while units are added to it.
            StoreLock::take($store->id);
            $product = $this->inventory->lock($store->id, $id);
            $variant = $this->inventory->variant($product, $data['variant_id'] ?? null);
            $warehouse = empty($data['warehouse_id'])
                ? $this->inventory->defaultWarehouse($store->id)
                : Warehouse::where('seller_profile_id', $store->id)->findOrFail($data['warehouse_id']);
            $now = $this->inventory->available($product, $variant);
            if (isset($data['expected_available']) && (int) $data['expected_available'] !== $now) {
                throw ValidationException::withMessages(['quantity' => "Stock changed since you opened this page (an order or another change). It is now {$now}. Check and try again."]);
            }
            $movement = $this->inventory->adjust($product, $variant, $data['type'], (int) $data['quantity'], $warehouse, $data['note'] ?? null, $request->user());
            $audit->log('inventory.adjusted', $product, [
                'variant_id' => $variant?->id, 'warehouse_id' => $warehouse->id, 'type' => $data['type'],
                'change' => $movement->quantity, 'movement_id' => $movement->id,
            ]);
        });

        return $this->show($request, $id);
    }

    /**
     * Give the listing and its options an SKU where they have none: PREFIX-<listing id>[-<n>].
     */
    public function generateSku(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $store = $this->currentStore($request, writable: true);

        DB::transaction(function () use ($store, $id, $audit) {
            $product = $this->inventory->lock($store->id, $id);
            $prefix = SellerErpSetting::forSeller($store->id)->sku_prefix ?: 'BF';
            $base = strtoupper($prefix).'-'.str_pad((string) $product->id, 5, '0', STR_PAD_LEFT);
            $variants = ProductVariant::where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->get();
            $given = [];
            $targets = $variants->isEmpty() ? [[$product, $base]] : $variants->values()->map(fn ($v, $i) => [$v, $base.'-'.($i + 1)])->all();
            foreach ($targets as [$target, $sku]) {
                if ($target->sku) {
                    continue;
                }
                for ($n = 0, $candidate = $sku; ! $this->skuFree($store->id, $candidate, $product->id, $target instanceof ProductVariant ? $target->id : null); $n++) {
                    $candidate = $sku.'-'.chr(65 + $n % 26).($n >= 26 ? intdiv($n, 26) : '');
                }
                $target->sku = $candidate;
                $target->save();
                $given[] = $candidate;
            }
            if ($given) {
                $audit->log('inventory.sku_generated', $product, ['skus' => $given]);
            }
        });

        return $this->show($request, $id);
    }

    /**
     * Stock items for pickers (purchase orders, transfers): listings kept in stock and their options,
     * with units available in total and, given a location, there.
     */
    public function pick(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            // A sale can also be of a made-to-order listing; buying and moving stock cannot.
            'for' => ['nullable', Rule::in(['stock', 'sale'])],
        ]);
        $warehouse = isset($data['warehouse_id']) ? Warehouse::where('seller_profile_id', $store->id)->findOrFail($data['warehouse_id']) : null;
        $products = $this->query($store->id, ['q' => $data['q'] ?? null, 'sort' => 'name'])
            ->when(($data['for'] ?? 'stock') === 'stock', fn ($q) => $q->where('products.stock_type', 'in_stock'))
            // The supplier's usual listings first.
            ->when($data['supplier_id'] ?? null, fn ($q, $s) => $q->reorder()
                ->orderByRaw('CASE WHEN products.supplier_id = ? THEN 0 ELSE 1 END', [$s])->orderBy('products.name')->orderBy('products.id'))
            ->with(['variants', 'primaryImage'])->limit(30)->get();

        $items = [];
        foreach ($products as $p) {
            foreach ($p->variants->isEmpty() ? [null] : $p->variants->all() as $v) {
                $items[] = [
                    'product_id' => $p->id, 'variant_id' => $v?->id, 'name' => $p->name, 'label' => $v?->label() ?: null,
                    'sku' => $v ? $v->sku : $p->sku, 'image' => $p->primaryImage?->thumbUrl(),
                    'available' => $this->inventory->available($p, $v),
                    'at_location' => $warehouse ? $this->inventory->quantityAt($p, $v, $warehouse) : null,
                    'cost_price_santim' => InventoryService::unitCost($p, $v),
                    'price_santim' => $v ? $v->priceSantim($p) : (int) $p->price_santim,
                    'stock_type' => $p->stock_type->value,
                    'supplier_id' => $p->supplier_id,
                ];
            }
        }

        return response()->json(['data' => array_slice($items, 0, 60)]);
    }

    /**
     * Stock history across the store, newest first, with filters.
     */
    public function movements(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $data = $this->movementFilters($request) + $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $page = $this->movementQuery($store->id, $data)->latest('id')->paginate(30)->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($m) => self::movement($m, withProduct: true))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Stock history with the same filters, oldest first, for a spreadsheet. */
    public function exportMovements(Request $request, AuditLogger $audit): StreamedResponse
    {
        $store = $this->currentStore($request);
        $data = $this->movementFilters($request);
        $audit->log('inventory.movements_exported', $store, array_filter($data));
        $tz = config('marketplace.timezone');
        $text = ReportController::cell(...);

        return response()->streamDownload(function () use ($store, $data, $tz, $text) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date_time', 'listing', 'option', 'location', 'type', 'change', 'available_after', 'at_location_after', 'order', 'by', 'note'], escape: '');
            $this->movementQuery($store->id, $data)->chunkById(500, function ($rows) use ($out, $tz, $text) {
                foreach ($rows as $m) {
                    $r = self::movement($m, withProduct: true);
                    fputcsv($out, [
                        $m->created_at?->setTimezone($tz)->format('Y-m-d H:i'), $text($r['product']['name'] ?? ''), $text($r['variant']), $text($r['warehouse']),
                        $r['type'], $r['quantity'], $r['available_after'], $r['location_after'], $r['order']['number'] ?? '', $text($r['by']), $text($r['note']),
                    ], escape: '');
                }
            });
            fclose($out);
        }, 'stock-history-'.now($tz)->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function movementFilters(Request $request): array
    {
        return $request->validate([
            'product_id' => ['nullable', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(StockMovement::TYPES)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
    }

    private function movementQuery(int $storeId, array $data): Builder
    {
        $tz = config('marketplace.timezone');

        return StockMovement::where('seller_profile_id', $storeId)
            ->when($data['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($data['variant_id'] ?? null, fn ($q, $v) => $q->where('variant_id', $v))
            ->when($data['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($data['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', CarbonImmutable::createFromFormat('Y-m-d', $v, $tz)->startOfDay()->utc()))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', CarbonImmutable::createFromFormat('Y-m-d', $v, $tz)->endOfDay()->utc()))
            ->with(['product:id,name,slug', 'warehouse:id,name', 'variant:id,size,color,material', 'order:id,number', 'user:id,name']);
    }

    /**
     * The inventory list as CSV, one row per stock item, with the same filters as the list.
     */
    public function export(Request $request, AuditLogger $audit): StreamedResponse
    {
        $store = $this->currentStore($request);
        $filters = $this->filters($request);
        $audit->log('inventory.exported', $store);

        return response()->streamDownload(function () use ($store, $filters) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['listing', 'option', 'sku', 'listing_status', 'stock_type', 'available', 'held_for_orders', 'sold', 'low_stock_level', 'state', 'price_etb', 'cost_etb', 'value_at_cost_etb', 'value_at_price_etb'], escape: '');
            $this->query($store->id, $filters)->with('variants')->chunk(200, function ($products) use ($out) {
                $figures = new StockFigures($products, $this->inventory);
                foreach ($products as $p) {
                    foreach ($figures->items($p) as $it) {
                        fputcsv($out, array_map(ReportController::cell(...), [
                            $p->name, $it['label'], $it['sku'], $p->status->value, $p->stock_type->value,
                            $it['tracked'] ? $it['available'] : '', $it['held'], $it['sold'], $it['tracked'] ? $it['low_level'] : '', $it['state'],
                            Money::toBirr($it['price_santim']), Money::toBirr($it['cost_price_santim']),
                            Money::toBirr($it['value_cost_santim']), $it['tracked'] ? Money::toBirr($it['value_price_santim']) : '',
                        ]), escape: '');
                    }
                }
            });
            fclose($out);
        }, 'inventory-'.now(config('marketplace.timezone'))->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'stock' => ['nullable', Rule::in(['low', 'out', 'ok', 'made_to_order'])],
            'status' => ['nullable', Rule::enum(ProductStatus::class)],
            'category_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    /**
     * Listings of the store matching the filters. Stock filters match a listing when the listing
     * itself, or any of its options, is in that state.
     */
    private function query(int $storeId, array $filters): Builder
    {
        $level = SellerErpSetting::forSeller($storeId)->low_stock_level;
        $q = Product::where('products.seller_profile_id', $storeId);

        if ($term = trim((string) ($filters['q'] ?? ''))) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(mb_substr($term, 0, 100))).'%';
            $q->where(fn ($w) => $w->whereRaw("LOWER(products.name) LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("LOWER(products.sku) LIKE ? ESCAPE '!'", [$like])
                ->orWhereHas('variants', fn ($v) => $v->whereRaw("LOWER(sku) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(color) LIKE ? ESCAPE '!'", [$like])->orWhereRaw("LOWER(size) LIKE ? ESCAPE '!'", [$like])));
        }
        $q->when($filters['status'] ?? null, fn ($w, $s) => $w->where('products.status', $s))
            ->when($filters['category_id'] ?? null, fn ($w, $c) => $w->where('products.category_id', $c))
            ->when($filters['supplier_id'] ?? null, fn ($w, $c) => $w->where('products.supplier_id', $c));

        $simple = fn ($w) => $w->whereDoesntHave('variants');
        match ($filters['stock'] ?? null) {
            'made_to_order' => $q->where('products.stock_type', 'made_to_order'),
            'out' => $q->where('products.stock_type', 'in_stock')->where(fn ($w) => $w
                ->where(fn ($s) => $simple($s)->where('products.stock_quantity', 0))
                ->orWhereHas('variants', fn ($v) => $v->where('product_variants.stock_quantity', 0))),
            'low' => $q->where('products.stock_type', 'in_stock')->where(fn ($w) => $w
                ->where(fn ($s) => $simple($s)->where('products.stock_quantity', '>', 0)
                    ->whereRaw('products.stock_quantity <= COALESCE(products.min_stock_level, ?)', [$level]))
                ->orWhereHas('variants', fn ($v) => $v->where('product_variants.stock_quantity', '>', 0)
                    ->whereRaw('product_variants.stock_quantity <= COALESCE(product_variants.min_stock_level, products.min_stock_level, ?)', [$level]))),
            'ok' => $q->where('products.stock_type', 'in_stock')->where(fn ($w) => $w
                ->where(fn ($s) => $simple($s)->whereRaw('products.stock_quantity > COALESCE(products.min_stock_level, ?)', [$level]))
                ->orWhereHas('variants', fn ($v) => $v
                    ->whereRaw('product_variants.stock_quantity > COALESCE(product_variants.min_stock_level, products.min_stock_level, ?)', [$level]))),
            default => null,
        };

        $dir = ($filters['dir'] ?? null) === 'desc' ? 'desc' : 'asc';
        match ($filters['sort'] ?? 'updated') {
            'name' => $q->orderBy('products.name', $dir),
            'stock' => $q->orderBy('products.stock_quantity', $dir),
            'sku' => $q->orderByRaw('products.sku IS NULL')->orderBy('products.sku', $dir),
            'price' => $q->orderBy('products.price_santim', $dir),
            default => $q->orderBy('products.updated_at', ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc'),
        };

        return $q->orderBy('products.id');
    }

    /** @return array<string, mixed> */
    private function row(Product $p, StockFigures $figures): array
    {
        $items = $figures->items($p);
        $states = array_column($items, 'state');

        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'sku' => $p->sku,
            'status' => $p->status->value,
            'stock_type' => $p->stock_type->value,
            'category' => $p->category?->name,
            'supplier' => $p->relationLoaded('supplier') && $p->supplier ? ['id' => $p->supplier->id, 'name' => $p->supplier->name] : null,
            'image' => $p->primaryImage?->thumbUrl(),
            'price_santim' => $p->price_santim,
            'cost_price_santim' => $p->cost_price_santim,
            'has_variants' => $p->variants->isNotEmpty(),
            'available' => array_sum(array_column($items, 'available')),
            'held' => array_sum(array_column($items, 'held')),
            'sold' => array_sum(array_column($items, 'sold')),
            'state' => in_array('out', $states, true) ? 'out' : (in_array('low', $states, true) ? 'low' : ($p->stock_type->value === 'made_to_order' ? 'made_to_order' : 'ok')),
            'value_cost_santim' => array_sum(array_map(fn ($i) => (int) $i['value_cost_santim'], $items)),
            'missing_cost' => count(array_filter($items, fn ($i) => $i['tracked'] && $i['cost_price_santim'] === null)),
            'value_price_santim' => array_sum(array_column($items, 'value_price_santim')),
            'items' => $items,
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function movement(StockMovement $m, bool $withProduct = false): array
    {
        return [
            'id' => $m->id,
            'type' => $m->type,
            'quantity' => $m->quantity,
            'available_after' => $m->available_after,
            'location_after' => $m->location_after,
            'warehouse' => $m->warehouse?->name,
            'variant' => $m->variant?->label(),
            'order' => $m->order ? ['id' => $m->order->id, 'number' => $m->order->number] : null,
            'reference_type' => $m->reference_type,
            'reference_id' => $m->reference_id,
            'note' => $m->note,
            // Order lines are done by the customer; the store sees the order, not who placed it.
            'by' => str_starts_with($m->type, 'order_') ? null : $m->user?->name,
            'created_at' => $m->created_at?->toIso8601String(),
        ] + ($withProduct ? ['product' => $m->product ? ['id' => $m->product->id, 'name' => $m->product->name] : null] : []);
    }

    private function skuFree(int $storeId, string $sku, int $productId, ?int $variantId): bool
    {
        $lower = mb_strtolower($sku);
        $listing = Product::withTrashed()->where('seller_profile_id', $storeId)->whereRaw('LOWER(sku) = ?', [$lower])
            ->when(! $variantId, fn ($q) => $q->whereKeyNot($productId))->exists();
        $option = ProductVariant::withTrashed()->where('seller_profile_id', $storeId)->whereRaw('LOWER(sku) = ?', [$lower])
            ->when($variantId, fn ($q) => $q->whereKeyNot($variantId))->exists();

        return ! $listing && ! $option;
    }

    private function assertSkuFree(int $storeId, string $sku, int $productId, ?int $variantId): void
    {
        if (! $this->skuFree($storeId, $sku, $productId, $variantId)) {
            throw ValidationException::withMessages(['sku' => 'Another listing or option of your store (possibly a deleted one) already uses this SKU.']);
        }
    }
}
