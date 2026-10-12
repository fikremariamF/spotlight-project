<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\OrderController as CustomerOrderController;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'q' => ['nullable', 'string', 'max:40'],
        ]);
        $orders = Order::with(['seller', 'customer'])
            ->withCount('items')
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when(trim($data['q'] ?? ''), fn ($q, $term) => $q->where('number', strtoupper($term)))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return OrderResource::collection($orders->through(fn ($o) => (new OrderResource($o))->as('admin')));
    }

    public function show(int $id): OrderResource
    {
        return CustomerOrderController::detail(Order::findOrFail($id), 'admin');
    }

    public function cancel(Request $request, int $id): OrderResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $order = Order::findOrFail($id);
        if ($order->status === OrderStatus::AwaitingPayment) {
            $this->payments->cancelUnpaid($order->checkout, 'admin', $request->user(), trim($data['reason']));

            return CustomerOrderController::detail($order->fresh(), 'admin');
        }
        $order = $this->orders->transition($id, OrderStatus::Cancelled, $request->user(), 'admin', trim($data['reason']));

        return CustomerOrderController::detail($order, 'admin');
    }
}
