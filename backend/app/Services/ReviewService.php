<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reviews come only from customers who received the item. Rating totals are recomputed from the
 * visible reviews after every change, so they cannot drift.
 */
class ReviewService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Alerts $alerts,
    ) {}

    /**
     * The last moment a review for this delivered order line can be written or edited, or null if it never can.
     */
    public static function deadline(OrderItem $item): ?CarbonInterface
    {
        $order = $item->order;
        if ($order->status !== OrderStatus::Delivered || ! $order->delivered_at) {
            return null;
        }

        return $order->delivered_at->copy()->addDays(config('marketplace.reviews.window_days'));
    }

    public function create(User $customer, int $orderId, int $itemId, int $rating, ?string $body): Review
    {
        $item = OrderItem::with('order')->where('order_id', $orderId)
            ->whereHas('order', fn ($q) => $q->where('customer_id', $customer->id))
            ->findOrFail($itemId);

        $this->assertOpen($item);

        try {
            return DB::transaction(function () use ($item, $customer, $rating, $body) {
                $this->lockTotals($item->product_id, $item->order->seller_profile_id);
                if ($item->product_id && Review::where('customer_id', $customer->id)->where('product_id', $item->product_id)->exists()) {
                    throw ValidationException::withMessages(['rating' => 'You already reviewed this listing from another order. Edit that review instead.']);
                }
                $review = new Review;
                $review->order_item_id = $item->id;
                $review->order_id = $item->order_id;
                $review->product_id = $item->product_id;
                $review->seller_profile_id = $item->order->seller_profile_id;
                $review->customer_id = $customer->id;
                $review->rating = $rating;
                $review->body = $body;
                $review->save();
                $this->recount($review);
                $this->alerts->reviewPosted($review);

                return $review;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['rating' => 'You already reviewed this item. Edit your review instead.']);
        }
    }

    public function update(User $customer, int $reviewId, int $rating, ?string $body): Review
    {
        $review = Review::where('customer_id', $customer->id)->with('orderItem.order')->findOrFail($reviewId);
        $this->assertOpen($review->orderItem);

        return DB::transaction(function () use ($review, $rating, $body) {
            $this->lockTotals($review->product_id, $review->seller_profile_id);
            $review->rating = $rating;
            $review->body = $body;
            $review->save();
            $this->recount($review);

            return $review;
        });
    }

    public function reply(SellerProfile $seller, int $reviewId, ?string $reply): Review
    {
        $review = Review::where('seller_profile_id', $seller->id)->findOrFail($reviewId);
        $isFirstReply = blank($review->seller_reply) && filled($reply);
        $review->seller_reply = $reply;
        $review->seller_replied_at = $reply ? now() : null;
        $review->save();
        if ($isFirstReply) {
            $this->alerts->reviewReplied($review);
        }

        return $review;
    }

    public function setHidden(int $reviewId, bool $hidden, ?string $reason): Review
    {
        $ids = Review::select(['product_id', 'seller_profile_id'])->findOrFail($reviewId);

        return DB::transaction(function () use ($reviewId, $hidden, $reason, $ids) {
            $this->lockTotals($ids->product_id, $ids->seller_profile_id);
            $review = Review::lockForUpdate()->findOrFail($reviewId);
            $review->is_hidden = $hidden;
            $review->hidden_reason = $hidden ? $reason : null;
            $review->save();
            $this->recount($review);
            $this->audit->log($hidden ? 'review.hidden' : 'review.restored', $review, ['reason' => $reason]);

            return $review;
        });
    }

    private function assertOpen(OrderItem $item): void
    {
        $deadline = self::deadline($item);
        if (! $deadline) {
            throw ValidationException::withMessages(['rating' => 'You can review an item once it has been delivered.']);
        }
        if ($deadline->isPast()) {
            throw ValidationException::withMessages(['rating' => 'Reviews close '.config('marketplace.reviews.window_days').' days after delivery.']);
        }
    }

    /**
     * Serialise rating changes per product and store, always product first. Without this, two reviews
     * saved at the same moment could each count only themselves and leave the totals one short.
     */
    private function lockTotals(?int $productId, int $sellerProfileId): void
    {
        if ($productId) {
            Product::withTrashed()->whereKey($productId)->lockForUpdate()->first();
        }
        SellerProfile::whereKey($sellerProfileId)->lockForUpdate()->first();
    }

    private function recount(Review $review): void
    {
        if ($review->product_id) {
            $stats = Review::visible()->where('product_id', $review->product_id)->selectRaw('count(*) as c, coalesce(sum(rating), 0) as s')->first();
            Product::withTrashed()->whereKey($review->product_id)->update(['rating_count' => (int) $stats->c, 'rating_sum' => (int) $stats->s]);
        }
        $stats = Review::visible()->where('seller_profile_id', $review->seller_profile_id)->selectRaw('count(*) as c, coalesce(sum(rating), 0) as s')->first();
        SellerProfile::whereKey($review->seller_profile_id)->update(['rating_count' => (int) $stats->c, 'rating_sum' => (int) $stats->s]);
    }
}
