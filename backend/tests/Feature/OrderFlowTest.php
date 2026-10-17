<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private Address $address;

    private SellerProfile $seller;

    private Product $sofa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->seller = $this->approvedSeller();
        $this->sofa = Product::factory()->published()->create([
            'seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id,
            'price_santim' => 1_000_000, 'stock_quantity' => 5, 'delivery_fee_santim' => null, 'pickup_available' => true,
        ]);
        $this->buyer = $this->customer();
        $this->address = $this->buyer->addresses()->forceCreate([
            'user_id' => $this->buyer->id, 'recipient_name' => 'Abebe', 'phone' => '+251911000111',
            'area_id' => $this->area('bole')->id, 'address_line' => 'House 12', 'is_default' => true,
        ]);
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user->fresh());

        return $this;
    }

    private function placeOrder(int $qty = 2, array $fulfillment = []): Order
    {
        $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug, 'quantity' => $qty])->assertCreated();
        $expected = $this->getJson('/api/cart?'.http_build_query(['address_id' => $this->address->id, 'fulfillment' => $fulfillment]))->json('data.grand_total_santim');
        $this->postJson('/api/checkout', [
            'address_id' => $this->address->id, 'payment_method' => 'cod', 'expected_total_santim' => $expected, 'fulfillment' => $fulfillment,
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        return Order::latest('id')->first();
    }

    public function test_full_delivery_flow_with_seller_set_fee(): void
    {
        $order = $this->placeOrder();
        $this->as($this->seller->user);

        $this->getJson('/api/seller/orders')->assertOk()->assertJsonPath('data.0.number', $order->number)
            ->assertJsonPath('data.0.commission_santim', 200_000);
        // The street and phone come with accepting the order.
        $this->getJson("/api/seller/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.delivery_address.address_line', null)
            ->assertJsonPath('data.delivery_address.contact_hidden', true)
            ->assertJsonPath('data.delivery_fee_confirmed', false);

        // Fee is required when nobody set one.
        $this->postJson("/api/seller/orders/{$order->id}/confirm")->assertUnprocessable()->assertJsonValidationErrors('delivery_fee');
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'processing'])->assertUnprocessable();
        $this->postJson("/api/seller/orders/{$order->id}/confirm", ['delivery_fee' => '450', 'note' => 'Ready Friday'])->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.delivery_fee_santim', 45_000)
            ->assertJsonPath('data.total_santim', 2_045_000)
            ->assertJsonPath('data.seller_note', 'Ready Friday')
            ->assertJsonPath('data.delivery_address.address_line', 'House 12');
        $this->postJson("/api/seller/orders/{$order->id}/confirm", ['delivery_fee' => '1'])->assertUnprocessable();

        foreach (['processing', 'ready', 'dispatched'] as $status) {
            $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => $status])->assertOk()->assertJsonPath('data.status', $status);
        }
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'processing'])->assertUnprocessable();
        $this->postJson("/api/seller/orders/{$order->id}/cancel", ['reason' => 'Changed mind'])->assertUnprocessable();
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonCount(6, 'data.history');
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'delivered'])->assertUnprocessable();

        // Delivered stock stays sold.
        $this->assertSame(3, $this->sofa->fresh()->stock_quantity);

        $this->as($this->buyer)->getJson("/api/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonMissingPath('data.commission_santim')
            ->assertJsonPath('data.seller.store_name', $this->seller->store_name);

        $this->getJson('/api/orders')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_seller_may_skip_steps_but_pickup_never_dispatches(): void
    {
        $order = $this->placeOrder(1, [$this->seller->id => 'pickup']);
        $this->as($this->seller->user);
        $this->postJson("/api/seller/orders/{$order->id}/confirm")->assertOk();
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'dispatched'])->assertUnprocessable();
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'ready'])->assertOk();
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'confirmed'])->assertUnprocessable();
    }

    public function test_customer_cancel_releases_stock_only_before_work_starts(): void
    {
        $order = $this->placeOrder(2);
        $this->assertSame(3, $this->sofa->fresh()->stock_quantity);

        $this->postJson("/api/orders/{$order->id}/cancel", ['reason' => ''])->assertUnprocessable();
        $this->postJson("/api/orders/{$order->id}/cancel", ['reason' => 'Found another one'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_by', 'customer');
        $this->assertSame(5, $this->sofa->fresh()->stock_quantity);
        // Cancelling twice never returns the stock twice.
        $this->postJson("/api/orders/{$order->id}/cancel", ['reason' => 'Again'])->assertUnprocessable();
        $this->assertSame(5, $this->sofa->fresh()->stock_quantity);

        $second = $this->placeOrder(1);
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$second->id}/confirm", ['delivery_fee' => '0'])->assertOk()
            ->assertJsonPath('data.delivery_fee_santim', 0);
        $this->postJson("/api/seller/orders/{$second->id}/status", ['status' => 'processing'])->assertOk();
        $this->as($this->buyer)->postJson("/api/orders/{$second->id}/cancel", ['reason' => 'Too slow'])->assertUnprocessable();
    }

    public function test_seller_and_admin_cancellation_limits(): void
    {
        $order = $this->placeOrder(1);
        $this->as($this->seller->user);
        $this->postJson("/api/seller/orders/{$order->id}/confirm", ['delivery_fee' => '300'])->assertOk();
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'ready'])->assertOk();
        $this->postJson("/api/seller/orders/{$order->id}/cancel", ['reason' => 'Damaged in workshop'])->assertOk()
            ->assertJsonPath('data.cancelled_by', 'seller');
        $this->assertSame(5, $this->sofa->fresh()->stock_quantity);

        $order = $this->placeOrder(1);
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$order->id}/confirm", ['delivery_fee' => '300'])->assertOk();
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'dispatched'])->assertOk();
        $this->postJson("/api/seller/orders/{$order->id}/cancel", ['reason' => 'Too late'])->assertUnprocessable();

        $this->as($this->admin());
        $this->getJson('/api/admin/orders?q='.strtolower($order->number))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer.email', $this->buyer->email);
        $this->postJson("/api/admin/orders/{$order->id}/cancel", ['reason' => 'Customer refused at the door'])->assertOk()
            ->assertJsonPath('data.cancelled_by', 'admin');
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.cancelled', 'subject_id' => $order->id]);
        $stock = $this->sofa->fresh()->stock_quantity;

        // Final orders stay final: no second cancel (which would release stock twice), no reopening.
        $this->postJson("/api/admin/orders/{$order->id}/cancel", ['reason' => 'Again'])->assertUnprocessable();
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'delivered'])->assertUnprocessable();
        $this->assertSame($stock, $this->sofa->fresh()->stock_quantity);

        $order = $this->placeOrder(1);
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$order->id}/confirm", ['delivery_fee' => '300'])->assertOk();
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();
        $this->as($this->admin())->postJson("/api/admin/orders/{$order->id}/cancel", ['reason' => 'Too late'])->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_orders_are_private(): void
    {
        $order = $this->placeOrder(1);

        $this->as($this->customer());
        $this->getJson("/api/orders/{$order->id}")->assertNotFound();
        $this->postJson("/api/orders/{$order->id}/cancel", ['reason' => 'Not mine'])->assertNotFound();
        $this->getJson('/api/orders')->assertJsonCount(0, 'data');

        $other = $this->approvedSeller();
        $this->as($other->user);
        $this->getJson("/api/seller/orders/{$order->id}")->assertNotFound();
        $this->postJson("/api/seller/orders/{$order->id}/confirm", ['delivery_fee' => '1'])->assertNotFound();
        $this->postJson("/api/seller/orders/{$order->id}/cancel", ['reason' => 'Not mine'])->assertNotFound();
        $this->getJson('/api/seller/orders')->assertJsonCount(0, 'data');

        $this->as($this->buyer)->getJson("/api/seller/orders/{$order->id}")->assertForbidden();
        $this->getJson("/api/admin/orders/{$order->id}")->assertForbidden();
        $this->assertSame('pending_confirmation', $order->fresh()->status->value);
    }

    public function test_suspended_store_cannot_confirm_new_orders(): void
    {
        $order = $this->placeOrder(1);
        $this->seller->forceFill(['status' => 'suspended'])->save();
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$order->id}/confirm", ['delivery_fee' => '1'])->assertUnprocessable();
        // But can still cancel so the customer is not left waiting.
        $this->postJson("/api/seller/orders/{$order->id}/cancel", ['reason' => 'Store closed'])->assertOk();
    }

    public function test_commission_rate_change_affects_new_orders_only(): void
    {
        $first = $this->placeOrder(1);
        $this->as($this->admin());
        $this->putJson('/api/admin/settings', ['commission_rate_percent' => '12.5'])->assertOk()->assertJsonPath('data.commission_rate_percent', 12.5);
        $this->putJson('/api/admin/settings', ['commission_rate_percent' => '51'])->assertUnprocessable();
        $this->putJson('/api/admin/settings', ['commission_rate_percent' => '-1'])->assertUnprocessable();
        $this->putJson('/api/admin/settings', ['commission_rate_percent' => '10.123'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);

        $second = $this->placeOrder(1);
        $this->assertSame(1000, $first->fresh()->commission_rate_bp);
        $this->assertSame(100_000, $first->fresh()->commission_santim);
        $this->assertSame(1250, $second->commission_rate_bp);
        $this->assertSame(125_000, $second->commission_santim);

        $this->as($this->seller->user)->putJson('/api/admin/settings', ['commission_rate_percent' => '0'])->assertForbidden();
    }

    public function test_admin_summary_reports_delivered_money_only(): void
    {
        $delivered = $this->placeOrder(1);
        $this->placeOrder(1);
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$delivered->id}/confirm", ['delivery_fee' => '500'])->assertOk();
        $this->postJson("/api/seller/orders/{$delivered->id}/status", ['status' => 'delivered'])->assertOk();
        $this->getJson('/api/seller/orders/summary')->assertOk()
            ->assertJsonPath('data.delivered_sales_santim', 1_050_000)
            ->assertJsonPath('data.commission_on_delivered_santim', 100_000);

        $this->as($this->admin())->getJson('/api/admin/summary')->assertOk()
            ->assertJsonPath('data.orders.delivered', 1)
            ->assertJsonPath('data.orders.pending_confirmation', 1)
            ->assertJsonPath('data.money.delivered_gross_santim', 1_050_000)
            ->assertJsonPath('data.money.delivered_items_santim', 1_000_000)
            ->assertJsonPath('data.money.commission_earned_santim', 100_000)
            ->assertJsonPath('data.money.open_orders_value_santim', 1_000_000);
    }

    public function test_commission_rounding(): void
    {
        $this->assertSame(0, Order::commissionFor(0, 1000));
        $this->assertSame(1, Order::commissionFor(5, 1000)); // 0.5 santim rounds up
        $this->assertSame(0, Order::commissionFor(4, 1000));
        $this->assertSame(108_754, Order::commissionFor(1_450_050, 750));
        // Largest possible line (quote max price x max quantity) at the max rate does not overflow.
        $this->assertSame(499_999_999_999_500, Order::commissionFor(999_999_999_999_000, 5000));
        // 30 lines at that size would overflow a naive total * rate.
        $this->assertSame(15_000_000_000_000_000, Order::commissionFor(30_000_000_000_000_000, 5000));
        $this->assertSame(1, Order::commissionFor(10_005, 1)); // 1.0005 santim -> 1
    }
}
