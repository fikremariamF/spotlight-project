<?php

namespace Tests\Feature;

use App\Enums\SellerStatus;
use App\Models\Order;
use App\Models\Setting;
use App\Payments\ChapaGateway;
use App\Payments\FakeGateway;
use App\Payments\NullGateway;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MakesSupportData;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

/**
 * Fixes from the security review: what a store may still see and do, delivery fees a store may not set,
 * payment test modes on a live site, and caps on calls that cost money or reveal data.
 */
class SecurityFixesTest extends TestCase
{
    use MakesSupportData, PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
    }

    private function storeView(Order $order): array
    {
        return $this->as($this->seller->user)->getJson("/api/seller/orders/{$order->id}")->assertOk()->json('data');
    }

    public function test_a_suspended_store_cannot_move_orders_forward_but_can_still_cancel(): void
    {
        $order = $this->placeOrder(1);
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$order->id}/confirm")->assertOk();
        $this->seller->forceFill(['status' => SellerStatus::Suspended])->save();

        $this->as($this->seller->user)->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('confirmed', $order->fresh()->status->value);

        $this->postJson("/api/seller/orders/{$order->id}/cancel", ['reason' => 'Store closed'])->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status->value);
    }

    public function test_the_store_sees_the_delivery_address_only_while_it_can_deliver(): void
    {
        $order = $this->placeOrder(1);
        // Before the store accepts the order it sees where (area, floor, lift, distance), not who or the street.
        $address = $this->storeView($order)['delivery_address'];
        $this->assertTrue($address['contact_hidden']);
        $this->assertSame($this->area()->name, $address['area']);
        foreach (['recipient_name', 'phone', 'address_line', 'landmark', 'latitude', 'longitude'] as $field) {
            $this->assertNull($address[$field], $field);
        }
        $this->as($this->seller->user)->getJson('/api/seller/orders')->assertOk()->assertJsonMissing(['phone' => '+251911000111'])
            ->assertJsonMissing(['address_line' => 'House 12']);

        $this->as($this->seller->user)->postJson("/api/seller/orders/{$order->id}/confirm")->assertOk()
            ->assertJsonPath('data.delivery_address.address_line', 'House 12')
            ->assertJsonPath('data.delivery_address.contact_hidden', false);

        // Suspended: hidden, and back once the store is active again.
        $this->seller->forceFill(['status' => SellerStatus::Suspended])->save();
        $this->assertNull($this->storeView($order)['delivery_address']);
        $this->seller->forceFill(['status' => SellerStatus::Approved])->save();
        $this->assertSame('+251911000111', $this->storeView($order)['delivery_address']['phone']);

        // Delivered: the store keeps the area but no longer the customer's contact.
        foreach (['processing', 'ready', 'dispatched'] as $status) {
            $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => $status])->assertOk();
            $this->assertSame('House 12', $this->storeView($order)['delivery_address']['address_line'], $status);
        }
        $this->postJson("/api/seller/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();
        $this->assertNull($this->storeView($order)['delivery_address']['phone']);
        $this->as($this->buyer)->getJson("/api/orders/{$order->id}")->assertOk()->assertJsonPath('data.delivery_address.address_line', 'House 12')
            ->assertJsonPath('data.delivery_address.contact_hidden', false);
    }

    public function test_a_cancelled_order_hides_the_address_from_the_store_for_good(): void
    {
        $order = $this->placeOrder(1);
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$order->id}/confirm")->assertOk();

        $this->as($this->buyer)->postJson("/api/orders/{$order->id}/cancel", ['reason' => 'Changed my mind'])->assertOk();
        $this->assertNull($this->storeView($order)['delivery_address']);
        $this->as($this->buyer)->getJson("/api/orders/{$order->id}")->assertOk()->assertJsonPath('data.delivery_address.address_line', 'House 12');
        $this->as($this->admin())->getJson("/api/admin/orders/{$order->id}")->assertOk()->assertJsonPath('data.delivery_address.address_line', 'House 12');
    }

    private function proposal(?string $fee): TestResponse
    {
        $this->seller->forceFill(['offers_custom_orders' => true])->save();
        $ticket = $this->actAs($this->buyer)->postJson('/api/support/tickets', [
            'topic' => 'custom', 'seller_slug' => $this->seller->slug, 'body' => 'A wardrobe please',
        ])->assertCreated()->json('data.id');
        $request = $this->actAs($this->admin())->postJson('/api/admin/support/requests', [
            'support_ticket_id' => $ticket, 'seller_profile_id' => $this->seller->id, 'topic' => 'price',
            'subject' => 'Wardrobe price', 'body' => 'How much for a walnut wardrobe?',
        ])->assertCreated()->json('data.id');

        return $this->actAs($this->seller->user)->postJson("/api/seller/requests/$request/messages", [
            'body' => 'Here is our price.',
            'proposal' => ['price' => '20000', 'quantity' => 1, 'delivery_fee' => $fee, 'valid_until' => now()->addDays(5)->toDateString()],
        ]);
    }

    public function test_a_proposal_cannot_carry_a_delivery_fee_while_the_marketplace_sets_them(): void
    {
        Setting::updateOrCreate(['key' => 'stores_set_delivery_fee'], ['value' => '0']);

        $this->proposal('5000')->assertUnprocessable()->assertJsonValidationErrors('delivery_fee');
        $this->proposal('0')->assertCreated()->assertJsonPath('data.messages.1.proposal.delivery_fee_santim', 0);
        $this->proposal(null)->assertCreated()->assertJsonPath('data.messages.1.proposal.delivery_fee_santim', null);
    }

    public function test_an_earlier_quote_fee_is_ignored_once_the_switch_is_off(): void
    {
        Setting::updateOrCreate(['key' => 'stores_set_delivery_fee'], ['value' => '1']);
        $chat = $this->oldConversation($this->buyer, $this->seller);
        $quoteId = $this->oldQuote($chat, ['delivery_fee_santim' => 500_000])->id;
        $this->actAs($this->buyer)->postJson("/api/quotes/{$quoteId}/accept")->assertOk();
        $this->postJson("/api/quotes/{$quoteId}/add-to-cart")->assertCreated();

        $fee = fn () => $this->getJson('/api/cart?address_id='.$this->address->id)->json('data.groups.0.delivery_fee_santim');
        $this->assertSame(500_000, $fee());

        // The quote's 5,000 birr no longer applies: the marketplace's own price does (here: the store confirms it).
        Setting::updateOrCreate(['key' => 'stores_set_delivery_fee'], ['value' => '0']);
        $this->assertNotSame(500_000, $fee());
    }

    private function gateway(string $env, string $name, ?string $key = null): PaymentGateway
    {
        $this->app['env'] = $env;
        config(['marketplace.payments.gateway' => $name, 'services.chapa.secret_key' => $key]);
        $this->app->forgetInstance(PaymentGateway::class);

        return $this->app->make(PaymentGateway::class);
    }

    public function test_payment_test_modes_are_refused_on_a_live_site(): void
    {
        try {
            $this->assertInstanceOf(FakeGateway::class, $this->gateway('staging', 'fake'));
            $this->assertInstanceOf(FakeGateway::class, $this->gateway('local', 'fake'));
            foreach (['production', 'prod', 'live', ''] as $env) {
                $this->assertInstanceOf(NullGateway::class, $this->gateway($env, 'fake'), "fake on '{$env}'");
                $this->assertFalse($this->gateway($env, 'fake')->ready());
                $this->assertInstanceOf(NullGateway::class, $this->gateway($env, 'chapa', 'CHASECK_TEST-abc'), "test key on '{$env}'");
                $this->assertInstanceOf(NullGateway::class, $this->gateway($env, 'chapa', ' CHASECK_TEST-abc'), 'spaces do not hide it');
                $this->assertInstanceOf(ChapaGateway::class, $this->gateway($env, 'chapa', 'CHASECK-live-abc'));
            }
            $this->assertInstanceOf(ChapaGateway::class, $this->gateway('staging', 'chapa', 'CHASECK_TEST-abc'), 'test sites may use test keys');
        } finally {
            $this->app['env'] = 'testing';
            $this->app->forgetInstance(PaymentGateway::class);
        }
    }

    public function test_road_distance_lookups_stop_at_the_hourly_cap_and_fall_back_to_the_estimate(): void
    {
        config(['services.google_maps.server_key' => 'test-server-key', 'services.google_maps.routes_per_hour' => 2]);
        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['distanceMeters' => 12_345]]])]);
        $this->sofa->forceFill(['delivery_fee_santim' => null])->save();
        $this->seller->forceFill(['latitude' => 9.03, 'longitude' => 38.74])->save();
        $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug, 'quantity' => 1])->assertCreated();

        $estimated = [];
        foreach ([8.99, 8.98, 8.97] as $lat) {
            $this->address->forceFill(['latitude' => $lat, 'longitude' => 38.79])->save();
            $estimated[] = $this->getJson('/api/cart?address_id='.$this->address->id)->json('data.groups.0.delivery_distance.estimated');
        }
        $this->assertSame([false, false, true], $estimated);
        Http::assertSentCount(2);

        // A pin moved by a few metres is the same trip: no new lookup.
        $this->travel(61)->minutes();
        $this->address->forceFill(['latitude' => 8.99001, 'longitude' => 38.79001])->save();
        $this->assertFalse($this->getJson('/api/cart?address_id='.$this->address->id)->json('data.groups.0.delivery_distance.estimated'));
        Http::assertSentCount(2);
    }

    public function test_delivery_estimates_and_saved_addresses_are_capped(): void
    {
        $this->as($this->buyer);
        for ($i = 0; $i < 30; $i++) {
            $this->getJson("/api/products/{$this->sofa->slug}/delivery-estimate?address_id={$this->address->id}")->assertOk();
        }
        $this->getJson("/api/products/{$this->sofa->slug}/delivery-estimate?address_id={$this->address->id}")->assertTooManyRequests();

        $payload = ['recipient_name' => 'Abebe', 'phone' => '+251911000111', 'area_id' => $this->area('bole')->id, 'address_line' => 'House 1'];
        for ($i = 0; $i < 30; $i++) {
            $this->assertNotSame(429, $this->postJson('/api/addresses', $payload + ['label' => 'A'.Str::random(4)])->status());
        }
        $this->postJson('/api/addresses', $payload)->assertTooManyRequests();
        $this->putJson("/api/addresses/{$this->address->id}", $payload)->assertTooManyRequests();
    }
}
