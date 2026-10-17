<?php

namespace App\Http\Resources;

use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An inquiry as its customer or an admin sees it. The customer view never carries notes, history,
 * store requests, the assignee or the store's private details; messages for it come from
 * SupportTicket::publicMessages().
 *
 * @mixin SupportTicket
 */
class SupportTicketResource extends JsonResource
{
    private string $viewer = 'customer';

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
            'reference' => $this->reference(),
            'topic' => $this->topic->value,
            'topic_label' => $this->topic->label(),
            'subject' => $this->subject,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'unread' => ! $admin && $this->isUnreadForCustomer(),
            'last_public_message_at' => $this->last_public_message_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'slug' => $this->product->slug,
                'price_santim' => $this->product->price_santim,
                'image' => $this->product->relationLoaded('primaryImage') ? $this->product->primaryImage?->thumbUrl() : null,
                'status' => $this->when($admin, fn () => $this->product->trashed() ? 'deleted' : $this->product->status->value),
                'stock_type' => $this->when($admin, fn () => $this->product->stock_type->value),
                'stock_quantity' => $this->when($admin, fn () => $this->product->stock_quantity),
            ] : null),
            'order' => $this->whenLoaded('order', fn () => $this->order ? [
                'id' => $this->order->id,
                'number' => $this->order->number,
                'status' => $this->order->status->value,
                'total_santim' => $this->when($admin, fn () => $this->order->total_santim),
                'payment_method' => $this->when($admin, fn () => $this->order->payment_method),
                'payment_status' => $this->when($admin, fn () => $this->order->payment_status),
                'created_at' => $this->when($admin, fn () => $this->order->created_at?->toIso8601String()),
            ] : null),
            // Customers already see the store name on the listing and order; nothing else about it.
            'store' => $this->whenLoaded('seller', fn () => $this->seller ? [
                'id' => $this->seller->id,
                'store_name' => $this->seller->store_name,
                'slug' => $this->seller->slug,
                'status' => $this->when($admin, fn () => $this->seller->status->value),
                'phone' => $this->when($admin, fn () => $this->seller->phone),
            ] : null),
            'last_message' => $this->whenLoaded('latestPublicMessage', fn () => $this->latestPublicMessage ? [
                'body' => mb_strimwidth($this->latestPublicMessage->body, 0, 140, '...'),
                'from' => $this->latestPublicMessage->author_role,
            ] : null),
            // Belt and braces: even if a caller loaded every row, a customer never gets an internal one.
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->filter(fn ($m) => $admin || ! $m->internal)->map(fn ($m) => (new SupportMessageResource($m))->as($this->viewer)->resolve($request))->values()),
            'offers' => QuoteResource::collection($this->whenLoaded('quotes')),

            // Admins only.
            'customer' => $this->when($admin && $this->relationLoaded('customer'), fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
                'phone' => $this->customer->phone,
            ]),
            'assignee' => $this->when($admin && $this->relationLoaded('assignee'), fn () => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null),
            'escalated_at' => $this->when($admin, fn () => $this->escalated_at?->toIso8601String()),
            'escalation_reason' => $this->when($admin, fn () => $this->escalation_reason),
            'needs_reply' => $this->when($admin, fn () => $this->status->value === 'new'
                || ($this->status->isActive() && $this->last_public_by === 'customer')),
            'last_activity_at' => $this->when($admin, fn () => $this->last_activity_at?->toIso8601String()),
            'first_response_at' => $this->when($admin, fn () => $this->first_response_at?->toIso8601String()),
            'store_requests' => $this->when($admin && $this->relationLoaded('sellerRequests'),
                fn () => $this->sellerRequests->map(fn ($r) => (new SellerRequestResource($r))->as('admin')->resolve($request))),
        ];
    }
}
