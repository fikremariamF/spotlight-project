<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Setting;
use App\Services\DistancePricing;
use App\Support\MapPin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

class DeliveryDistanceTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    // Merkato (store) and Bole (customer), about 6.7 km apart in a straight line.
    private const STORE = [9.0300, 38.7400];

    private const HOME = [8.9950, 38.7900];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrders();
        config([
            'services.google_maps.server_key' => null,
            'marketplace.distance.base_fee_birr' => 150,
            'marketplace.distance.per_km_birr' => 25,
            'marketplace.distance.max_km' => 40,
            'marketplace.distance.per_floor_birr' => 100,
            'marketplace.distance.road_factor' => 1.3,
        ]);
        // Distance pricing stands in for the listing's own fee only when the listing has none.
        $this->sofa->forceFill(['delivery_fee_santim' => null])->save();
        $this->seller->forceFill(['latitude' => self::STORE[0], 'longitude' => self::STORE[1]])->save();
        $this->address->forceFill(['latitude' => self::HOME[0], 'longitude' => self::HOME[1]])->save();
        // A zone fee exists too, so each test shows which one won.
        $zone = DeliveryZone::create(['name' => 'Central', 'fee_santim' => 77_700, 'min_days' => 1, 'max_days' => 2, 'is_active' => true]);
        $this->area('bole')->forceFill(['delivery_zone_id' => $zone->id])->save();
    }

    private function expectedFee(int $meters): int
    {
        return (int) (ceil((15_000 + intdiv(2_500 * $meters + 999, 1000)) / 1000) * 1000);
    }

    private function cart(?Address $address = null): array
    {
        $address ??= $this->address;
        $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug, 'quantity' => 1]);

        return $this->getJson('/api/cart?address_id='.$address->id)->assertOk()->json('data');
    }

    public function test_straight_line_estimate_without_a_google_key(): void
    {
        $straight = MapPin::straightMeters(...[...self::STORE, ...self::HOME]);
        $meters = (int) round($straight * 1.3);
        Http::fake();

        $data = $this->cart();
        $group = $data['groups'][0];
        $this->assertSame($this->expectedFee($meters), $group['delivery_fee_santim']);
        $this->assertSame(0, $group['delivery_fee_santim'] % 1000, 'rounded up to 10 birr');
        $this->assertTrue($group['delivery_distance']['estimated']);
        $this->assertSame($meters, $group['delivery_distance']['meters']);
        $this->assertNotSame(77_700, $group['delivery_fee_santim'], 'distance wins over the zone fee');
        $this->assertTrue($data['has_pin']);
        Http::assertNothingSent();
    }

    public function test_road_distance_from_google_is_used_and_remembered(): void
    {
        config(['services.google_maps.server_key' => 'test-server-key']);
        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['distanceMeters' => 12_345, 'duration' => '900s']]])]);

        $group = $this->cart()['groups'][0];
        // 150 + 25 x 12.345 km = 458.63, rounded up to 460 birr.
        $this->assertSame(46_000, $group['delivery_fee_santim']);
        $this->assertFalse($group['delivery_distance']['estimated']);
        $this->assertSame(12.3, $group['delivery_distance']['km']);

        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Goog-Api-Key', 'test-server-key')
            && $r->hasHeader('X-Goog-FieldMask', 'routes.distanceMeters')
            && $r['origin']['location']['latLng'] === ['latitude' => self::STORE[0], 'longitude' => self::STORE[1]]
            && $r['destination']['location']['latLng'] === ['latitude' => self::HOME[0], 'longitude' => self::HOME[1]]
            && $r['travelMode'] === 'DRIVE');

        // The checkout preview and the order ask again: Google is not called twice for one trip.
        $this->getJson('/api/cart?address_id='.$this->address->id)->assertOk();
        $expected = $this->getJson('/api/cart?address_id='.$this->address->id)->json('data.grand_total_santim');
        $this->postJson('/api/checkout', ['address_id' => $this->address->id, 'payment_method' => 'cod', 'expected_total_santim' => $expected], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        Http::assertSentCount(1);

        $order = Order::latest('id')->first();
        $this->assertSame(46_000, $order->delivery_fee_santim);
        $this->assertSame(12_345, $order->delivery_distance_m);
        $this->assertFalse($order->delivery_distance_estimated);
        $this->assertEquals(self::HOME[0], (float) $order->checkout->latitude);

        // The store sees the distance to price the trip, and the pin for directions once it accepts the order.
        $this->as($this->seller->user)->getJson("/api/seller/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.delivery_distance_km', 12.3)
            ->assertJsonPath('data.delivery_address.latitude', null);
        $this->postJson("/api/seller/orders/{$order->id}/confirm")->assertOk()
            ->assertJsonPath('data.delivery_address.latitude', self::HOME[0]);
    }

    public function test_google_failures_fall_back_to_the_estimate(): void
    {
        config(['services.google_maps.server_key' => 'test-server-key']);
        // One fake whose reply changes per round (later Http::fake calls would not replace it).
        $reply = null;
        Http::fake(['routes.googleapis.com/*' => function () use (&$reply) {
            return $reply instanceof \Closure ? $reply() : $reply;
        }]);

        $failures = [
            'refused key' => Http::response(['error' => ['code' => 403]], 403),
            'no route' => Http::response(['routes' => []]),
            'not json' => Http::response('not json'),
            'negative' => Http::response(['routes' => [['distanceMeters' => -5]]]),
            'timeout' => fn () => throw new ConnectionException('timeout'),
        ];
        foreach ($failures as $name => $r) {
            Cache::flush();
            $reply = $r;
            $group = $this->cart()['groups'][0];
            $this->assertTrue($group['delivery_distance']['estimated'], $name);
            $this->assertGreaterThan(15_000, $group['delivery_fee_santim'], $name);
        }
        // Every round asked Google (the timeout is not recorded as a sent request).
        Http::assertSentCount(count($failures) - 1);

        // A failed lookup is remembered only briefly, then Google is asked again.
        $reply = Http::response(['routes' => [['distanceMeters' => 2_000]]]);
        $this->assertTrue($this->cart()['groups'][0]['delivery_distance']['estimated'], 'still the remembered estimate');
        $this->travel(11)->minutes();
        $this->assertFalse($this->cart()['groups'][0]['delivery_distance']['estimated']);

        // Same place: Google returns a route without distanceMeters, which means 0 m.
        Cache::flush();
        $reply = Http::response(['routes' => [['duration' => '0s']]]);
        $group = $this->cart()['groups'][0];
        $this->assertSame(0, $group['delivery_distance']['meters']);
        $this->assertSame(15_000, $group['delivery_fee_santim'], 'just the base fee');
    }

    public function test_too_far_means_the_store_confirms_not_the_zone_fee(): void
    {
        Setting::create(['key' => 'delivery_max_km', 'value' => '3']);
        $data = $this->cart();
        $group = $data['groups'][0];
        $this->assertTrue($group['delivery_distance']['too_far']);
        $this->assertNull($group['delivery_fee_santim']);
        $this->assertFalse($group['delivery_fee_confirmed']);
        $this->assertTrue($data['delivery_to_confirm']);

        // 0 means no limit.
        Setting::where('key', 'delivery_max_km')->update(['value' => '0']);
        $this->assertNotNull($this->cart()['groups'][0]['delivery_fee_santim']);
    }

    public function test_a_trip_of_exactly_the_longest_distance_is_still_priced(): void
    {
        Setting::create(['key' => 'delivery_max_km', 'value' => '3']);
        config(['services.google_maps.server_key' => 'test-server-key']);
        $meters = 3_000;
        Http::fake(['routes.googleapis.com/*' => function () use (&$meters) {
            return Http::response(['routes' => [['distanceMeters' => $meters]]]);
        }]);

        $group = $this->cart()['groups'][0];
        $this->assertFalse($group['delivery_distance']['too_far']);
        $this->assertSame(DistancePricing::fee(3_000), $group['delivery_fee_santim']);

        Cache::flush();
        $meters = 3_001;
        $this->assertTrue($this->cart()['groups'][0]['delivery_distance']['too_far']);
    }

    public function test_listing_fee_wins_and_missing_pins_or_zero_rate_use_the_zone(): void
    {
        $this->sofa->forceFill(['delivery_fee_santim' => 20_000])->save();
        $this->assertSame(20_000, $this->cart()['groups'][0]['delivery_fee_santim']);
        $this->sofa->forceFill(['delivery_fee_santim' => null])->save();

        $this->seller->forceFill(['latitude' => null, 'longitude' => null])->save();
        $group = $this->cart()['groups'][0];
        $this->assertSame(77_700, $group['delivery_fee_santim']);
        $this->assertNull($group['delivery_distance']);

        $this->seller->forceFill(['latitude' => self::STORE[0], 'longitude' => self::STORE[1]])->save();
        Setting::create(['key' => 'delivery_per_km_birr', 'value' => '0']);
        $this->assertSame(77_700, $this->cart()['groups'][0]['delivery_fee_santim']);

        // Area-only previews (no saved address) have no pin.
        Setting::where('key', 'delivery_per_km_birr')->delete();
        $this->assertSame(77_700, $this->getJson('/api/cart?area_id='.$this->area('bole')->id)->json('data.groups.0.delivery_fee_santim'));
    }

    public function test_pickup_has_no_distance_or_carrying_fee(): void
    {
        $this->address->forceFill(['floor' => 3, 'has_lift' => false])->save();
        $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug, 'quantity' => 1]);
        $group = $this->getJson('/api/cart?address_id='.$this->address->id.'&fulfillment['.$this->seller->id.']=pickup')->json('data.groups.0');
        $this->assertSame('pickup', $group['fulfillment']);
        $this->assertNull($group['delivery_fee_santim']);
        $this->assertNull($group['delivery_distance']);
        $this->assertNull($group['carry_fee_santim']);
    }

    public function test_carrying_fee_per_floor_without_a_lift(): void
    {
        $base = $this->cart()['groups'][0]['delivery_fee_santim'];

        $this->address->forceFill(['floor' => 4, 'has_lift' => false])->save();
        $data = $this->cart();
        $this->assertSame($base + 40_000, $data['groups'][0]['delivery_fee_santim']);
        $this->assertSame(40_000, $data['groups'][0]['carry_fee_santim']);
        $this->assertSame(['floor' => 4, 'fee_santim' => 40_000], $data['carry']);

        // A lift, the ground floor, or an unanswered lift question: no carrying fee.
        foreach ([[4, true], [0, false], [4, null]] as [$floor, $lift]) {
            $this->address->forceFill(['floor' => $floor, 'has_lift' => $lift])->save();
            $this->assertSame($base, $this->cart()['groups'][0]['delivery_fee_santim'], "floor $floor lift ".var_export($lift, true));
        }
    }

    public function test_store_confirmed_fee_gets_the_carrying_fee_added(): void
    {
        $this->seller->forceFill(['latitude' => null, 'longitude' => null])->save();
        $this->area('bole')->forceFill(['delivery_zone_id' => null])->save();
        $this->address->forceFill(['floor' => 2, 'has_lift' => false])->save();
        $data = $this->cart();
        $this->assertNull($data['groups'][0]['delivery_fee_santim']);
        $this->assertSame(20_000, $data['groups'][0]['carry_fee_santim']);

        $this->postJson('/api/checkout', ['address_id' => $this->address->id, 'payment_method' => 'cod', 'expected_total_santim' => $data['grand_total_santim']], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $order = Order::latest('id')->first();
        $this->assertSame(20_000, $order->carry_fee_santim);
        $this->assertSame(2, $order->checkout->floor);

        $this->as($this->seller->user)->postJson("/api/seller/orders/{$order->id}/confirm", ['delivery_fee' => '500'])->assertOk()
            ->assertJsonPath('data.delivery_fee_santim', 70_000)
            ->assertJsonPath('data.total_santim', 1_000_000 + 70_000)
            ->assertJsonPath('data.delivery_address.floor', 2)
            ->assertJsonPath('data.delivery_address.has_lift', false);
    }

    public function test_pins_and_floors_are_validated(): void
    {
        $this->as($this->buyer);
        $base = ['recipient_name' => 'Abebe', 'phone' => '+251911000111', 'area_id' => $this->area('bole')->id, 'address_line' => 'House 1'];

        // Half a pin, a swapped pair, a pin in another country, out of range.
        $this->postJson('/api/addresses', $base + ['latitude' => 9.0])->assertUnprocessable()->assertJsonValidationErrors('longitude');
        $this->postJson('/api/addresses', $base + ['latitude' => 38.76, 'longitude' => 9.01])->assertUnprocessable()->assertJsonValidationErrors('latitude');
        $this->postJson('/api/addresses', $base + ['latitude' => 51.5, 'longitude' => -0.12])->assertUnprocessable()->assertJsonValidationErrors('latitude');
        $this->postJson('/api/addresses', $base + ['latitude' => 'x', 'longitude' => 200])->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude']);
        // A floor above the ground needs the lift answer; widths must be plausible.
        $this->postJson('/api/addresses', $base + ['floor' => 3])->assertUnprocessable()->assertJsonValidationErrors(['has_lift' => 'Say whether the building has a lift.']);
        $this->postJson('/api/addresses', $base + ['floor' => 61, 'door_width_cm' => 5])->assertUnprocessable()->assertJsonValidationErrors(['floor', 'door_width_cm' => 'The front door width field must be at least 40.']);

        $id = $this->postJson('/api/addresses', $base + ['latitude' => 9.01, 'longitude' => 38.76, 'floor' => 3, 'has_lift' => false, 'door_width_cm' => 80, 'stair_width_cm' => 90])->assertCreated()
            ->assertJsonPath('data.latitude', 9.01)
            ->assertJsonPath('data.floor', 3)
            ->assertJsonPath('data.has_lift', false)
            ->assertJsonPath('data.door_width_cm', 80)
            ->json('data.id');
        $this->postJson('/api/addresses', $base + ['floor' => 0])->assertCreated();

        // Clearing the pin; editing other fields leaves the pin alone.
        $this->putJson("/api/addresses/$id", ['address_line' => 'House 2'])->assertOk()->assertJsonPath('data.latitude', 9.01);
        $this->putJson("/api/addresses/$id", ['latitude' => null, 'longitude' => null])->assertOk()->assertJsonPath('data.latitude', null);

        // The store pin, on the store profile; the public store page never shows it.
        $this->as($this->seller->user)->putJson('/api/seller/profile', ['latitude' => 9.03, 'longitude' => 38.74])->assertOk()->assertJsonPath('data.latitude', 9.03);
        $this->putJson('/api/seller/profile', ['latitude' => 0, 'longitude' => 0])->assertUnprocessable()->assertJsonValidationErrors('latitude');
        $public = $this->getJson("/api/sellers/{$this->seller->slug}")->assertOk()->getContent();
        $this->assertStringNotContainsString('38.74', $public);
    }

    public function test_product_page_estimate_uses_the_saved_address(): void
    {
        $this->address->forceFill(['floor' => 1, 'has_lift' => false])->save();
        $meters = (int) round(MapPin::straightMeters(...[...self::STORE, ...self::HOME]) * 1.3);

        $this->as($this->buyer)->getJson("/api/products/{$this->sofa->slug}/delivery-estimate?address_id={$this->address->id}")->assertOk()
            ->assertJsonPath('data.fee_source', 'distance')
            ->assertJsonPath('data.fee_santim', $this->expectedFee($meters) + 10_000)
            ->assertJsonPath('data.carry_fee_santim', 10_000)
            ->assertJsonPath('data.distance_km', round($meters / 1000, 1));

        // By sub-city only, it is the zone fee as before.
        $this->getJson("/api/products/{$this->sofa->slug}/delivery-estimate?area_id={$this->area('bole')->id}")->assertOk()
            ->assertJsonPath('data.fee_source', 'zone')->assertJsonPath('data.fee_santim', 77_700);

        // Someone else's address is not found; without signing in it is refused.
        $this->as($this->customer())->getJson("/api/products/{$this->sofa->slug}/delivery-estimate?address_id={$this->address->id}")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/products/{$this->sofa->slug}/delivery-estimate?address_id={$this->address->id}")->assertUnauthorized();
        $this->getJson("/api/products/{$this->sofa->slug}/delivery-estimate")->assertUnprocessable();
    }

    public function test_admin_sets_rates_and_the_public_config_never_shows_the_server_key(): void
    {
        config(['services.google_maps.browser_key' => 'browser-key', 'services.google_maps.server_key' => 'secret-server-key']);
        $this->as($this->admin());
        $this->putJson('/api/admin/settings', ['delivery_base_fee_birr' => -1, 'delivery_per_km_birr' => 'x', 'delivery_max_km' => 5000, 'delivery_per_floor_birr' => 1.5])
            ->assertUnprocessable()->assertJsonValidationErrors(['delivery_base_fee_birr' => 'The base fee field must be at least 0.', 'delivery_per_km_birr', 'delivery_max_km' => 'The longest trip field', 'delivery_per_floor_birr']);
        $this->putJson('/api/admin/settings', ['delivery_base_fee_birr' => 200, 'delivery_per_km_birr' => 30, 'delivery_max_km' => 25, 'delivery_per_floor_birr' => 50])->assertOk()
            ->assertJsonPath('data.delivery_per_km_birr', 30)
            ->assertJsonPath('meta.maps_server_key_set', true)
            ->assertJsonPath('meta.stores_without_pin', 0);
        $this->as($this->seller->user)->putJson('/api/admin/settings', ['delivery_per_km_birr' => 1])->assertForbidden();

        $config = $this->getJson('/api/config')->assertOk()
            ->assertJsonPath('maps.browser_key', 'browser-key')
            ->assertJsonPath('delivery.by_distance', true)
            ->assertJsonPath('delivery.base_fee_santim', 20_000)
            ->assertJsonPath('delivery.per_floor_santim', 5_000);
        $this->assertStringNotContainsString('secret-server-key', $config->getContent());
    }

    public function test_fee_rounding(): void
    {
        $this->assertSame(15_000, DistancePricing::fee(0));
        // 1 m costs 2.5 santim, rounded up to a whole santim, then to 10 birr.
        $this->assertSame(16_000, DistancePricing::fee(1));
        $this->assertSame(18_000, DistancePricing::fee(1_000));
        $this->assertSame(40_000, DistancePricing::fee(10_000));
        $this->assertSame(40_000, DistancePricing::fee(9_999));
        $this->assertSame(41_000, DistancePricing::fee(10_001));
        // A fraction of a santim still rounds up when the rest lands exactly on 10 birr:
        // 1 birr/km x 10.001 km = 1000.1 santim, so 150 + 10.001 is 170 birr, not 160.
        Setting::create(['key' => 'delivery_per_km_birr', 'value' => '1']);
        $this->assertSame(17_000, DistancePricing::fee(10_001));
    }
}
