<?php

namespace App\Http\Controllers\Api\Seller;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Review;
use App\Models\SellerRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Counts for the seller menu and overview: what is waiting for the store and how it is doing.
 */
class SummaryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $seller = $request->user()->sellerProfile()->firstOrFail();
        // Unpaid online orders stay out of sight, as on the orders page.
        $orders = Order::where('seller_profile_id', $seller->id)->visibleToSeller();
        $reviews = Review::visible()->where('seller_profile_id', $seller->id);

        return response()->json(['data' => [
            'store' => $seller->only(['store_name', 'slug', 'status', 'status_reason', 'offers_delivery']) + ['has_pin' => $seller->pin() !== null],
            'orders' => (clone $orders)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
            'products' => $seller->products()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
            'delivered_sales_santim' => (int) (clone $orders)->where('status', OrderStatus::Delivered->value)->sum('total_santim'),
            'reviews' => [
                'count' => $seller->rating_count,
                'average' => $seller->rating_count ? round($seller->rating_sum / $seller->rating_count, 1) : null,
                'unanswered' => (clone $reviews)->whereNull('seller_reply')->count(),
                // Low ratings from the last 30 days without a reply: worth answering while the buyer still reads it.
                'low_unanswered' => (clone $reviews)->whereNull('seller_reply')->where('rating', '<=', 2)
                    ->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'balance' => BalanceController::summary($seller->id),
            // Requests from Betoch the store has not read yet, and those waiting for its answer.
            'requests' => [
                'unread' => SellerRequest::where('seller_profile_id', $seller->id)->unreadForSeller()->count(),
                'waiting' => SellerRequest::where('seller_profile_id', $seller->id)->where('status', SellerRequest::STATUS_OPEN)->count(),
            ],
        ]]);
    }
}
