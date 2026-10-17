<?php

namespace App\Services;

use App\Enums\ProductStatus;
use App\Enums\StockType;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrderItem;
use App\Models\SellerErpSetting;
use App\Models\StockMovement;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of available stock.
 *
 * A stock item is a listing without options, or one option (variant) of a listing. Its available
 * units are products.stock_quantity or product_variants.stock_quantity; with options, the
 * listing's number is their total. warehouse_stock holds the units at non-default locations and
 * the default location has the rest. Every change writes a stock_movements row.
 *
 * Callers lock the listing row first (lock(), or lockForUpdate in id order). That one lock guards
 * the stock of the listing and all its options at every location, so no other row needs a lock.
 */
class InventoryService
{
    /** @var array<int, SellerErpSetting> */
    private array $settings = [];

    /** @var array<int, Warehouse> */
    private array $defaults = [];

    public function __construct(private readonly Alerts $alerts) {}

    /**
     * Lock one of this store's listings for a stock change. Another store's id answers 404.
     */
    public function lock(int $sellerId, int $productId): Product
    {
        return Product::where('seller_profile_id', $sellerId)->whereKey($productId)->lockForUpdate()->firstOrFail();
    }

    /** One of the listing's current options; a removed or foreign one answers 404. */
    public function variant(Product $product, ?int $variantId): ?ProductVariant
    {
        return $variantId ? ProductVariant::where('product_id', $product->id)->findOrFail($variantId) : null;
    }

    public function hasVariants(Product $product): bool
    {
        return ProductVariant::where('product_id', $product->id)->exists();
    }

    /**
     * Where arriving units go: the line's option, or for a line written before the listing had
     * options, its first option (as cancelled orders do). A removed option cannot take stock.
     */
    public function inboundVariant(Product $product, ?int $variantId, string $what): ?ProductVariant
    {
        if ($variantId) {
            return ProductVariant::where('product_id', $product->id)->find($variantId)
                ?? throw ValidationException::withMessages(['items' => "An option of {$what} was removed, so it cannot take stock."]);
        }

        return ProductVariant::where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->first();
    }

    /**
     * Refuse a change that would strand units still on their way to the store: ordered from a
     * supplier and not received yet, or sent between locations and not arrived. With $variant,
     * only that option's units count.
     */
    public function assertNothingOnTheWay(Product $product, ?ProductVariant $variant, string $field, string $what): void
    {
        // Lines written before the listing had options arrive as its first option, so they count for that one too.
        $first = $variant ? ProductVariant::where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->value('id') : null;
        $scope = fn ($q) => $q->where('product_id', $product->id)->when($variant, fn ($q) => $q->where(fn ($w) => $w
            ->where('variant_id', $variant->id)->when($first === $variant->id, fn ($w) => $w->orWhereNull('variant_id'))));
        $line = PurchaseOrderItem::where($scope)->whereColumn('received_quantity', '<', 'quantity')
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['ordered', 'partially_received']))
            ->with('order:id,number')->first();
        if ($line) {
            throw ValidationException::withMessages([$field => "{$what}: purchase order {$line->order->number} is still waiting for units of it. Receive them, or close or cancel that order first."]);
        }
        $moving = StockTransferItem::where($scope)->whereHas('transfer', fn ($q) => $q->where('status', 'in_transit'))
            ->with('transfer:id,number')->first();
        if ($moving) {
            throw ValidationException::withMessages([$field => "{$what}: transfer {$moving->transfer->number} is moving units of it between your locations. Receive or cancel that transfer first."]);
        }
    }

    public function defaultWarehouse(int $sellerId): Warehouse
    {
        if (isset($this->defaults[$sellerId])) {
            return $this->defaults[$sellerId];
        }
        $warehouse = Warehouse::where('default_for', $sellerId)->first();
        // Stores get one when they are created; this covers a store made some other way.
        foreach (['Main store', 'Main store (default)'] as $name) {
            if ($warehouse) {
                break;
            }
            Warehouse::insertOrIgnore([
                'seller_profile_id' => $sellerId, 'name' => $name, 'is_default' => true, 'default_for' => $sellerId,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $warehouse = Warehouse::where('default_for', $sellerId)->first();
        }
        if (! $warehouse) {
            throw new \RuntimeException("Could not create the default location for store {$sellerId}.");
        }

        return $this->defaults[$sellerId] = $warehouse;
    }

    /** Available units of the item at all locations. */
    public function available(Product $product, ?ProductVariant $variant): int
    {
        return (int) ($variant ? $variant->stock_quantity : $product->stock_quantity);
    }

    /**
     * Available units per location, default first: [[warehouse, quantity], ...]. Locations holding
     * nothing are left out unless $all is true.
     *
     * @return list<array{0: Warehouse, 1: int}>
     */
    public function locations(Product $product, ?ProductVariant $variant = null, bool $all = false): array
    {
        $default = $this->defaultWarehouse($product->seller_profile_id);
        $rows = $this->rows($product, $variant)->where('warehouse_id', '!=', $default->id)->pluck('quantity', 'warehouse_id');
        $others = Warehouse::where('seller_profile_id', $product->seller_profile_id)->where('id', '!=', $default->id)
            ->when(! $all, fn ($q) => $q->whereIn('id', $rows->filter()->keys()))
            ->orderBy('name')->get();

        $list = [[$default, max(0, $this->available($product, $variant) - (int) $rows->sum())]];
        foreach ($others as $w) {
            $list[] = [$w, (int) ($rows[$w->id] ?? 0)];
        }

        return $all ? $list : array_values(array_filter($list, fn ($l) => $l[1] > 0 || $l[0]->is_default));
    }

    public function quantityAt(Product $product, ?ProductVariant $variant, Warehouse $warehouse): int
    {
        if ($warehouse->is_default) {
            return max(0, $this->available($product, $variant) - $this->elsewhere($product, $variant, $warehouse->id));
        }

        return (int) $this->rows($product, $variant)->where('warehouse_id', $warehouse->id)->value('quantity');
    }

    /**
     * Take units for a new order: from the default location first, then the others. The listing
     * row is locked and the caller already checked that enough is available.
     */
    public function reserve(Product $product, ?ProductVariant $variant, int $quantity, Order $order, ?User $actor): void
    {
        $before = $this->available($product, $variant);
        if ($quantity < 1 || $before < $quantity) {
            throw ValidationException::withMessages(['cart' => "Only {$before} of {$product->name} left."]);
        }

        $left = $quantity;
        foreach ($this->locations($product, $variant) as [$warehouse, $here]) {
            $take = min($left, $here);
            if ($take > 0) {
                $this->change($product, $variant, $warehouse, -$take, 'order_reserved', $actor, order: $order);
                $left -= $take;
            }
            if ($left === 0) {
                break;
            }
        }

        $this->afterDecrease($product, $variant, $before);
    }

    /**
     * Give back what a cancelled order took, to the locations it came from (the default one when a
     * location was closed since, or for orders placed before locations were tracked).
     */
    public function release(Order $order, int $productId, ?int $variantId, int $quantity, ?User $actor): void
    {
        $product = Product::withTrashed()->lockForUpdate()->find($productId);
        if (! $product || $quantity < 1) {
            return;
        }
        // A removed option still takes its units back, so its history and totals stay right.
        $variant = $variantId ? ProductVariant::withTrashed()->where('product_id', $productId)->find($variantId) : null;
        if ($variantId && ! $variant) {
            return;
        }
        // Ordered before the listing had options: the units went to its first option, so they come back there.
        $first = ! $variantId ? ProductVariant::where('product_id', $productId)->orderBy('sort_order')->orderBy('id')->first() : null;
        if ($first) {
            $this->change($product, $first, $this->defaultWarehouse($product->seller_profile_id), $quantity, 'order_released', $actor, order: $order,
                note: 'From an order placed before the listing had options');

            return;
        }

        $out = StockMovement::where('order_id', $order->id)->where('product_id', $productId)
            ->when($variantId, fn ($q) => $q->where('variant_id', $variantId), fn ($q) => $q->whereNull('variant_id'))
            ->whereIn('type', ['order_reserved', 'order_released'])
            ->selectRaw('warehouse_id, sum(quantity) as net')->groupBy('warehouse_id')->orderBy('warehouse_id')
            ->pluck('net', 'warehouse_id');
        $default = $this->defaultWarehouse($product->seller_profile_id);
        $open = Warehouse::whereIn('id', $out->keys())->where('is_active', true)->get()->keyBy('id');

        $left = $quantity;
        foreach ($out as $warehouseId => $net) {
            $back = min($left, max(0, -(int) $net));
            if ($back > 0) {
                $this->change($product, $variant, $open[$warehouseId] ?? $default, $back, 'order_released', $actor, order: $order);
                $left -= $back;
            }
        }
        if ($left > 0) {
            $this->change($product, $variant, $default, $left, 'order_released', $actor, order: $order);
        }
    }

    /**
     * Set an item's total available from a form (the listing form, or an option's stock box). The
     * difference goes to the default location; units counted at other locations must be moved or
     * adjusted there first.
     */
    public function setTotal(Product $product, ?ProductVariant $variant, int $quantity, ?User $actor, string $type = 'edit'): void
    {
        $before = $this->available($product, $variant);
        if ($quantity === $before) {
            return;
        }
        if (! $variant && $this->hasVariants($product)) {
            throw ValidationException::withMessages(['stock_quantity' => 'This listing has options. Set the stock of each option instead.']);
        }
        $default = $this->defaultWarehouse($product->seller_profile_id);
        $elsewhere = $this->elsewhere($product, $variant, $default->id);
        if ($quantity < $elsewhere) {
            throw ValidationException::withMessages(['stock_quantity' => "{$elsewhere} units are counted at your other locations. Set at least {$elsewhere}, or adjust those locations in Inventory first."]);
        }

        $note = match ($type) {
            'opening' => $variant ? 'Stock when the option was added' : 'Stock when the listing was created',
            default => $variant ? 'Changed in the option list' : 'Changed on the listing form',
        };
        $this->change($product, $variant, $default, $quantity - $before, $type, $actor, note: $note);
        $this->afterDecrease($product, $variant, $before);
    }

    /**
     * A listing gets its first option: the units it had move to that option, location by location,
     * so nothing is lost and both histories add up.
     */
    public function moveToFirstVariant(Product $product, ProductVariant $variant, ?User $actor): void
    {
        $label = $variant->label() ?: 'the first option';
        foreach ($this->locations($product, null) as [$warehouse, $here]) {
            if ($here > 0) {
                $this->change($product, null, $warehouse, -$here, 'edit', $actor, note: "Moved to {$label}");
                $this->change($product, $variant, $warehouse, $here, 'opening', $actor, note: 'Moved from the listing');
            }
        }
    }

    /**
     * A stock adjustment by the store. For a count, $quantity is the number counted at the location;
     * otherwise it is how many units were damaged, lost, returned or found.
     */
    public function adjust(Product $product, ?ProductVariant $variant, string $type, int $quantity, Warehouse $warehouse, ?string $note, User $actor): StockMovement
    {
        if (! array_key_exists($type, StockMovement::MANUAL)) {
            throw ValidationException::withMessages(['type' => 'Choose what happened to the stock.']);
        }
        if ($product->stock_type !== StockType::InStock) {
            throw ValidationException::withMessages(['type' => 'Made-to-order listings do not keep stock.']);
        }
        if (! $variant && $this->hasVariants($product)) {
            throw ValidationException::withMessages(['variant_id' => 'Choose which option this is for.']);
        }
        if (! $warehouse->is_active) {
            throw ValidationException::withMessages(['warehouse_id' => 'This location is closed.']);
        }
        if ($type !== 'count' && $quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Enter at least 1.']);
        }

        $here = $this->quantityAt($product, $variant, $warehouse);
        $delta = match (StockMovement::MANUAL[$type]) {
            0 => $quantity - $here,
            -1 => -$quantity,
            1 => $quantity,
        };
        if ($here + $delta < 0) {
            throw ValidationException::withMessages(['quantity' => "Only {$here} available at {$warehouse->name}."]);
        }

        $before = $this->available($product, $variant);
        $movement = $this->change($product, $variant, $warehouse, $delta, $type, $actor, note: $note);
        $this->afterDecrease($product, $variant, $before);

        return $movement;
    }

    /** The low-stock level that applies: the option's, the listing's, or the store's default. */
    public function lowLevel(Product $product, ?ProductVariant $variant = null): int
    {
        return $variant?->min_stock_level ?? $product->min_stock_level ?? $this->settings($product->seller_profile_id)->low_stock_level;
    }

    /** Cost per unit for profit figures: the option's, else the listing's. Null when unknown. */
    public static function unitCost(Product $product, ?ProductVariant $variant): ?int
    {
        return $variant?->cost_price_santim ?? $product->cost_price_santim;
    }

    /**
     * Apply one signed change at one location and record it. Callers validate first; the checks here
     * are a last line of defence against going below zero.
     *
     * @param  array{type?: string, id?: int}  $reference
     */
    public function change(Product $product, ?ProductVariant $variant, Warehouse $warehouse, int $delta, string $type, ?User $actor, ?Order $order = null, array $reference = [], ?string $note = null): StockMovement
    {
        if ($warehouse->seller_profile_id !== $product->seller_profile_id || ($variant && $variant->product_id !== $product->id)) {
            throw new \LogicException('Stock location, listing and option must belong together.');
        }

        $item = $this->available($product, $variant) + $delta;
        $listing = (int) $product->stock_quantity + $delta;
        $row = null;
        if ($warehouse->is_default) {
            $here = $item - $this->elsewhere($product, $variant, $warehouse->id);
        } else {
            $row = $this->rows($product, $variant)->where('warehouse_id', $warehouse->id)->first();
            if (! $row) {
                $row = new WarehouseStock;
                $row->warehouse_id = $warehouse->id;
                $row->product_id = $product->id;
                $row->variant_id = $variant?->id;
                $row->quantity = 0;
            }
            $here = (int) $row->quantity + $delta;
        }
        if ($item < 0 || $here < 0 || $listing < 0) {
            throw ValidationException::withMessages(['quantity' => 'Not enough stock for this change.']);
        }

        if ($delta !== 0) {
            if ($row) {
                $row->quantity = $here;
                $row->save();
            }
            if ($variant) {
                ProductVariant::withTrashed()->whereKey($variant->id)->update(['stock_quantity' => $item, 'updated_at' => now()]);
                $variant->forceFill(['stock_quantity' => $item])->syncOriginalAttribute('stock_quantity');
            }
            Product::withTrashed()->whereKey($product->id)->update(['stock_quantity' => $listing, 'updated_at' => now()]);
            $product->forceFill(['stock_quantity' => $listing])->syncOriginalAttribute('stock_quantity');
        }

        $movement = new StockMovement;
        $movement->seller_profile_id = $product->seller_profile_id;
        $movement->product_id = $product->id;
        $movement->variant_id = $variant?->id;
        $movement->warehouse_id = $warehouse->id;
        $movement->type = $type;
        $movement->quantity = $delta;
        $movement->available_after = $item;
        $movement->location_after = $here;
        $movement->order_id = $order?->id;
        $movement->reference_type = $reference['type'] ?? null;
        $movement->reference_id = $reference['id'] ?? null;
        $movement->note = $note !== null ? mb_substr($note, 0, 255) : null;
        $movement->user_id = $actor?->id;
        $movement->save();

        return $movement;
    }

    /**
     * Tell the store once when an item drops to its low-stock level, and again when it runs out.
     */
    public function afterDecrease(Product $product, ?ProductVariant $variant, int $before): void
    {
        $after = $this->available($product, $variant);
        if ($after >= $before || $product->stock_type !== StockType::InStock || $product->trashed()
            || $product->status === ProductStatus::Archived || $variant?->trashed()) {
            return;
        }
        if (! $this->settings($product->seller_profile_id)->low_stock_alerts) {
            return;
        }
        $level = $this->lowLevel($product, $variant);
        if ($after === 0 || ($before > $level && $after <= $level)) {
            $this->alerts->stockLow($product, $variant, $after);
        }
    }

    private function rows(Product $product, ?ProductVariant $variant): Builder
    {
        return WarehouseStock::where('product_id', $product->id)
            ->when($variant, fn ($q) => $q->where('variant_id', $variant->id), fn ($q) => $q->whereNull('variant_id'));
    }

    private function elsewhere(Product $product, ?ProductVariant $variant, int $defaultId): int
    {
        return (int) $this->rows($product, $variant)->where('warehouse_id', '!=', $defaultId)->sum('quantity');
    }

    private function settings(int $sellerId): SellerErpSetting
    {
        return $this->settings[$sellerId] ??= SellerErpSetting::forSeller($sellerId);
    }
}
