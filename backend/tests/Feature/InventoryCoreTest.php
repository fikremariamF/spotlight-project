<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Notifications\Alert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

/**
 * Stock history, options (variants) and the order flow: every change to available stock goes
 * through InventoryService and the history always adds up to the stock shown.
 */
class InventoryCoreTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->setUpOrders();
    }

    /** Sum of the history of one stock item. */
    private function history(Product $product, ?ProductVariant $variant = null): int
    {
        return (int) StockMovement::where('product_id', $product->id)
            ->when($variant, fn ($q) => $q->where('variant_id', $variant->id), fn ($q) => $q->whereNull('variant_id'))->sum('quantity');
    }

    private function addVariant(array $data, ?Product $product = null): array
    {
        $product ??= $this->sofa;

        return $this->as($product->seller->user)->postJson("/api/seller/products/{$product->id}/variants", $data)->assertOk()->json('data');
    }

    public function test_every_store_gets_one_default_location(): void
    {
        $this->assertSame(1, Warehouse::where('seller_profile_id', $this->seller->id)->where('is_default', true)->count());
        $this->assertSame($this->seller->id, Warehouse::where('seller_profile_id', $this->seller->id)->value('default_for'));
    }

    public function test_new_listing_records_its_opening_stock(): void
    {
        $id = $this->as($this->seller->user)->post('/api/seller/products', $this->productPayload(['stock_quantity' => 7]), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.stock_quantity', 7)->json('data.id');

        $movement = StockMovement::where('product_id', $id)->sole();
        $this->assertSame(['opening', 7, 7], [$movement->type, $movement->quantity, $movement->available_after]);
        $this->assertSame($this->seller->user->id, $movement->user_id);
    }

    public function test_orders_take_stock_and_cancelling_gives_it_back_with_history(): void
    {
        // Factory stock has no history yet (like listings from before stock tracking); orders still balance.
        $order = $this->placeOrder(3);
        $this->assertSame(47, $this->sofa->fresh()->stock_quantity);
        $taken = StockMovement::where('order_id', $order->id)->sole();
        $this->assertSame(['order_reserved', -3, 47], [$taken->type, $taken->quantity, $taken->available_after]);
        $this->assertSame($this->buyer->id, $taken->user_id);
        $this->assertNull($order->items->first()->unit_cost_santim);

        $this->as($this->buyer)->postJson("/api/orders/{$order->id}/cancel", ['reason' => 'Changed my mind'])->assertOk();
        $this->assertSame(50, $this->sofa->fresh()->stock_quantity);
        $this->assertSame(0, (int) StockMovement::where('order_id', $order->id)->sum('quantity'));
        $this->assertSame(['order_reserved', 'order_released'], StockMovement::where('order_id', $order->id)->orderBy('id')->pluck('type')->all());
    }

    public function test_order_lines_keep_the_cost_price_of_the_moment(): void
    {
        $this->sofa->forceFill(['cost_price_santim' => 600_000])->save();
        $order = $this->placeOrder(1);
        $this->sofa->forceFill(['cost_price_santim' => 700_000])->save();

        $this->assertSame(600_000, $order->items->first()->fresh()->unit_cost_santim);
    }

    public function test_listing_form_stock_change_is_recorded_and_stale_forms_are_refused(): void
    {
        $this->placeOrder(2); // 48 left while the seller's form still says 50
        $seller = $this->seller->user;

        $this->as($seller)->putJson("/api/seller/products/{$this->sofa->id}", ['stock_quantity' => 60, 'stock_expected' => 50])
            ->assertUnprocessable()->assertJsonPath('errors.stock_quantity.0', fn ($m) => str_contains($m, 'It is now 48'));
        $this->assertSame(48, $this->sofa->fresh()->stock_quantity);

        $this->putJson("/api/seller/products/{$this->sofa->id}", ['stock_quantity' => 60, 'stock_expected' => 48])->assertOk()
            ->assertJsonPath('data.stock_quantity', 60);
        $edit = StockMovement::where('product_id', $this->sofa->id)->where('type', 'edit')->sole();
        $this->assertSame([12, 60], [$edit->quantity, $edit->available_after]);

        // Saving without touching stock (or an old client without stock_expected) still works.
        $this->putJson("/api/seller/products/{$this->sofa->id}", ['price' => '9000'])->assertOk()->assertJsonPath('data.stock_quantity', 60);
        $this->putJson("/api/seller/products/{$this->sofa->id}", ['stock_quantity' => 55])->assertOk()->assertJsonPath('data.stock_quantity', 55);
    }

    public function test_first_option_takes_over_the_listing_stock_and_further_options_have_their_own(): void
    {
        $data = $this->addVariant(['color' => 'Grey', 'size' => '3-seater', 'stock_quantity' => 50]);
        $grey = ProductVariant::where('product_id', $this->sofa->id)->sole();
        $this->assertSame(50, $grey->stock_quantity);
        $this->assertSame('Grey, 3-seater', $data['variants'][0]['label']);
        // The listing's own units moved out to the option (factory stock had no opening line).
        $this->assertSame(-50, $this->history($this->sofa));
        $this->assertSame(50, $this->history($this->sofa, $grey));

        $data = $this->addVariant(['color' => 'Blue', 'size' => '3-seater', 'price' => '12000', 'stock_quantity' => 4]);
        $blue = ProductVariant::where('product_id', $this->sofa->id)->where('color', 'Blue')->sole();
        $this->assertSame(54, $this->sofa->fresh()->stock_quantity);
        $this->assertSame(4, $this->history($this->sofa, $blue));
        $this->assertSame('12000.00', collect($data['variants'])->firstWhere('id', $blue->id)['price']);
        $this->assertSame('10000.00', collect($data['variants'])->firstWhere('id', $grey->id)['price']);

        // Same combination twice (any letter case) is refused; so is an option with nothing set.
        $this->postJson("/api/seller/products/{$this->sofa->id}/variants", ['color' => 'grey ', 'size' => '3-SEATER'])->assertUnprocessable();
        $this->postJson("/api/seller/products/{$this->sofa->id}/variants", ['color' => ' '])->assertUnprocessable()->assertJsonValidationErrors('size');

        // With options, the listing's own stock number can no longer be set.
        $this->putJson("/api/seller/products/{$this->sofa->id}", ['stock_quantity' => 3])->assertUnprocessable()->assertJsonValidationErrors('stock_quantity');
    }

    public function test_customers_buy_an_option_at_its_price_and_stock(): void
    {
        $this->addVariant(['color' => 'Grey', 'stock_quantity' => 50]);
        $this->addVariant(['color' => 'Blue', 'price' => '12000', 'stock_quantity' => 2]);
        $blue = ProductVariant::where('color', 'Blue')->sole();

        $page = $this->getJson("/api/products/{$this->sofa->slug}")->assertOk();
        $this->assertCount(2, $page->json('data.variants'));
        $this->assertArrayNotHasKey('sku', $page->json('data.variants.0'));
        $this->assertArrayNotHasKey('own_price', $page->json('data.variants.0'));

        $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug, 'quantity' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('variant_id');
        $this->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug, 'variant_id' => 999999])->assertUnprocessable();
        $this->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug, 'variant_id' => $blue->id, 'quantity' => 3])
            ->assertUnprocessable()->assertJsonPath('errors.quantity.0', 'Only 2 can be ordered.');
        $cart = $this->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug, 'variant_id' => $blue->id, 'quantity' => 2])->assertCreated();
        $this->assertSame(2_400_000, $cart->json('data.items_total_santim'));
        $this->assertSame('Blue', $cart->json('data.groups.0.lines.0.variant.label'));

        $expected = $this->getJson('/api/cart?address_id='.$this->address->id)->json('data.grand_total_santim');
        $this->postJson('/api/checkout', ['address_id' => $this->address->id, 'payment_method' => 'cod', 'expected_total_santim' => $expected],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $order = Order::latest('id')->first();
        $item = $order->items->first();
        $this->assertSame([$blue->id, 'Blue', 1_200_000], [$item->variant_id, $item->variant_label, $item->unit_price_santim]);
        $this->assertSame(0, $blue->fresh()->stock_quantity);
        $this->assertSame(50, $this->sofa->fresh()->stock_quantity);

        $this->as($this->seller->user)->getJson("/api/seller/orders/{$order->id}")->assertJsonPath('data.items.0.variant_label', 'Blue');

        // Cancelling gives the units back to that option.
        $this->postJson("/api/seller/orders/{$order->id}/cancel", ['reason' => 'Damaged in the workshop'])->assertOk();
        $this->assertSame(2, $blue->fresh()->stock_quantity);
        $this->assertSame(52, $this->sofa->fresh()->stock_quantity);
        $this->assertSame(2, $this->history($this->sofa, $blue));
    }

    public function test_cart_lines_from_before_options_must_be_picked_again(): void
    {
        $this->as($this->buyer)->postJson('/api/cart/items', ['product_slug' => $this->sofa->slug, 'quantity' => 1])->assertCreated();
        $this->addVariant(['color' => 'Grey', 'stock_quantity' => 50]);

        $this->as($this->buyer)->getJson('/api/cart')->assertOk()->assertJsonPath('data.has_problems', true)
            ->assertJsonPath('data.groups.0.lines.0.problem', fn ($p) => str_contains($p, 'Choose a size, color or material'));
    }

    public function test_an_order_from_before_options_returns_its_units_to_the_first_option(): void
    {
        $order = $this->placeOrder(3);
        $this->addVariant(['color' => 'Grey', 'stock_quantity' => 47]);
        $grey = ProductVariant::sole();

        $this->as($this->buyer)->postJson("/api/orders/{$order->id}/cancel", ['reason' => 'Changed my mind'])->assertOk();
        $this->assertSame(50, $grey->fresh()->stock_quantity);
        $this->assertSame(50, $this->sofa->fresh()->stock_quantity);
        $this->assertSame(50, $this->history($this->sofa, $grey));
    }

    public function test_option_stock_edits_and_removal_rules(): void
    {
        $this->addVariant(['color' => 'Grey', 'stock_quantity' => 5]);
        $this->addVariant(['color' => 'Blue', 'stock_quantity' => 5]);
        $grey = ProductVariant::where('color', 'Grey')->sole();
        $blue = ProductVariant::where('color', 'Blue')->sole();
        $order = $this->placeOrder(1, null, null, $blue);

        $url = "/api/seller/products/{$this->sofa->id}/variants";
        $this->as($this->seller->user)->putJson("{$url}/{$blue->id}", ['color' => 'Blue', 'stock_quantity' => 9, 'stock_expected' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('stock_quantity');
        $this->putJson("{$url}/{$blue->id}", ['color' => 'Navy blue', 'stock_quantity' => 9, 'stock_expected' => 4])->assertOk();
        $this->assertSame(['Navy blue', 9], [$blue->fresh()->color, $blue->fresh()->stock_quantity]);
        $this->assertSame(14, $this->sofa->fresh()->stock_quantity);

        // Not while it has stock, and not while an open order holds some.
        $this->deleteJson("{$url}/{$grey->id}")->assertUnprocessable()->assertJsonValidationErrors('variant');
        $this->putJson("{$url}/{$blue->id}", ['color' => 'Navy blue', 'stock_quantity' => 0])->assertOk();
        $this->deleteJson("{$url}/{$blue->id}")->assertUnprocessable()->assertJsonPath('errors.variant.0', fn ($m) => str_contains($m, 'open orders'));

        $this->deliver($order);
        $this->as($this->seller->user)->deleteJson("{$url}/{$blue->id}")->assertOk()->assertJsonCount(1, 'data.variants');
        $this->assertSoftDeleted($blue);
        // The order keeps showing what was bought.
        $this->assertSame('Blue', $order->items->first()->fresh()->variant_label);
    }

    public function test_other_stores_suspended_stores_and_customers_cannot_change_options(): void
    {
        $this->addVariant(['color' => 'Grey', 'stock_quantity' => 5]);
        $grey = ProductVariant::sole();
        $other = $this->approvedSeller();

        $this->as($other->user)->postJson("/api/seller/products/{$this->sofa->id}/variants", ['color' => 'Red'])->assertNotFound();
        $this->putJson("/api/seller/products/{$this->sofa->id}/variants/{$grey->id}", ['color' => 'Red'])->assertNotFound();
        $this->deleteJson("/api/seller/products/{$this->sofa->id}/variants/{$grey->id}")->assertNotFound();

        // An option id from another listing does not resolve under this one.
        $mine = Product::factory()->create(['seller_profile_id' => $other->id, 'category_id' => $this->category()->id]);
        $this->putJson("/api/seller/products/{$mine->id}/variants/{$grey->id}", ['color' => 'Red'])->assertNotFound();

        $this->as($this->buyer)->postJson("/api/seller/products/{$this->sofa->id}/variants", ['color' => 'Red'])->assertForbidden();
        $this->seller->forceFill(['status' => 'suspended'])->save();
        $this->as($this->seller->user)->postJson("/api/seller/products/{$this->sofa->id}/variants", ['color' => 'Red'])->assertForbidden();
        $this->assertSame('Grey', $grey->fresh()->color);
    }

    public function test_low_stock_and_out_of_stock_alerts_fire_once_when_crossing(): void
    {
        Notification::fake();
        $this->sofa->forceFill(['stock_quantity' => 4, 'min_stock_level' => 2, 'reorder_quantity' => 10])->save();

        $this->placeOrder(1); // 3: above the level, no alert
        Notification::assertNotSentTo($this->seller->user, Alert::class, fn ($n) => str_starts_with($n->kind, 'stock.'));
        $this->placeOrder(1, $this->customer()); // 2: reached the level
        $this->placeOrder(1, $this->customer()); // 1: still low, no second alert
        $this->placeOrder(1, $this->customer()); // 0: out of stock

        $sent = [];
        Notification::assertSentTo($this->seller->user, Alert::class, function ($n) use (&$sent) {
            if (str_starts_with($n->kind, 'stock.')) {
                $sent[] = [$n->kind, $n->title];
            }

            return true;
        });
        $this->assertSame([
            ['stock.low', "Low stock: {$this->sofa->name}"],
            ['stock.out', "Out of stock: {$this->sofa->name}"],
        ], $sent);
    }

    public function test_made_to_order_switch_writes_stock_off_with_history(): void
    {
        $this->as($this->seller->user)->putJson("/api/seller/products/{$this->sofa->id}", ['stock_type' => 'made_to_order', 'production_days' => 14])->assertOk()
            ->assertJsonPath('data.stock_quantity', 0);
        $this->assertSame(-50, $this->history($this->sofa));
    }
}
