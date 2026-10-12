<?php

namespace App\Http\Resources;

use App\Models\Product;
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
            'area' => $this->whenLoaded('area', fn () => $this->area?->name),
            'area_id' => $this->area_id,
            'delivery' => [
                'offers_delivery' => $this->offers_delivery,
                'pickup_available' => $this->pickup_available,
                'fee' => Money::toBirr($this->delivery_fee_santim),
                'fee_santim' => $this->delivery_fee_santim,
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
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($img) => [
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
}
