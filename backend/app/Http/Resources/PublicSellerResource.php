<?php

namespace App\Http\Resources;

use App\Models\SellerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What guests see about a seller. No TIN, owner email, review notes or phone: customers reach
 * stores only through Betoch support.
 *
 * @mixin SellerProfile
 */
class PublicSellerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_name' => $this->store_name,
            'slug' => $this->slug,
            'description' => $this->description,
            'business_type' => $this->business_type->value,
            'city' => $this->whenLoaded('city', fn () => $this->city?->name),
            'area' => $this->whenLoaded('area', fn () => $this->area?->name),
            'offers_custom_orders' => $this->offers_custom_orders,
            'offers_delivery' => $this->offers_delivery,
            'products_count' => $this->whenCounted('products'),
            'rating' => [
                'average' => $this->rating_count ? round($this->rating_sum / $this->rating_count, 1) : null,
                'count' => $this->rating_count,
            ],
            'member_since' => $this->created_at?->toDateString(),
        ];
    }
}
