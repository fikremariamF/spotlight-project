<?php

namespace App\Services;

use App\Enums\QuoteStatus;
use App\Enums\StockType;
use App\Models\Area;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\SellerProfile;
use Illuminate\Support\Collection;

/**
 * Prices a cart the same way for the cart page, the checkout preview and order placement,
 * so what the customer sees is exactly what is charged.
 *
 * Lines are grouped by seller; each group becomes one order. Delivery is one trip per seller:
 * the group fee is the highest line fee. A line's fee comes from its quote, else the listing,
 * else the delivery zone of the chosen sub-city. If any line has no fee, the seller confirms
 * the group fee before the order goes ahead.
 */
class CheckoutCalculator
{
    /**
     * @param  Collection<int, CartItem>  $items  with product.seller and quote.seller/product loaded
     * @param  array<int|string, string>  $fulfillment  seller_profile_id => 'delivery'|'pickup'
     */
    public function calculate(Collection $items, ?Area $area = null, array $fulfillment = []): array
    {
        $visibleProductIds = Product::visible()->whereIn('products.id', $items->pluck('product_id')->filter()->all())->pluck('products.id')->flip();
        $activeSellerIds = SellerProfile::publiclyVisible()
            ->whereIn('id', $items->map(fn (CartItem $i) => $i->product?->seller_profile_id ?? $i->quote?->seller_profile_id)->filter()->unique()->all())
            ->pluck('id')->flip();
        $zone = $area?->deliveryZone?->is_active ? $area->deliveryZone : null;

        $groups = $items->groupBy(fn (CartItem $i) => $i->product?->seller_profile_id ?? $i->quote?->seller_profile_id)
            ->map(function (Collection $lines, $sellerId) use ($visibleProductIds, $activeSellerIds, $zone, $area, $fulfillment) {
                $seller = $lines->first()->product?->seller ?? $lines->first()->quote?->seller;
                $rows = $lines->map(fn (CartItem $i) => $this->line($i, $visibleProductIds, $activeSellerIds, $zone));

                $deliveryAvailable = $rows->every(fn ($r) => $r['offers_delivery']);
                $pickupAvailable = $rows->every(fn ($r) => $r['pickup_available']);
                $mode = $fulfillment[$sellerId] ?? ($deliveryAvailable ? 'delivery' : 'pickup');
                $problem = null;
                if ($mode === 'delivery' && ! $deliveryAvailable) {
                    $problem = 'This seller does not deliver every item here. Choose pickup or remove the item.';
                } elseif ($mode === 'pickup' && ! $pickupAvailable) {
                    $problem = 'Pickup is not available for every item from this seller.';
                }

                $itemsTotal = $rows->sum('line_total_santim');
                $fee = null;
                $feeConfirmed = true;
                if ($mode === 'delivery') {
                    $fees = $rows->pluck('delivery_fee_santim');
                    $feeConfirmed = $area !== null && $fees->every(fn ($f) => $f !== null);
                    $fee = $feeConfirmed ? (int) $fees->max() : null;
                }

                return [
                    'seller' => ['id' => $seller->id, 'store_name' => $seller->store_name, 'slug' => $seller->slug, 'area' => $seller->relationLoaded('area') ? $seller->area?->name : null],
                    'fulfillment' => $mode,
                    'delivery_available' => $deliveryAvailable,
                    'pickup_available' => $pickupAvailable,
                    'lines' => $rows->map(fn ($r) => collect($r)->except(['offers_delivery', 'pickup_available', 'delivery_fee_santim'])->all())->values()->all(),
                    'items_total_santim' => $itemsTotal,
                    'delivery_fee_santim' => $fee,
                    'delivery_fee_confirmed' => $feeConfirmed,
                    'total_santim' => $itemsTotal + (int) $fee,
                    'problem' => $problem,
                ];
            })->values();

        $itemsTotal = $groups->sum('items_total_santim');
        $deliveryTotal = $groups->sum(fn ($g) => (int) $g['delivery_fee_santim']);
        $hasProblems = $groups->contains(fn ($g) => $g['problem'] !== null || collect($g['lines'])->contains(fn ($l) => $l['problem'] !== null));

        return [
            'groups' => $groups->all(),
            'line_count' => $items->count(),
            'items_total_santim' => $itemsTotal,
            'delivery_total_santim' => $deliveryTotal,
            'grand_total_santim' => $itemsTotal + $deliveryTotal,
            'delivery_to_confirm' => $groups->contains(fn ($g) => ! $g['delivery_fee_confirmed']),
            'area' => $area ? ['id' => $area->id, 'name' => $area->name] : null,
            'has_problems' => $hasProblems,
        ];
    }

    private function line(CartItem $item, Collection $visibleProductIds, Collection $activeSellerIds, $zone): array
    {
        if ($item->quote_id) {
            return $this->quoteLine($item, $activeSellerIds, $zone);
        }

        $p = $item->product;
        $problem = null;
        $max = $p->stock_type === StockType::InStock ? (int) $p->stock_quantity : (int) config('marketplace.cart.max_quantity');
        if (! $visibleProductIds->has($p->id)) {
            $problem = 'This listing is no longer available.';
        } elseif ($p->stock_type === StockType::InStock && $p->stock_quantity < 1) {
            $problem = 'Out of stock.';
        } elseif ($item->quantity > $max) {
            $problem = "Only {$max} left. Lower the quantity.";
        }

        $fee = $p->delivery_fee_santim ?? $zone?->fee_santim;

        return [
            'id' => $item->id,
            'kind' => 'product',
            'product' => [
                'id' => $p->id, 'slug' => $p->slug, 'name' => $p->name,
                'image' => $p->relationLoaded('primaryImage') ? $p->primaryImage?->thumbUrl() : null,
                'stock_type' => $p->stock_type->value, 'production_days' => $p->production_days,
            ],
            'quote' => null,
            'name' => $p->name,
            'unit_price_santim' => $p->price_santim,
            'quantity' => $item->quantity,
            'max_quantity' => max(0, min($max, (int) config('marketplace.cart.max_quantity'))),
            'line_total_santim' => $p->price_santim * $item->quantity,
            'problem' => $problem,
            'offers_delivery' => $p->offers_delivery,
            'pickup_available' => $p->pickup_available,
            'delivery_fee_santim' => $p->offers_delivery ? $fee : null,
        ];
    }

    private function quoteLine(CartItem $item, Collection $activeSellerIds, $zone): array
    {
        $q = $item->quote;
        $p = $q->product;
        $problem = null;
        if ($q->status !== QuoteStatus::Accepted) {
            $problem = 'This quote is no longer accepted.';
        } elseif ($q->orderDeadline()->isPast()) {
            $problem = 'This quote has expired. Ask the seller for a new one.';
        } elseif (! $activeSellerIds->has($q->seller_profile_id)) {
            $problem = 'This store is not taking orders right now.';
        } elseif ($q->isOrdered()) {
            $problem = 'This quote has already been ordered.';
        }

        // A custom quote is one line for its full quantity; its own delivery fee wins.
        $fee = $q->delivery_fee_santim ?? ($p?->delivery_fee_santim ?? $zone?->fee_santim);

        return [
            'id' => $item->id,
            'kind' => 'quote',
            'product' => $p ? ['id' => $p->id, 'slug' => $p->slug, 'name' => $p->name, 'image' => null, 'stock_type' => null, 'production_days' => null] : null,
            'quote' => ['id' => $q->id, 'title' => $q->title, 'quantity' => $q->quantity, 'production_days' => $q->production_days, 'conversation_id' => $q->conversation_id, 'order_by' => $q->orderDeadline()->toIso8601String()],
            'name' => $q->title,
            'unit_price_santim' => $q->price_santim,
            'quantity' => $q->quantity,
            'max_quantity' => $q->quantity,
            'line_total_santim' => $q->price_santim * $q->quantity,
            'problem' => $problem,
            'offers_delivery' => $p ? $p->offers_delivery || $q->delivery_fee_santim !== null : true,
            'pickup_available' => $p ? $p->pickup_available : true,
            'delivery_fee_santim' => $fee,
        ];
    }
}
