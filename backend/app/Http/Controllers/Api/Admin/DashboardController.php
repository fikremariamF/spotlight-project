<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Enums\SellerStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\SellerLedgerEntry;
use App\Models\SellerProfile;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function summary(): JsonResponse
    {
        $sellers = SellerProfile::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $products = Product::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $orders = Order::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json(['data' => [
            'customers' => User::where('role', Role::Customer->value)->count(),
            'sellers' => collect(SellerStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($sellers[$s->value] ?? 0)]),
            'products' => collect(ProductStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($products[$s->value] ?? 0)]),
            'orders' => collect(OrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($orders[$s->value] ?? 0)]),
            // Money is reported only for delivered orders. Gross sales are what customers paid
            // sellers; commission is the platform's revenue; neither is profit.
            'money' => [
                'delivered_gross_santim' => (int) Order::where('status', OrderStatus::Delivered->value)->sum('total_santim'),
                'delivered_items_santim' => (int) Order::where('status', OrderStatus::Delivered->value)->sum('items_total_santim'),
                'commission_earned_santim' => (int) Order::where('status', OrderStatus::Delivered->value)->sum('commission_santim'),
                'open_orders_value_santim' => (int) Order::whereNotIn('status', [OrderStatus::Delivered->value, OrderStatus::Cancelled->value, OrderStatus::AwaitingPayment->value])->sum('total_santim'),
                'commission_rate_percent' => Setting::commissionRateBp() / 100,
                // What sellers still owe on cash-on-delivery sales (credits on other stores do not offset it).
                'commission_outstanding_santim' => (int) DB::query()
                    ->fromSub(SellerLedgerEntry::query()->selectRaw('seller_profile_id, sum(amount_santim) as b')->groupBy('seller_profile_id'), 'x')
                    ->where('b', '>', 0)->sum('b'),
            ],
            'reviews_hidden' => Review::where('is_hidden', true)->count(),
            'reviews_low' => Review::visible()->where('rating', '<=', 2)->where('created_at', '>=', now()->subDays(30))->count(),
        ]]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $logs = AuditLog::with('user:id,name,email')
            ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', str_replace(['%', '_'], '', $a).'%'))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return response()->json($logs);
    }
}
