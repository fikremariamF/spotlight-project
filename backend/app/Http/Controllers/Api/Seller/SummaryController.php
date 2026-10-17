<?php

namespace App\Http\Controllers\Api\Seller;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\OfflineSale;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\Review;
use App\Models\StockTransfer;
use App\Support\Erp\SaleFigures;
use App\Support\Erp\StockFigures;
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
            'stock' => StockFigures::counts($seller->id),
            'erp' => [
                // Purchase orders past their expected date, and transfers still on their way.
                'late_purchases' => PurchaseOrder::where('seller_profile_id', $seller->id)->whereIn('status', ['ordered', 'partially_received'])
                    ->whereDate('expected_date', '<', now(config('marketplace.timezone'))->toDateString())->count(),
                'transfers_in_transit' => StockTransfer::where('seller_profile_id', $seller->id)->where('status', 'in_transit')->count(),
                'overdue_sales' => $this->overdueSales($seller->id),
            ],
        ]]);
    }

    /** Offline sales past their pay-by date that the customer has not paid in full. */
    private function overdueSales(int $storeId): int
    {
        $sales = OfflineSale::where('seller_profile_id', $storeId)->where('status', 'completed')
            ->whereDate('due_date', '<', now(config('marketplace.timezone'))->toDateString())->get(['id', 'status', 'total_santim']);
        $money = SaleFigures::money($sales->pluck('id')->all());

        return $sales->filter(fn ($s) => SaleFigures::balance($s, $money[$s->id]) > 0)->count();
    }
}
