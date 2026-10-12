<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Models\Checkout;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as its customer, seller or an admin sees it. Customers never see commission;
 * sellers see the commission on their own orders.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    private string $viewer = 'customer';

    public function as(string $viewer): static
    {
        $this->viewer = $viewer;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $showCommission = $this->viewer !== 'customer';
        $checkout = $this->whenLoaded('checkout');

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'fulfillment' => $this->fulfillment,
            'items_total_santim' => $this->items_total_santim,
            'delivery_fee_santim' => $this->delivery_fee_santim,
            'delivery_fee_confirmed' => $this->deliveryFeeConfirmed(),
            'total_santim' => $this->total_santim,
            'total' => Money::toBirr($this->total_santim),
            'checkout_id' => $this->checkout_id,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'paid_santim' => $this->paid_santim,
            'paid_at' => $this->paid_at?->toIso8601String(),
            // Online orders waiting for payment: when they will be cancelled if still unpaid.
            'payment_due_at' => $checkout instanceof Checkout && $this->status === OrderStatus::AwaitingPayment ? $checkout->payment_due_at?->toIso8601String() : null,
            'refund' => $this->when($this->viewer !== 'seller' && $this->relationLoaded('refund') && $this->refund, fn () => [
                'status' => $this->refund->status,
                'amount_santim' => $this->refund->amount_santim,
                'reference' => $this->refund->reference,
                'refunded_at' => $this->refund->refunded_at?->toIso8601String(),
            ]),
            'commission_rate_percent' => $this->when($showCommission, fn () => $this->commission_rate_bp / 100),
            'commission_santim' => $this->when($showCommission, fn () => $this->commission_santim),
            'customer_note' => $this->customer_note,
            'seller_note' => $this->seller_note,
            'cancel_reason' => $this->cancel_reason,
            'cancelled_by' => $this->cancelled_by,
            'seller' => $this->whenLoaded('seller', fn () => [
                'id' => $this->seller->id,
                'store_name' => $this->seller->store_name,
                'slug' => $this->seller->slug,
                // The customer needs the store's phone to arrange delivery once they have ordered.
                'phone' => $this->seller->phone,
                'area' => $this->seller->relationLoaded('area') ? $this->seller->area?->name : null,
            ]),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->when($this->viewer === 'admin', $this->customer->email),
            ]),
            'delivery_address' => $checkout instanceof Checkout ? [
                'recipient_name' => $checkout->recipient_name,
                'phone' => $checkout->phone,
                'area' => $checkout->area_name,
                'address_line' => $checkout->address_line,
                'landmark' => $checkout->landmark,
            ] : null,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'product_slug' => $i->relationLoaded('product') ? $i->product?->slug : null,
                'image' => $i->relationLoaded('product') && $i->product?->relationLoaded('primaryImage') ? $i->product->primaryImage?->thumbUrl() : null,
                'quote_id' => $i->quote_id,
                'unit_price_santim' => $i->unit_price_santim,
                'quantity' => $i->quantity,
                'line_total_santim' => $i->line_total_santim,
                'stock_type' => $i->stock_type,
                'production_days' => $i->production_days,
                'review' => $i->relationLoaded('review') && $i->review ? [
                    'id' => $i->review->id,
                    'rating' => $i->review->rating,
                    'body' => $i->review->body,
                    'seller_reply' => $i->review->seller_reply,
                    'is_hidden' => $i->review->is_hidden,
                ] : null,
            ])->values()),
            // Customers can review delivered items for a limited time after delivery.
            'reviews_open' => $this->viewer === 'customer' && $this->status === OrderStatus::Delivered && $this->delivered_at
                && $this->delivered_at->copy()->addDays(config('marketplace.reviews.window_days'))->isFuture(),
            'review_until' => $this->when($this->viewer === 'customer' && $this->status === OrderStatus::Delivered && $this->delivered_at, fn () => $this->delivered_at->copy()->addDays(config('marketplace.reviews.window_days'))->toIso8601String()),
            'history' => $this->whenLoaded('history', fn () => $this->history->map(fn ($h) => [
                'from' => $h->from_status,
                'to' => $h->to_status,
                'by' => $h->actor_role,
                'note' => $h->note,
                'at' => $h->created_at?->toIso8601String(),
            ])->values()),
            'items_count' => $this->whenCounted('items'),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
