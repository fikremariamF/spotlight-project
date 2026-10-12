<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\StockType;
use App\Http\Controllers\Api\CartController;
use App\Models\Address;
use App\Models\Area;
use App\Models\CartItem;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly CheckoutCalculator $calculator,
        private readonly AuditLogger $audit,
        private readonly LedgerService $ledger,
        private readonly Alerts $alerts,
    ) {}

    /**
     * Turn the customer's cart into one checkout and one order per seller, atomically.
     * Prices, stock and delivery fees are recomputed here from locked rows; the client's
     * expected total must match, so a price change never surprises the customer.
     *
     * @return array{0: Checkout, 1: bool} the checkout and whether it was created now
     */
    public function place(User $customer, Address $address, string $idempotencyKey, string $paymentMethod, array $fulfillment, int $expectedTotal, ?string $note): array
    {
        return DB::transaction(function () use ($customer, $address, $idempotencyKey, $paymentMethod, $fulfillment, $expectedTotal, $note) {
            // One checkout at a time per customer; a retried request returns the first result.
            User::whereKey($customer->id)->lockForUpdate()->first();
            $existing = Checkout::where('customer_id', $customer->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return [$existing, false];
            }

            $lines = CartController::lines($customer);
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['cart' => 'Your cart is empty.']);
            }

            // Lock listing and quote rows in id order (consistent order avoids deadlocks), then
            // price from the locked, fresh values.
            $productIds = $lines->pluck('product_id')->filter()->sort()->values()->all();
            $locked = Product::withTrashed()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $quoteIds = $lines->pluck('quote_id')->filter()->sort()->values()->all();
            $lockedQuotes = Quote::whereIn('id', $quoteIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($lines as $line) {
                if ($line->product_id) {
                    $line->product->setRawAttributes($locked[$line->product_id]->getAttributes(), true);
                }
                if ($line->quote_id) {
                    $line->quote->setRawAttributes($lockedQuotes[$line->quote_id]->getAttributes(), true);
                }
            }

            $area = Area::with('deliveryZone')->findOrFail($address->area_id);
            $priced = $this->calculator->calculate($lines, $area, $fulfillment);

            if ($priced['has_problems']) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Some items in your cart need attention before you can order.',
                    'data' => $priced,
                ], 409));
            }
            // Online payments charge a fixed amount up front, so every delivery fee must already be known.
            if ($paymentMethod === 'online' && $priced['delivery_to_confirm']) {
                throw ValidationException::withMessages(['payment_method' => 'A store still has to set the delivery fee for part of this order, so it cannot be paid online yet. Choose pickup for that store, or pay cash on delivery.']);
            }
            if ($priced['grand_total_santim'] !== $expectedTotal) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Prices or delivery fees changed since you opened checkout. Check the new total and place the order again.',
                    'data' => $priced,
                ], 409));
            }

            $checkout = new Checkout;
            $checkout->customer_id = $customer->id;
            $checkout->idempotency_key = $idempotencyKey;
            $checkout->payment_method = $paymentMethod;
            $checkout->recipient_name = $address->recipient_name;
            $checkout->phone = $address->phone;
            $checkout->area_id = $area->id;
            $checkout->area_name = $area->name;
            $checkout->address_line = $address->address_line;
            $checkout->landmark = $address->landmark;
            $checkout->items_total_santim = $priced['items_total_santim'];
            $checkout->delivery_total_santim = $priced['delivery_total_santim'];
            $checkout->grand_total_santim = $priced['grand_total_santim'];
            $online = $paymentMethod === 'online';
            if ($online) {
                $checkout->payment_due_at = now()->addMinutes(max(5, (int) config('marketplace.payments.window_minutes')));
            }
            $checkout->save();

            $rateBp = Setting::commissionRateBp();
            $linesById = $lines->keyBy('id');

            foreach ($priced['groups'] as $group) {
                $order = new Order;
                $order->checkout_id = $checkout->id;
                $order->customer_id = $customer->id;
                $order->seller_profile_id = $group['seller']['id'];
                $order->fulfillment = $group['fulfillment'];
                $order->items_total_santim = $group['items_total_santim'];
                $order->delivery_fee_santim = $group['fulfillment'] === 'delivery' ? $group['delivery_fee_santim'] : null;
                $order->total_santim = $group['total_santim'];
                $order->commission_rate_bp = $rateBp;
                $order->commission_santim = Order::commissionFor($group['items_total_santim'], $rateBp);
                $order->payment_method = $paymentMethod;
                $order->status = $online ? OrderStatus::AwaitingPayment : OrderStatus::PendingConfirmation;
                $order->customer_note = $note;
                $order->save();
                $order->number = 'ORD-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
                $order->save();

                foreach ($group['lines'] as $row) {
                    $cartLine = $linesById[$row['id']];
                    $item = new OrderItem;
                    $item->order_id = $order->id;
                    $item->name = $row['name'];
                    $item->unit_price_santim = $row['unit_price_santim'];
                    $item->quantity = $row['quantity'];
                    $item->line_total_santim = $row['line_total_santim'];

                    if ($cartLine->quote_id) {
                        $item->quote_id = $cartLine->quote_id;
                        $item->product_id = $cartLine->quote->product_id;
                        $item->production_days = $cartLine->quote->production_days;
                    } else {
                        $product = $locked[$cartLine->product_id];
                        $item->product_id = $product->id;
                        $item->stock_type = $product->stock_type->value;
                        $item->production_days = $product->production_days;
                        if ($product->stock_type === StockType::InStock) {
                            // Reserve now; the calculator already checked there is enough under the lock.
                            $product->decrement('stock_quantity', $row['quantity']);
                            $item->reserved_quantity = $row['quantity'];
                        }
                    }
                    $item->save();
                }

                $this->record($order, null, $order->status, $customer, 'customer', null);
            }

            CartItem::whereIn('id', $lines->pluck('id'))->delete();
            // Online orders reach the stores only once paid (see markPaid).
            if (! $online) {
                $this->alerts->orderPlaced($checkout);
            }
            $this->audit->log('checkout.placed', $checkout, [
                'orders' => $checkout->orders()->pluck('number'),
                'grand_total_santim' => $checkout->grand_total_santim,
                'payment_method' => $paymentMethod,
            ]);

            return [$checkout, true];
        });
    }

    /**
     * Move an order to a new status under a row lock, enforcing who may do what.
     *
     * @param  'customer'|'seller'|'admin'  $role
     */
    public function transition(int $orderId, OrderStatus $to, User $actor, string $role, ?string $note = null, ?int $deliveryFee = null): Order
    {
        return DB::transaction(function () use ($orderId, $to, $actor, $role, $note, $deliveryFee) {
            $order = Order::lockForUpdate()->findOrFail($orderId);
            $from = $order->status;

            if ($from->isFinal()) {
                throw ValidationException::withMessages(['status' => 'This order is already '.str_replace('_', ' ', $from->value).'.']);
            }
            if ($from === OrderStatus::AwaitingPayment) {
                throw ValidationException::withMessages(['status' => 'This order is waiting for online payment.']);
            }

            $this->authorize($order, $from, $to, $role);

            if ($to === OrderStatus::Confirmed) {
                // The limit stops new cash orders. A paid online order still goes ahead: the customer has
                // paid, and delivering it lowers what the store owes.
                $limit = Setting::creditLimitSantim();
                if ($order->payment_method !== 'online' && $limit > 0 && LedgerService::balance($order->seller_profile_id) > $limit) {
                    throw ValidationException::withMessages(['status' => 'Your unpaid commission is over the limit. Pay your balance (see Balance) to confirm new orders.']);
                }
                if ($order->fulfillment === 'delivery' && $order->delivery_fee_santim === null) {
                    if ($deliveryFee === null) {
                        throw ValidationException::withMessages(['delivery_fee' => 'Set the delivery fee before confirming.']);
                    }
                    $order->delivery_fee_santim = $deliveryFee;
                    $order->total_santim = $order->items_total_santim + $deliveryFee;
                }
                $order->confirmed_at = now();
                if ($note) {
                    $order->seller_note = $note;
                }
            }

            if ($to === OrderStatus::Delivered) {
                $order->delivered_at = now();
                // Cash on delivery is collected by the seller at handover.
                if ($order->payment_method === 'cod') {
                    $order->payment_status = 'paid';
                    $order->paid_at = now();
                }
            }

            $refund = null;
            if ($to === OrderStatus::Cancelled) {
                $order->cancel_reason = $note;
                $order->cancelled_by = $role;
                $order->cancelled_at = now();
                $this->releaseStock($order);
                // Money paid online for a cancelled order goes back to the customer.
                if ($order->payment_status === 'paid' && $order->payment_method === 'online') {
                    $order->payment_status = 'refund_due';
                    $refund = $this->refundOrder($order, $role === 'customer' ? 'Cancelled by the customer' : ($role === 'admin' ? 'Cancelled by the marketplace' : 'Cancelled by the store'));
                }
            }

            $order->status = $to;
            $order->save();

            if ($to === OrderStatus::Delivered) {
                // Cash stays with the seller, so the commission becomes a balance the seller owes. Online,
                // the platform holds the money: the sale is credited to the seller and the commission kept.
                $this->ledger->chargeCommission($order);
                if ($order->payment_method === 'online') {
                    $this->ledger->creditSale($order);
                }
            }
            $this->record($order, $from, $to, $actor, $role, $note);
            $this->audit->log('order.'.$to->value, $order, ['from' => $from->value, 'by' => $role]);
            $this->alerts->orderStatusChanged($order, $from, $to, $role);
            if ($refund) {
                $this->alerts->refundDue($refund);
            }

            return $order;
        });
    }

    /**
     * Payment confirmed for an online checkout: its orders go to the stores. Runs inside the payment's
     * transaction with the checkout row locked.
     */
    public function markPaid(Checkout $checkout, Payment $payment): void
    {
        $orders = Order::where('checkout_id', $checkout->id)->where('status', OrderStatus::AwaitingPayment->value)->orderBy('id')->lockForUpdate()->get();
        foreach ($orders as $order) {
            $order->status = OrderStatus::PendingConfirmation;
            $order->payment_status = 'paid';
            $order->paid_at = $payment->paid_at;
            $order->paid_santim = $order->total_santim;
            $order->save();
            $this->record($order, OrderStatus::AwaitingPayment, OrderStatus::PendingConfirmation, null, 'system', 'Paid online');
        }
        $checkout->paid_at = $payment->paid_at;
        $checkout->save();
        $this->audit->log('checkout.paid', $checkout, ['payment_id' => $payment->id, 'tx_ref' => $payment->tx_ref, 'amount_santim' => $payment->amount_santim]);
        $this->alerts->orderPlaced($checkout);
    }

    /**
     * Cancel every order of an online checkout that was never paid, give the stock back and, when
     * the customer may want to try again, put the items back in their cart. Runs with the checkout locked.
     *
     * @param  'customer'|'admin'|'system'  $role
     */
    public function cancelUnpaid(Checkout $checkout, string $role, ?User $actor, string $reason, bool $restoreCart): int
    {
        $orders = Order::where('checkout_id', $checkout->id)->where('status', OrderStatus::AwaitingPayment->value)->orderBy('id')->lockForUpdate()->get();
        foreach ($orders as $order) {
            if ($restoreCart) {
                $this->restoreToCart($order);
            }
            $order->status = OrderStatus::Cancelled;
            $order->cancel_reason = $reason;
            $order->cancelled_by = $role;
            $order->cancelled_at = now();
            $this->releaseStock($order);
            $order->save();
            $this->record($order, OrderStatus::AwaitingPayment, OrderStatus::Cancelled, $actor, $role, $reason);
        }
        if ($orders->isNotEmpty()) {
            $this->audit->log('checkout.cancelled_unpaid', $checkout, ['by' => $role, 'orders' => $orders->pluck('number')]);
        }

        return $orders->count();
    }

    private function restoreToCart(Order $order): void
    {
        $max = (int) config('marketplace.cart.max_lines');
        foreach ($order->items()->get() as $item) {
            if (CartItem::where('user_id', $order->customer_id)->count() >= $max) {
                return;
            }
            $column = $item->quote_id ? 'quote_id' : 'product_id';
            $id = $item->quote_id ?: $item->product_id;
            if (! $id || ($column === 'product_id' && ! Product::whereKey($id)->exists())) {
                continue;
            }
            if (! CartItem::where('user_id', $order->customer_id)->where($column, $id)->exists()) {
                $line = new CartItem;
                $line->user_id = $order->customer_id;
                $line->{$column} = $id;
                $line->quantity = $item->quote_id ? 1 : $item->quantity;
                $line->save();
            }
        }
    }

    private function refundOrder(Order $order, string $reason): Refund
    {
        $refund = new Refund;
        $refund->order_id = $order->id;
        $refund->payment_id = Payment::where('checkout_id', $order->checkout_id)->where('status', 'paid')->orderBy('paid_at')->value('id');
        $refund->customer_id = $order->customer_id;
        $refund->amount_santim = (int) ($order->paid_santim ?? $order->total_santim);
        $refund->reason = $reason;
        $refund->save();
        $this->audit->log('refund.due', $refund, ['order' => $order->number, 'amount_santim' => $refund->amount_santim]);

        return $refund;
    }

    private function authorize(Order $order, OrderStatus $from, OrderStatus $to, string $role): void
    {
        $allowed = match ($role) {
            // Customers can back out until the seller starts work (a paid order is then refunded).
            'customer' => $to === OrderStatus::Cancelled && in_array($from, [OrderStatus::PendingConfirmation, OrderStatus::Confirmed], true),
            // Sellers confirm first, then move forward (skipping steps is fine), or cancel before dispatch.
            'seller' => match (true) {
                $to === OrderStatus::Cancelled => $from->rank() <= OrderStatus::Ready->rank(),
                $from === OrderStatus::PendingConfirmation => $to === OrderStatus::Confirmed,
                $to === OrderStatus::Dispatched && $order->fulfillment === 'pickup' => false,
                default => $to->rank() > $from->rank() && $to !== OrderStatus::Confirmed,
            },
            // Admins only cancel; the seller runs fulfilment.
            'admin' => $to === OrderStatus::Cancelled,
            default => false,
        };

        if (! $allowed) {
            throw ValidationException::withMessages(['status' => 'This order cannot move from '.str_replace('_', ' ', $from->value).' to '.str_replace('_', ' ', $to->value).'.']);
        }
    }

    private function releaseStock(Order $order): void
    {
        $items = $order->items()->where('reserved_quantity', '>', 0)->orderBy('product_id')->get();
        foreach ($items as $item) {
            if ($item->product_id) {
                Product::withTrashed()->whereKey($item->product_id)->increment('stock_quantity', $item->reserved_quantity);
            }
            $item->reserved_quantity = 0;
            $item->save();
        }
    }

    private function record(Order $order, ?OrderStatus $from, OrderStatus $to, ?User $actor, string $role, ?string $note): void
    {
        $row = new OrderStatusHistory;
        $row->order_id = $order->id;
        $row->from_status = $from?->value;
        $row->to_status = $to->value;
        $row->actor_id = $actor?->id;
        $row->actor_role = $role;
        $row->note = $note;
        $row->save();
    }
}
