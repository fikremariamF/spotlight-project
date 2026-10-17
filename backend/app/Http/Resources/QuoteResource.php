<?php

namespace App\Http\Resources;

use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Quote */
class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $accepted = $this->status === QuoteStatus::Accepted;

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'support_ticket_id' => $this->support_ticket_id,
            // Offers Betoch sends are fulfilled by a store; its name is public on the listing anyway.
            'store' => $this->whenLoaded('seller', fn () => $this->seller ? ['store_name' => $this->seller->store_name, 'slug' => $this->seller->slug] : null),
            'product_id' => $this->product_id,
            'title' => $this->title,
            'description' => $this->description,
            'price' => Money::toBirr($this->price_santim),
            'price_santim' => $this->price_santim,
            'quantity' => $this->quantity,
            'delivery_fee' => Money::toBirr($this->delivery_fee_santim),
            'delivery_fee_santim' => $this->delivery_fee_santim,
            'total_santim' => $this->totalSantim(),
            'production_days' => $this->production_days,
            'valid_until' => $this->valid_until->toDateString(),
            'status' => $this->effectiveStatus()->value,
            'responded_at' => $this->responded_at?->toIso8601String(),
            // Only accepted quotes can be ordered; these two drive the "Add to cart" button.
            'order_by' => $accepted ? $this->orderDeadline()->toIso8601String() : null,
            'ordered' => $accepted ? $this->isOrdered() : false,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
