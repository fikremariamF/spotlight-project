<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\Setting;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    /**
     * Include moderation fields. Enabled by ManagedProductResource for owners and administrators.
     */
    protected bool $withModeration = false;

    public function toArray(Request $request): array
    {
        // Shoppers see the fee that applies; the owner and admins see what the store entered.
        $storesSetFee = self::storesSetFee($request);
        $fee = $this->withModeration ? $this->delivery_fee_santim : $this->ownDeliveryFeeSantim($storesSetFee);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'price' => Money::toBirr($this->price_santim),
            'price_santim' => $this->price_santim,
            'currency' => config('marketplace.currency'),
            'price_negotiable' => $this->price_negotiable,
            'condition' => $this->condition->value,
            'stock_type' => $this->stock_type->value,
            'stock_quantity' => $this->stock_quantity,
            'is_available' => $this->isAvailable(),
            'rating' => [
                'average' => $this->rating_count ? round($this->rating_sum / $this->rating_count, 1) : null,
                'count' => $this->rating_count,
            ],
            'production_days' => $this->production_days,
            'custom_order_available' => $this->custom_order_available,
            'dimensions' => [
                'width_cm' => $this->width_cm,
                'depth_cm' => $this->depth_cm,
                'height_cm' => $this->height_cm,
            ],
            'material' => $this->material,
            'color' => $this->color,
            'sku' => $this->when($this->withModeration, fn () => $this->sku),
            'options_count' => $this->whenCounted('variants'),
            // Options a customer picks from, each with its own stock and price.
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($v) => [
                'id' => $v->id,
                'label' => $v->label(),
                'size' => $v->size,
                'color' => $v->color,
                'material' => $v->material,
                'price' => Money::toBirr($v->priceSantim($this->resource)),
                'price_santim' => $v->priceSantim($this->resource),
                'stock_quantity' => $v->stock_quantity,
                'is_available' => $this->stock_type->value === 'made_to_order' || $v->stock_quantity > 0,
            ] + ($this->withModeration ? ['own_price' => Money::toBirr($v->price_santim), 'sku' => $v->sku] : []))->values()->all()),
            'area' => $this->whenLoaded('area', fn () => $this->area?->name),
            'area_id' => $this->area_id,
            'delivery' => [
                'offers_delivery' => $this->offers_delivery,
                'pickup_available' => $this->pickup_available,
                'fee' => Money::toBirr($fee),
                'fee_santim' => $fee,
                // The store's own charged fee is switched off by admins, so the marketplace prices delivery.
                'fee_ignored' => $this->when($this->withModeration, fn () => $fee !== null && $fee > 0 && ! $storesSetFee),
                'notes' => $this->delivery_notes,
            ],
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'seller' => $this->whenLoaded('seller', fn () => [
                'id' => $this->seller->id,
                'store_name' => $this->seller->store_name,
                'slug' => $this->seller->slug,
                'area' => $this->seller->relationLoaded('area') ? $this->seller->area?->name : null,
            ]),
            'image' => $this->whenLoaded('primaryImage', fn () => $this->primaryImage?->thumbUrl()),
            // A photo without its copies has nothing public to show; its store still sees it to remove it.
            'images' => $this->whenLoaded('images', fn () => $this->images->filter(fn ($img) => $this->withModeration || $img->large_path)->map(fn ($img) => [
                'id' => $img->id,
                'url' => $img->url(),
                'thumb' => $img->thumbUrl(),
                'alt' => $img->alt,
                'sort_order' => $img->sort_order,
            ])->values()),
            'is_favorite' => $this->when(isset($this->is_favorite), fn () => (bool) $this->is_favorite),
            'status' => $this->when($this->withModeration, fn () => $this->status->value),
            'moderation_note' => $this->when($this->withModeration, fn () => $this->moderation_note),
            'is_featured' => $this->is_featured,
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** Read once per request, not once per listing in a list. */
    private static function storesSetFee(Request $request): bool
    {
        if (! $request->attributes->has('stores_set_delivery_fee')) {
            $request->attributes->set('stores_set_delivery_fee', Setting::storesSetDeliveryFee());
        }

        return $request->attributes->get('stores_set_delivery_fee');
    }
}
