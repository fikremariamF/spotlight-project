<?php

namespace App\Services;

use App\Enums\QuoteStatus;
use App\Enums\StockType;
use App\Models\Address;
use App\Models\Area;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * Prices a cart the same way for the cart page, the checkout preview and order placement,
 * so what the customer sees is exactly what is charged.
 *
 * Lines are grouped by seller; each group becomes one order. Delivery is one trip per seller:
 * the group fee is the highest line fee. A line's fee comes from its quote, else the listing,
 * else the distance from the store's map pin to the customer's (when both are set), else the
 * delivery zone of the chosen sub-city. If any line has no fee, the seller confirms the group
 * fee before the order goes ahead. A trip longer than the distance limit always needs the
 * seller to confirm; the zone fee would undercharge it.
 *
 * Carrying up stairs (an address above the ground floor with no lift) adds a fee per floor to
 * every delivery, whatever set the transport fee. When the transport fee is still to be
 * confirmed, the carrying part is known now and added when the store confirms.
 */
class CheckoutCalculator
{
    /** Read once per calculation: whether listings' own delivery fees above 0 count. */
    private bool $storesSetFee = false;

    public function __construct(private readonly DistancePricing $distance) {}

    /**
     * @param  Collection<int, CartItem>  $items  with product.seller and quote.seller/product loaded
     * @param  array<int|string, string>  $fulfillment  seller_profile_id => 'delivery'|'pickup'
     * @param  Address|null  $address  the delivery address (map pin, floor, lift), when one is chosen
     */
    public function calculate(Collection $items, ?Area $area = null, array $fulfillment = [], ?Address $address = null): array
    {
        $pin = $address?->pin();
        $carry = self::carryFee($address);
        $this->storesSetFee = Setting::storesSetDeliveryFee();
        $visibleProductIds = Product::visible()->whereIn('products.id', $items->pluck('product_id')->filter()->all())->pluck('products.id')->flip();
        $activeSellerIds = SellerProfile::publiclyVisible()
            ->whereIn('id', $items->map(fn (CartItem $i) => $i->product?->seller_profile_id ?? $i->quote?->seller_profile_id)->filter()->unique()->all())
            ->pluck('id')->flip();
        $zone = $area?->deliveryZone?->is_active ? $area->deliveryZone : null;

        $groups = $items->groupBy(fn (CartItem $i) => $i->product?->seller_profile_id ?? $i->quote?->seller_profile_id)
            ->map(function (Collection $lines, $sellerId) use ($visibleProductIds, $activeSellerIds, $zone, $area, $fulfillment, $pin, $carry) {
                $seller = $lines->first()->product?->seller ?? $lines->first()->quote?->seller;
                // One trip per store, so one distance; it stands in for the zone fee on every line.
                $trip = $this->distance->quote($seller, $pin);
                $defaultFee = $trip ? $trip['fee_santim'] : $zone?->fee_santim;
                $rows = $lines->map(fn (CartItem $i) => $this->line($i, $visibleProductIds, $activeSellerIds, $defaultFee));

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
                    $fee = $feeConfirmed ? (int) $fees->max() + $carry : null;
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
                    // Included in delivery_fee_santim when that is known.
                    'carry_fee_santim' => $mode === 'delivery' && $carry > 0 ? $carry : null,
                    'delivery_distance' => $mode === 'delivery' && $trip ? [
                        'km' => round($trip['meters'] / 1000, 1),
                        'meters' => $trip['meters'],
                        'estimated' => $trip['estimated'],
                        'too_far' => $trip['too_far'],
                    ] : null,
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
            'has_pin' => $pin !== null,
            'carry' => $carry > 0 ? ['floor' => (int) $address->floor, 'fee_santim' => $carry] : null,
            'has_problems' => $hasProblems,
        ];
    }

    /** Carrying fee for an address above the ground floor without a lift. */
    public static function carryFee(?Address $address): int
    {
        if (! $address || $address->has_lift !== false || (int) $address->floor < 1) {
            return 0;
        }

        return (int) $address->floor * Setting::deliveryPerFloorSantim();
    }

    /** @param  int|null  $defaultFee  from the distance, or the zone when distance does not apply */
    private function line(CartItem $item, Collection $visibleProductIds, Collection $activeSellerIds, ?int $defaultFee): array
    {
        if ($item->quote_id) {
            return $this->quoteLine($item, $activeSellerIds, $defaultFee);
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

        $fee = $p->ownDeliveryFeeSantim($this->storesSetFee) ?? $defaultFee;

        return [
            'id' => $item->id,
            'kind' => 'product',
            'product' => [
                'id' => $p->id, 'slug' => $p->slug, 'name' => $p->name,
                'image' => $p->relationLoaded('primaryImage') ? $p->primaryImage?->thumbUrl() : null,
                'stock_type' => $p->stock_type->value, 'production_days' => $p->production_days,
                // For the "will it fit" check at checkout.
                'dimensions' => ['width_cm' => $p->width_cm, 'depth_cm' => $p->depth_cm, 'height_cm' => $p->height_cm],
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

    private function quoteLine(CartItem $item, Collection $activeSellerIds, ?int $defaultFee): array
    {
        $q = $item->quote;
        $p = $q->product;
        $problem = null;
        if ($q->status !== QuoteStatus::Accepted) {
            $problem = 'This quote is no longer accepted.';
        } elseif ($q->orderDeadline()->isPast()) {
            $problem = 'This offer has expired. Ask Betoch Support for a new one.';
        } elseif (! $activeSellerIds->has($q->seller_profile_id)) {
            $problem = 'This store is not taking orders right now.';
        } elseif ($q->isOrdered()) {
            $problem = 'This quote has already been ordered.';
        }

        // A custom quote is one line for its full quantity; its own delivery fee wins.
        // Same rule as a listing's fee: a store's own charge counts only while admins allow it (free always does).
        $quoteFee = $q->delivery_fee_santim === 0 || ($q->delivery_fee_santim !== null && $this->storesSetFee) ? $q->delivery_fee_santim : null;
        $fee = $quoteFee ?? ($p?->ownDeliveryFeeSantim($this->storesSetFee) ?? $defaultFee);

        return [
            'id' => $item->id,
            'kind' => 'quote',
            'product' => $p ? ['id' => $p->id, 'slug' => $p->slug, 'name' => $p->name, 'image' => null, 'stock_type' => null, 'production_days' => null] : null,
            'quote' => ['id' => $q->id, 'title' => $q->title, 'quantity' => $q->quantity, 'production_days' => $q->production_days, 'conversation_id' => $q->conversation_id, 'support_ticket_id' => $q->support_ticket_id, 'order_by' => $q->orderDeadline()->toIso8601String()],
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
