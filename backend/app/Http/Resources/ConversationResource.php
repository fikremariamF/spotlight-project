<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Conversation as seen by one participant. The viewer's side ('customer' or 'seller')
 * decides the unread flag and which counterpart details are shown.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    private ?string $side;

    /**
     * Collections construct resources with (item, key), so a non-string second argument is ignored
     * and the side is worked out from the signed-in user instead.
     */
    public function __construct($resource, mixed $side = null)
    {
        parent::__construct($resource);
        $this->side = in_array($side, ['customer', 'seller'], true) ? $side : null;
    }

    public static function forSide(string $side, $resource): static
    {
        return new static($resource, $side);
    }

    public function toArray(Request $request): array
    {
        $side = $this->side ?? $this->sideOf($request->user());

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'subject' => $this->subject,
            'status' => $this->status,
            'viewer_side' => $side,
            'unread' => $side !== null && $this->isUnreadFor($side),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            // Customers see the store; sellers see the customer's name only (no email).
            'seller' => $this->whenLoaded('seller', fn () => [
                'id' => $this->seller->id,
                'store_name' => $this->seller->store_name,
                'slug' => $this->seller->slug,
            ]),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
            ]),
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'slug' => $this->product->slug,
                'price_santim' => $this->product->price_santim,
                'image' => $this->product->relationLoaded('primaryImage') ? $this->product->primaryImage?->thumbUrl() : null,
            ] : null),
            'last_message' => $this->whenLoaded('latestMessage', fn () => $this->latestMessage ? [
                'body' => mb_strimwidth($this->latestMessage->body, 0, 140, '...'),
                'is_mine' => $this->latestMessage->sender_id === $request->user()?->id,
            ] : null),
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
            'quotes' => QuoteResource::collection($this->whenLoaded('quotes')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
