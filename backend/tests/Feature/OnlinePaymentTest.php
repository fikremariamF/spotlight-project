<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\SellerLedgerEntry;
use App\Models\Setting;
use App\Notifications\SmsChannel;
use App\Payments\FakeGateway;
use App\Payments\PaymentGateway;
use App\Payments\PaymentGatewayError;
use App\Payments\VerifyResult;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

class OnlinePaymentTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    /** @var list<array{user: int, kind: string, channel: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketplace.payments.gateway' => 'fake', 'marketplace.payments.window_minutes' => 30]);
        $this->app->forgetInstance(PaymentGateway::class);
        $this->setUpOrders();
        Cache::flush();
        Event::listen(NotificationSent::class, function (NotificationSent $e) {
            $this->sent[] = ['user' => $e->notifiable->id, 'kind' => $e->notification->kind, 'channel' => $e->channel === SmsChannel::class ? 'sms' : $e->channel];
        });
    }

    private function kinds(int $userId): array
    {
        return collect($this->sent)->where('user', $userId)->pluck('kind')->unique()->sort()->values()->all();
    }

    private function checkoutOnline(int $qty = 2, array $fulfillment = [], ?string $key = null): TestResponse
    {
        $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug, 'quantity' => $qty]);
        $expected = $this->getJson('/api/cart?address_id='.$this->address->id.'&'.http_build_query(['fulfillment' => $fulfillment]))->json('data.grand_total_santim');

        return $this->postJson('/api/checkout', [
            'address_id' => $this->address->id, 'payment_method' => 'online', 'fulfillment' => $fulfillment, 'expected_total_santim' => $expected,
        ], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function payAtProvider(string $txRef, string $outcome = 'paid'): TestResponse
    {
        return $this->as($this->buyer)->postJson("/api/payments/test/{$txRef}", ['outcome' => $outcome]);
    }

    public function test_online_checkout_waits_for_payment_out_of_the_stores_sight(): void
    {
        $res = $this->checkoutOnline(2)->assertCreated()
            ->assertJsonPath('data.payment_method', 'online')
            ->assertJsonPath('data.payment.status', 'awaiting')
            ->assertJsonPath('data.payment.payment.status', 'pending');
        $this->assertStringContainsString('/pay/test?tx_ref=', $res->json('data.payment.payment.checkout_url'));

        $order = Order::sole();
        $this->assertSame(OrderStatus::AwaitingPayment, $order->status);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame(48, $this->sofa->fresh()->stock_quantity, 'stock is held while the customer pays');
        $this->assertSame(0, CartItem::count());
        $this->assertNotNull($order->checkout->payment_due_at);
        $this->assertSame(2_030_000, Payment::sole()->amount_santim);

        // The store neither sees nor hears about an unpaid order.
        $sellerUser = $this->seller->user;
        $this->as($sellerUser)->getJson('/api/seller/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/seller/orders/{$order->id}")->assertNotFound();
        $this->postJson("/api/seller/orders/{$order->id}/confirm")->assertNotFound();
        $this->assertSame([], $this->kinds($sellerUser->id));
        $this->assertSame([], $this->kinds($this->buyer->id));

        // The customer sees it, with the deadline.
        $this->as($this->buyer)->getJson("/api/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.checkout_id', $order->checkout_id);
        $this->assertNotNull($this->getJson("/api/orders/{$order->id}")->json('data.payment_due_at'));
    }

    public function test_paying_sends_the_order_to_the_store(): void
    {
        $checkoutId = $this->checkoutOnline(2)->json('data.id');
        $payment = Payment::sole();

        $this->getJson("/api/checkouts/{$checkoutId}/payment")->assertOk()->assertJsonPath('data.status', 'awaiting');
        $this->payAtProvider($payment->tx_ref)->assertOk()->assertJsonPath('data.status', 'paid');

        $this->getJson("/api/checkouts/{$checkoutId}/payment")->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.can_pay', false)
            ->assertJsonPath('data.orders.0.status', 'pending_confirmation')
            ->assertJsonPath('data.orders.0.payment_status', 'paid')
            ->assertJsonPath('data.orders.0.paid_santim', 2_030_000);

        $order = Order::sole();
        $this->assertNotNull($order->paid_at);
        $this->assertSame(['awaiting_payment', 'pending_confirmation'], $order->history()->pluck('to_status')->all());
        $this->assertSame('system', $order->history()->get()->last()->actor_role);
        $this->assertNotNull($payment->fresh()->provider_reference);

        $this->assertSame(['order.new'], $this->kinds($this->seller->user->id));
        $this->assertSame(['checkout.placed'], $this->kinds($this->buyer->id));

        $this->as($this->seller->user)->getJson('/api/seller/orders')->assertOk()->assertJsonCount(1, 'data');
        // Paid online: the fee is fixed, confirming cannot change what was paid.
        $this->postJson("/api/seller/orders/{$order->id}/confirm", ['delivery_fee' => '999'])->assertOk()
            ->assertJsonPath('data.total_santim', 2_030_000);

        // Applying the same answer again changes nothing.
        app(PaymentService::class)->apply($payment->id, new VerifyResult('paid', 2_030_000, 'ETB', 'X', 'test'));
        $this->assertSame(3, $order->history()->count());
        $this->assertSame(0, Refund::count());
    }

    public function test_failed_payment_can_be_retried_and_recent_pages_are_reused(): void
    {
        $checkoutId = $this->checkoutOnline()->json('data.id');
        $first = Payment::sole();

        // Asking again within a few minutes reuses the same page.
        $this->postJson("/api/checkouts/{$checkoutId}/pay")->assertOk()->assertJsonPath('data.payment.tx_ref', $first->tx_ref);

        $this->payAtProvider($first->tx_ref, 'failed')->assertOk()->assertJsonPath('data.status', 'failed');
        $this->getJson("/api/checkouts/{$checkoutId}/payment")->assertOk()->assertJsonPath('data.status', 'awaiting')->assertJsonPath('data.can_pay', true);

        $second = $this->postJson("/api/checkouts/{$checkoutId}/pay")->assertOk()->json('data.payment.tx_ref');
        $this->assertNotSame($first->tx_ref, $second);
        $this->payAtProvider($second)->assertOk();
        $this->assertSame('paid', Order::sole()->payment_status);

        $this->postJson("/api/checkouts/{$checkoutId}/pay")->assertUnprocessable()->assertJsonPath('errors.payment.0', 'This order is already paid.');
    }

    public function test_online_needs_known_delivery_fees_and_a_working_gateway(): void
    {
        $this->sofa->forceFill(['delivery_fee_santim' => null])->save();
        $this->checkoutOnline()->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertSame(0, Order::count());
        $this->assertSame(1, CartItem::count(), 'the cart is kept');

        // Picking up removes the unknown fee.
        $this->checkoutOnline(2, [$this->seller->id => 'pickup'])->assertCreated();
        $this->assertSame('pickup', Order::sole()->fulfillment);

        Setting::updateOrCreate(['key' => 'online_payments_enabled'], ['value' => '0']);
        $this->getJson('/api/config')->assertJsonPath('online_payments.available', false);
        $this->checkoutOnline(1, [$this->seller->id => 'pickup'])->assertUnprocessable()->assertJsonPath('errors.payment_method.0', 'Online payment is not available right now.');

    }

    public function test_online_is_off_until_a_gateway_is_configured(): void
    {
        config(['marketplace.payments.gateway' => 'none']);
        $this->app->forgetInstance(PaymentGateway::class);
        $this->getJson('/api/config')->assertJsonPath('online_payments.available', false);
        $this->checkoutOnline()->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->assertSame(0, Order::count());
    }

    public function test_the_test_gateway_never_runs_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->app->forgetInstance(PaymentGateway::class);
        $gateway = app(PaymentGateway::class);
        $this->assertFalse($gateway->ready());
        $this->assertNotInstanceOf(FakeGateway::class, $gateway);
        $this->as($this->buyer)->getJson('/api/config')->assertJsonPath('online_payments.available', false);
        $this->postJson('/api/payments/test/BF-1-X', ['outcome' => 'paid'])->assertNotFound();
    }

    public function test_unpaid_checkouts_expire_release_stock_and_refill_the_cart(): void
    {
        $this->checkoutOnline(3);
        $order = Order::sole();
        $this->artisan('marketplace:expire-payments')->assertSuccessful();
        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status, 'not yet due');

        $this->travel(31)->minutes();
        $this->as($this->buyer)->getJson("/api/checkouts/{$order->checkout_id}/payment")->assertOk()->assertJsonPath('data.status', 'expired');
        $this->postJson("/api/checkouts/{$order->checkout_id}/pay")->assertUnprocessable();

        $this->artisan('marketplace:expire-payments')->expectsOutputToContain('Expired 1 unpaid checkout')->assertSuccessful();
        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('system', $order->cancelled_by);
        $this->assertSame(50, $this->sofa->fresh()->stock_quantity);
        $this->assertSame(3, CartItem::where('user_id', $this->buyer->id)->where('product_id', $this->sofa->id)->value('quantity'));
        $this->assertSame('expired', Payment::sole()->status);
        $this->assertSame(['checkout.expired'], $this->kinds($this->buyer->id));
        $this->assertSame([], $this->kinds($this->seller->user->id));

        // Running again does nothing.
        $this->artisan('marketplace:expire-payments')->expectsOutputToContain('Expired 0')->assertSuccessful();
        $this->getJson("/api/checkouts/{$order->checkout_id}/payment")->assertJsonPath('data.status', 'cancelled');
    }

    public function test_expiry_checks_with_the_provider_first(): void
    {
        $this->checkoutOnline();
        $payment = Payment::sole();
        // Paid at the provider, but the customer never came back to the site.
        FakeGateway::choose($payment->tx_ref, 'paid');
        $this->travel(31)->minutes();

        $this->artisan('marketplace:expire-payments')->expectsOutputToContain('1 turned out paid')->assertSuccessful();
        $this->assertSame(OrderStatus::PendingConfirmation, Order::sole()->status);
        $this->assertSame(0, Refund::count());
    }

    public function test_expiry_waits_one_more_window_when_the_provider_cannot_be_reached(): void
    {
        $this->checkoutOnline();
        $down = new class extends FakeGateway
        {
            public function verify(Payment $payment): VerifyResult
            {
                throw new PaymentGatewayError('provider down');
            }
        };
        $this->app->instance(PaymentGateway::class, $down);
        $this->app->forgetInstance(PaymentService::class);

        $this->travel(31)->minutes();
        $this->artisan('marketplace:expire-payments')->expectsOutputToContain('1 waiting for the payment provider')->assertSuccessful();
        $this->assertSame(OrderStatus::AwaitingPayment, Order::sole()->status, 'a payment may have gone through; do not cancel yet');
        $this->assertSame(48, $this->sofa->fresh()->stock_quantity);

        $this->travel(30)->minutes();
        $this->artisan('marketplace:expire-payments')->expectsOutputToContain('Expired 1')->assertSuccessful();
        $this->assertSame(OrderStatus::Cancelled, Order::sole()->status);
        $this->assertSame('expired', Payment::sole()->status);
    }

    public function test_a_payment_arriving_after_expiry_is_refunded(): void
    {
        $admin = $this->admin();
        $this->checkoutOnline();
        $payment = Payment::sole();
        $this->travel(31)->minutes();
        $this->artisan('marketplace:expire-payments')->assertSuccessful();

        $this->payAtProvider($payment->tx_ref)->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, Order::sole()->status);
        $refund = Refund::sole();
        $this->assertNull($refund->order_id);
        $this->assertSame($payment->id, $refund->payment_id);
        $this->assertSame(2_030_000, $refund->amount_santim);
        $this->assertSame('Paid after the order was cancelled', $refund->reason);
        $this->assertContains('refund.due', $this->kinds($this->buyer->id));
        $this->assertSame(['admin.refund_due'], $this->kinds($admin->id));

        // A second verify of the same payment does not create a second refund.
        app(PaymentService::class)->apply($payment->id, new VerifyResult('paid', 2_030_000, 'ETB'));
        $this->assertSame(1, Refund::count());
    }

    public function test_paying_twice_refunds_the_second_payment(): void
    {
        $checkoutId = $this->checkoutOnline()->json('data.id');
        $first = Payment::sole();
        $this->payAtProvider($first->tx_ref, 'failed');
        $second = Payment::where('tx_ref', $this->postJson("/api/checkouts/{$checkoutId}/pay")->assertOk()->json('data.payment.tx_ref'))->sole();

        // The customer had both pages open and both went through.
        $this->payAtProvider($second->tx_ref)->assertOk();
        app(PaymentService::class)->apply($first->id, new VerifyResult('paid', 2_030_000, 'ETB', 'R1'));

        $this->assertSame('paid', Order::sole()->payment_status);
        $this->assertSame('paid', $first->fresh()->status);
        $refund = Refund::sole();
        $this->assertSame($first->id, $refund->payment_id);
        $this->assertSame('This order was already paid by another payment', $refund->reason);
    }

    public function test_a_short_payment_is_refunded_and_an_overpayment_is_noted(): void
    {
        $this->checkoutOnline();
        $payment = Payment::sole();
        app(PaymentService::class)->apply($payment->id, new VerifyResult('paid', 1_000_000, 'ETB', 'SHORT'));
        $this->assertSame(OrderStatus::AwaitingPayment, Order::sole()->status);
        $this->assertSame(1_000_000, Refund::sole()->amount_santim);
        $this->assertStringContainsString('expected ETB 20,300.00', $payment->fresh()->problem);

        // Wrong currency is treated the same way.
        $this->as($this->buyer);
        $retry = $this->postJson('/api/checkouts/'.$payment->checkout_id.'/pay')->assertOk()->json('data.payment.tx_ref');
        $usd = Payment::where('tx_ref', $retry)->sole();
        app(PaymentService::class)->apply($usd->id, new VerifyResult('paid', 2_030_000, 'USD', 'USD1'));
        $this->assertSame(OrderStatus::AwaitingPayment, Order::sole()->status);
        $this->assertSame(2, Refund::count());

        $third = $this->postJson('/api/checkouts/'.$payment->checkout_id.'/pay')->assertOk()->json('data.payment.tx_ref');
        $over = Payment::where('tx_ref', $third)->sole();
        app(PaymentService::class)->apply($over->id, new VerifyResult('paid', 2_100_000, 'ETB', 'OVER'));
        $this->assertSame(OrderStatus::PendingConfirmation, Order::sole()->status);
        $this->assertNotNull($over->fresh()->problem);
        $this->assertSame(2, Refund::count());
        $this->assertSame('paid', Order::sole()->payment_status);
        $this->assertSame(2_030_000, Order::sole()->paid_santim, 'the order records what it cost, the extra stays on the payment note');
    }

    public function test_customer_cancels_before_paying(): void
    {
        $checkoutId = $this->checkoutOnline(2)->json('data.id');
        $order = Order::sole();

        $this->postJson("/api/orders/{$order->id}/cancel", ['reason' => 'Changed my mind'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_by', 'customer');
        $this->assertSame(50, $this->sofa->fresh()->stock_quantity);
        $this->assertSame('cancelled', Payment::sole()->status);
        $this->assertSame(1, CartItem::count(), 'items go back to the cart to try again');
        $this->assertSame(0, Refund::count());

        $this->postJson("/api/checkouts/{$checkoutId}/cancel")->assertUnprocessable();
        $this->postJson("/api/checkouts/{$checkoutId}/pay")->assertUnprocessable();
    }

    public function test_cancel_loses_to_a_payment_that_already_went_through(): void
    {
        $checkoutId = $this->checkoutOnline()->json('data.id');
        FakeGateway::choose(Payment::sole()->tx_ref, 'paid');

        $this->as($this->buyer)->postJson("/api/checkouts/{$checkoutId}/cancel")->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'This order was paid, so it went to the store. Cancel it from the order page to get a refund.');
        $this->assertSame(OrderStatus::PendingConfirmation, Order::sole()->status);
    }

    public function test_cancelling_a_paid_order_creates_a_refund_an_admin_completes(): void
    {
        $this->checkoutOnline();
        $this->payAtProvider(Payment::sole()->tx_ref);
        $order = Order::sole();

        $this->as($this->seller->user)->postJson("/api/seller/orders/{$order->id}/cancel", ['reason' => 'Out of fabric'])->assertOk()
            ->assertJsonMissingPath('data.refund');
        $order->refresh();
        $this->assertSame('refund_due', $order->payment_status);
        $refund = Refund::sole();
        $this->assertSame($order->id, $refund->order_id);
        $this->assertSame(2_030_000, $refund->amount_santim);
        $this->assertSame('Cancelled by the store', $refund->reason);
        $this->assertContains('refund.due', $this->kinds($this->buyer->id));
        $this->as($this->buyer)->getJson("/api/orders/{$order->id}")->assertJsonPath('data.refund.status', 'due');

        $admin = $this->admin();
        $this->as($admin)->getJson('/api/admin/refunds')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.order.number', $order->number);
        $this->postJson("/api/admin/refunds/{$refund->id}/complete", ['method' => 'telebirr'])->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->postJson("/api/admin/refunds/{$refund->id}/complete", ['method' => 'chapa', 'reference' => 'RF-123'])->assertOk()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.refunded_by', $admin->name);
        $this->postJson("/api/admin/refunds/{$refund->id}/complete", ['method' => 'cash'])->assertUnprocessable();
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertContains('refund.sent', $this->kinds($this->buyer->id));
        $this->getJson('/api/admin/refunds')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/refunds?status=refunded')->assertJsonCount(1, 'data');

        // Not for other roles.
        $this->as($this->buyer)->getJson('/api/admin/refunds')->assertForbidden();
    }

    public function test_cash_orders_cancelled_never_create_refunds(): void
    {
        $order = $this->placeOrder();
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$order->id}/cancel", ['reason' => 'No stock'])->assertOk();
        $this->assertSame(0, Refund::count());
    }

    public function test_delivered_online_sale_is_owed_to_the_seller_and_paid_out(): void
    {
        $this->checkoutOnline(2);
        $this->payAtProvider(Payment::sole()->tx_ref);
        $order = $this->deliver(Order::sole());
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame('paid', $order->payment_status);

        // Commission 10% of 20,000 = 2,000; the platform holds 20,300 (with delivery); owes 18,300.
        $this->assertSame(['commission' => 200_000, 'sale' => -2_030_000], SellerLedgerEntry::where('order_id', $order->id)->pluck('amount_santim', 'type')->all());
        $this->as($this->seller->user)->getJson('/api/seller/balance')->assertOk()
            ->assertJsonPath('data.balance_santim', -1_830_000)
            ->assertJsonPath('data.owed_to_seller_santim', 1_830_000)
            ->assertJsonPath('data.online_sales_total_santim', 2_030_000)
            ->assertJsonPath('data.over_limit', false);

        $admin = $this->admin();
        $this->as($admin)->getJson('/api/admin/settlements?owed=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.payable_santim', 1_830_000);
        $this->getJson('/api/admin/settlements?owing=1')->assertOk()->assertJsonCount(0, 'data');
        $url = "/api/admin/settlements/{$this->seller->id}/payouts";
        $this->postJson($url, ['amount' => '18300.01', 'method' => 'bank_transfer', 'reference' => 'TR1'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson($url, ['amount' => '10000', 'method' => 'bank_transfer'])->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->postJson($url, ['amount' => '10000', 'method' => 'bank_transfer', 'reference' => 'tr1'])->assertCreated();
        $this->postJson($url, ['amount' => '100', 'method' => 'bank_transfer', 'reference' => 'TR1'])->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->postJson($url, ['amount' => '8300', 'method' => 'cash'])->assertCreated()->assertJsonPath('data.balance_santim', 0);
        $this->postJson($url, ['amount' => '1', 'method' => 'cash'])->assertUnprocessable()->assertJsonPath('errors.amount.0', 'The marketplace does not owe this store anything right now.');
        $this->assertContains('balance.payout', $this->kinds($this->seller->user->id));

        // A seller paying commission cannot be recorded against an online credit either.
        $this->postJson("/api/admin/settlements/{$this->seller->id}/payments", ['amount' => '1', 'method' => 'cash'])->assertUnprocessable();
        $this->getJson('/api/admin/payments')->assertOk()->assertJsonPath('data.0.status', 'paid')->assertJsonPath('meta.gateway', 'fake');
    }

    public function test_a_store_over_its_credit_limit_can_still_fill_paid_online_orders(): void
    {
        Setting::updateOrCreate(['key' => 'commission_credit_limit_birr'], ['value' => '500']);
        $this->deliver($this->placeOrder(1));
        $this->assertTrue($this->as($this->seller->user)->getJson('/api/seller/balance')->json('data.over_limit'));

        $cash = $this->placeOrder(1);
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$cash->id}/confirm")->assertUnprocessable();

        $this->checkoutOnline(1);
        $this->payAtProvider(Payment::sole()->tx_ref)->assertOk();
        $online = Order::where('payment_method', 'online')->sole();
        $this->deliver($online);
        // 1,000 commission on the cash sale + 1,000 on the online one - 10,300 held for the store.
        $this->assertSame(-830_000, $this->as($this->seller->user)->getJson('/api/seller/balance')->json('data.balance_santim'));
    }

    public function test_one_payment_covers_orders_from_two_stores(): void
    {
        $seller2 = $this->approvedSeller();
        $chair = Product::factory()->published()->create([
            'seller_profile_id' => $seller2->id, 'category_id' => $this->category()->id,
            'price_santim' => 300_000, 'stock_quantity' => 5, 'delivery_fee_santim' => 0,
        ]);
        $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $chair->slug, 'quantity' => 1])->assertCreated();
        $checkoutId = $this->checkoutOnline(1)->assertCreated()->assertJsonCount(2, 'data.orders')->json('data.id');
        // 10,000 + 300 delivery for the sofa, 3,000 + free delivery for the chair.
        $this->assertSame(1_330_000, Payment::sole()->amount_santim);

        $this->payAtProvider(Payment::sole()->tx_ref)->assertOk();
        $orders = Order::where('checkout_id', $checkoutId)->orderBy('id')->get();
        $this->assertSame(['pending_confirmation', 'pending_confirmation'], $orders->pluck('status')->map->value->all());
        $this->assertSame([300_000, 1_030_000], $orders->pluck('paid_santim')->sort()->values()->all());
        $this->assertSame(['order.new'], $this->kinds($this->seller->user->id));
        $this->assertSame(['order.new'], $this->kinds($seller2->user->id));

        // The chair's store cancels: only its part is refunded, the sofa goes ahead.
        $chairOrder = $orders->firstWhere('seller_profile_id', $seller2->id);
        $this->as($seller2->user)->postJson("/api/seller/orders/{$chairOrder->id}/cancel", ['reason' => 'Sold in the shop'])->assertOk();
        $this->assertSame(300_000, Refund::sole()->amount_santim);
        $this->assertSame(OrderStatus::PendingConfirmation, $orders->firstWhere('seller_profile_id', $this->seller->id)->fresh()->status);

        // An unpaid checkout with two stores is cancelled as a whole.
        $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $chair->slug, 'quantity' => 1])->assertCreated();
        $second = $this->checkoutOnline(1)->json('data.id');
        $one = Order::where('checkout_id', $second)->first();
        $this->postJson("/api/orders/{$one->id}/cancel", ['reason' => 'Changed my mind'])->assertOk();
        $this->assertSame(['cancelled', 'cancelled'], Order::where('checkout_id', $second)->pluck('status')->map->value->all());
        $this->assertSame(2, CartItem::where('user_id', $this->buyer->id)->count());
    }

    public function test_awaiting_orders_cannot_be_moved_by_stores_and_admins_cancel_the_whole_checkout(): void
    {
        $this->checkoutOnline();
        $order = Order::sole();
        $this->expectsNoStatusChange($order, fn () => app(OrderService::class)->transition($order->id, OrderStatus::Confirmed, $this->seller->user, 'seller'));

        $this->as($this->admin())->postJson("/api/admin/orders/{$order->id}/cancel", ['reason' => 'Fraud check'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancelled_by', 'admin');
        $this->assertSame(0, CartItem::count(), 'an admin cancel does not refill the cart');
        $this->assertSame(['checkout.expired'], $this->kinds($this->buyer->id));
    }

    public function test_retrying_the_same_checkout_request_returns_the_same_payment(): void
    {
        $key = (string) Str::uuid();
        $first = $this->checkoutOnline(1, [], $key)->assertCreated();
        $this->postJson('/api/checkout', ['address_id' => $this->address->id, 'payment_method' => 'online', 'expected_total_santim' => 1], ['Idempotency-Key' => $key])
            ->assertOk()->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.payment.payment.tx_ref', $first->json('data.payment.payment.tx_ref'));
        $this->assertSame(1, Payment::count());
    }

    public function test_payment_pages_are_private(): void
    {
        $checkoutId = $this->checkoutOnline()->json('data.id');
        $txRef = Payment::sole()->tx_ref;
        $other = $this->customer();
        $this->as($other)->getJson("/api/checkouts/{$checkoutId}/payment")->assertNotFound();
        $this->postJson("/api/checkouts/{$checkoutId}/pay")->assertNotFound();
        $this->postJson("/api/checkouts/{$checkoutId}/cancel")->assertNotFound();
        $this->postJson("/api/payments/test/{$txRef}", ['outcome' => 'paid'])->assertNotFound();
        $this->getJson("/api/payments/test/{$txRef}")->assertNotFound();
        $this->as($this->seller->user)->getJson("/api/checkouts/{$checkoutId}/payment")->assertForbidden();
        $this->assertSame('pending', Payment::sole()->status);
    }

    public function test_reports_leave_out_unpaid_online_orders(): void
    {
        $this->checkoutOnline();
        $this->travel(31)->minutes();
        $this->artisan('marketplace:expire-payments');
        $this->placeOrder(1);

        $report = $this->as($this->admin())->getJson('/api/admin/reports/summary')->assertOk();
        $this->assertSame(1, $report->json('data.placed.orders'));
        $this->assertSame(0, $report->json('data.cancelled.orders'));
        $this->assertSame(1, $report->json('data.online.unpaid_cancelled_orders'));
    }

    public function test_cart_refill_skips_deleted_listings_and_full_carts(): void
    {
        $this->checkoutOnline(2);
        $this->sofa->delete();
        $this->travel(31)->minutes();
        $this->artisan('marketplace:expire-payments')->assertSuccessful();
        $this->assertSame(0, CartItem::count());
        $this->assertSame(OrderStatus::Cancelled, Order::sole()->status);
        $this->assertSame(50, Product::withTrashed()->find($this->sofa->id)->stock_quantity);
    }

    private function expectsNoStatusChange(Order $order, \Closure $attempt): void
    {
        try {
            $attempt();
            $this->fail('The transition should have been refused.');
        } catch (ValidationException $e) {
            $this->assertSame('This order is waiting for online payment.', $e->errors()['status'][0]);
        }
        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);
        $this->assertInstanceOf(Checkout::class, $order->checkout);
    }
}
