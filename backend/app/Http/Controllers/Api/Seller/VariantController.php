<?php

namespace App\Http\Controllers\Api\Seller;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\StockType;
use App\Http\Controllers\Api\Seller\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Http\Resources\ManagedProductResource;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Options of a listing (size, color, material), each with its own stock and optional price.
 * Adding the first option moves the listing's stock to it; removing one needs its stock at 0.
 */
class VariantController extends Controller
{
    use ResolvesStore;

    public const MAX_PER_LISTING = 30;

    public function __construct(private readonly InventoryService $inventory) {}

    public function store(Request $request, int $id, AuditLogger $audit): ManagedProductResource
    {
        $store = $this->currentStore($request, writable: true);
        $data = $this->validated($request);

        return DB::transaction(function () use ($request, $store, $id, $data, $audit) {
            $product = $this->editable($store->id, $id);
            $first = ! $this->inventory->hasVariants($product);
            if (! $first && ProductVariant::where('product_id', $product->id)->count() >= self::MAX_PER_LISTING) {
                throw ValidationException::withMessages(['size' => 'A listing can have up to '.self::MAX_PER_LISTING.' options.']);
            }
            $this->assertUnique($product, $data);

            $variant = new ProductVariant;
            $variant->product_id = $product->id;
            $variant->seller_profile_id = $product->seller_profile_id;
            $variant->sort_order = (int) ProductVariant::where('product_id', $product->id)->max('sort_order') + 1;
            $this->fill($variant, $data);
            $variant->stock_quantity = 0;
            $variant->save();

            if ($first) {
                $this->inventory->moveToFirstVariant($product, $variant, $request->user());
            }
            if ($product->stock_type === StockType::InStock && array_key_exists('stock_quantity', $data)) {
                $this->inventory->setTotal($product, $variant, (int) $data['stock_quantity'], $request->user(), $first ? 'edit' : 'opening');
            }
            $audit->log('product.variant_added', $product, ['variant_id' => $variant->id, 'label' => $variant->label()]);

            return $this->resource($product);
        });
    }

    public function update(Request $request, int $id, int $variantId, AuditLogger $audit): ManagedProductResource
    {
        $store = $this->currentStore($request, writable: true);
        $data = $this->validated($request);

        return DB::transaction(function () use ($request, $store, $id, $variantId, $data, $audit) {
            $product = $this->editable($store->id, $id);
            $variant = $this->inventory->variant($product, $variantId);
            $this->assertUnique($product, $data, $variant->id);
            $this->fill($variant, $data);
            $changed = array_keys($variant->getDirty());
            $variant->save();

            if ($product->stock_type === StockType::InStock && array_key_exists('stock_quantity', $data)
                && (int) $data['stock_quantity'] !== $variant->stock_quantity) {
                $expected = $data['stock_expected'] ?? null;
                if ($expected !== null && (int) $expected !== $variant->stock_quantity) {
                    throw ValidationException::withMessages(['stock_quantity' => "Stock changed while you were editing (an order or another change). It is now {$variant->stock_quantity}. Check the number and save again."]);
                }
                $this->inventory->setTotal($product, $variant, (int) $data['stock_quantity'], $request->user());
                $changed[] = 'stock_quantity';
            }
            if ($changed) {
                $audit->log('product.variant_updated', $product, ['variant_id' => $variant->id, 'fields' => $changed]);
            }

            return $this->resource($product);
        });
    }

    public function destroy(Request $request, int $id, int $variantId, AuditLogger $audit): ManagedProductResource
    {
        $store = $this->currentStore($request, writable: true);

        return DB::transaction(function () use ($store, $id, $variantId, $audit) {
            $product = $this->editable($store->id, $id);
            $variant = $this->inventory->variant($product, $variantId);
            if ($variant->stock_quantity > 0) {
                throw ValidationException::withMessages(['variant' => "This option still has {$variant->stock_quantity} in stock. Set its stock to 0 first (or record why in Inventory)."]);
            }
            // A cancelled order gives its units back to the option, so it must stay until those orders end.
            $held = OrderItem::where('variant_id', $variant->id)->where('reserved_quantity', '>', 0)
                ->whereHas('order', fn ($q) => $q->whereNotIn('status', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value]))
                ->sum('reserved_quantity');
            if ($held > 0) {
                throw ValidationException::withMessages(['variant' => "{$held} of this option are in open orders. Remove it once those orders are delivered or cancelled."]);
            }
            $this->inventory->assertNothingOnTheWay($product, $variant, 'variant', 'This option cannot be removed yet');
            $variant->delete();
            $audit->log('product.variant_removed', $product, ['variant_id' => $variant->id, 'label' => $variant->label()]);

            return $this->resource($product);
        });
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $money = 'regex:/^\d{1,10}(\.\d{1,2})?$/';
        $data = $request->validate([
            'size' => ['nullable', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:60'],
            'material' => ['nullable', 'string', 'max:100'],
            'price' => ['nullable', 'string', $money],
            'stock_quantity' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'stock_expected' => ['nullable', 'integer', 'min:0'],
        ], ['price.regex' => 'Enter the price in birr, for example 12500 or 12500.50.']);

        foreach (['size', 'color', 'material'] as $field) {
            $data[$field] = isset($data[$field]) ? (trim($data[$field]) ?: null) : null;
        }
        if (! $data['size'] && ! $data['color'] && ! $data['material']) {
            throw ValidationException::withMessages(['size' => 'Enter a size, color or material for this option.']);
        }
        if (isset($data['price']) && $data['price'] !== '' && Money::toSantim($data['price']) < 100) {
            throw ValidationException::withMessages(['price' => 'The price must be at least 1 birr.']);
        }

        return $data;
    }

    private function fill(ProductVariant $variant, array $data): void
    {
        $variant->size = $data['size'];
        $variant->color = $data['color'];
        $variant->material = $data['material'];
        $variant->price_santim = isset($data['price']) && $data['price'] !== '' ? Money::toSantim($data['price']) : null;
    }

    private function assertUnique(Product $product, array $data, ?int $exceptId = null): void
    {
        $key = fn ($v) => implode('|', array_map(fn ($f) => mb_strtolower(trim((string) $v[$f])), ['size', 'color', 'material']));
        $mine = $key($data);
        $taken = ProductVariant::where('product_id', $product->id)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->get(['size', 'color', 'material'])->contains(fn ($v) => $key($v->only(['size', 'color', 'material'])) === $mine);
        if ($taken) {
            throw ValidationException::withMessages(['size' => 'This listing already has an option with the same size, color and material.']);
        }
    }

    private function editable(int $storeId, int $productId): Product
    {
        $product = $this->inventory->lock($storeId, $productId);
        if ($product->status === ProductStatus::Archived) {
            throw ValidationException::withMessages(['status' => 'Restore this listing before editing it.']);
        }

        return $product;
    }

    private function resource(Product $product): ManagedProductResource
    {
        return new ManagedProductResource($product->fresh(['images', 'category:id,name,slug', 'variants']));
    }
}
