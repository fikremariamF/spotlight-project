<?php

namespace App\Http\Resources;

use App\Models\SellerRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A request from Betoch to a store. The store view has no customer and no link to the customer's inquiry;
 * the listing and order are the store's own.
 *
 * @mixin SellerRequest
 */
class SellerRequestResource extends JsonResource
{
    private string $viewer = 'seller';

    public function as(string $viewer): static
    {
        $this->viewer = $viewer;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $admin = $this->viewer === 'admin';

        return [
            'id' => $this->id,
            'topic' => $this->topic->value,
            'topic_label' => $this->topic->label(),
            'subject' => $this->subject,
            'status' => $this->status,
            'unread' => ! $admin && $this->isUnreadForSeller(),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'last_message_by' => $this->last_message_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'slug' => $this->product->slug,
            ] : null),
            'order' => $this->whenLoaded('order', fn () => $this->order ? [
                'id' => $this->order->id,
                'number' => $this->order->number,
                'status' => $this->order->status->value,
            ] : null),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn ($m) => [
                'id' => $m->id,
                'from_role' => $m->author_role,
                'from' => match ($m->author_role) {
                    'seller' => $admin ? ($this->relationLoaded('seller') ? $this->seller->store_name : 'Store') : 'You',
                    'admin' => $admin && $m->relationLoaded('author') ? $m->author?->name : 'Betoch Support',
                    default => 'Betoch',
                },
                'body' => $m->body,
                'proposal' => $m->hasProposal() ? [
                    'price_santim' => $m->price_santim,
                    'quantity' => $m->quantity,
                    'delivery_fee_santim' => $m->delivery_fee_santim,
                    'production_days' => $m->production_days,
                    'valid_until' => $m->valid_until?->toDateString(),
                    'total_santim' => $m->proposalTotalSantim(),
                    'sent' => $m->quote_id !== null,
                    'quote_id' => $this->when($admin, $m->quote_id),
                    'offer_status' => $m->relationLoaded('quote') && $m->quote ? $m->quote->effectiveStatus()->value : null,
                ] : null,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values()),

            // Admins only.
            'store' => $this->when($admin && $this->relationLoaded('seller'), fn () => [
                'id' => $this->seller->id,
                'store_name' => $this->seller->store_name,
                'slug' => $this->seller->slug,
                'status' => $this->seller->status->value,
            ]),
            'support_ticket_id' => $this->when($admin, fn () => $this->support_ticket_id),
            'ticket' => $this->when($admin && $this->relationLoaded('ticket'), fn () => $this->ticket ? [
                'id' => $this->ticket->id,
                'reference' => $this->ticket->reference(),
                'subject' => $this->ticket->subject,
                'status' => $this->ticket->status->value,
            ] : null),
            'created_by' => $this->when($admin && $this->relationLoaded('creator'), fn () => $this->creator?->name),
        ];
    }
}
