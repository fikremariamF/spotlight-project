<?php

namespace App\Http\Controllers\Api\Seller;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\OrderController as CustomerOrderController;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Seller side: every lookup goes through the seller's own profile, so other stores' orders are 404.
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['nullable', Rule::enum(OrderStatus::class)]]);
        $orders = $this->scoped($request)
            ->with(['customer', 'items'])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return OrderResource::collection($orders->through(fn ($o) => (new OrderResource($o))->as('seller')));
    }

    public function summary(Request $request): JsonResponse
    {
        $base = $this->scoped($request);
        $counts = (clone $base)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $delivered = (clone $base)->where('status', OrderStatus::Delivered->value);

        return response()->json(['data' => [
            'counts' => $counts,
            'delivered_sales_santim' => (int) (clone $delivered)->sum('total_santim'),
            'commission_on_delivered_santim' => (int) (clone $delivered)->sum('commission_santim'),
            'balance' => BalanceController::summary($request->user()->sellerProfile()->firstOrFail()->id),
        ]]);
    }

    public function show(Request $request, int $id): OrderResource
    {
        return CustomerOrderController::detail($this->scoped($request)->findOrFail($id), 'seller');
    }

    public function confirm(Request $request, int $id): OrderResource
    {
        $data = $request->validate([
            'delivery_fee' => ['nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $this->scoped($request)->findOrFail($id);
        if (! $request->user()->isApprovedSeller()) {
            throw ValidationException::withMessages(['status' => 'Your store must be approved to confirm orders.']);
        }

        $order = $this->orders->transition($id, OrderStatus::Confirmed, $request->user(), 'seller',
            isset($data['note']) ? trim($data['note']) ?: null : null,
            isset($data['delivery_fee']) ? Money::toSantim($data['delivery_fee']) : null);

        return CustomerOrderController::detail($order, 'seller');
    }

    public function advance(Request $request, int $id): OrderResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([OrderStatus::Processing->value, OrderStatus::Ready->value, OrderStatus::Dispatched->value, OrderStatus::Delivered->value])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $this->scoped($request)->findOrFail($id);
        $order = $this->orders->transition($id, OrderStatus::from($data['status']), $request->user(), 'seller', $data['note'] ?? null);

        return CustomerOrderController::detail($order, 'seller');
    }

    public function cancel(Request $request, int $id): OrderResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $this->scoped($request)->findOrFail($id);
        $order = $this->orders->transition($id, OrderStatus::Cancelled, $request->user(), 'seller', trim($data['reason']));

        return CustomerOrderController::detail($order, 'seller');
    }

    private function scoped(Request $request)
    {
        return Order::where('seller_profile_id', $request->user()->sellerProfile?->id ?? 0)->visibleToSeller();
    }
}
