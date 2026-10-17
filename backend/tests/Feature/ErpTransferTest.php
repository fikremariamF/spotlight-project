<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

class ErpTransferTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->setUpOrders();
    }

    private function location(string $name = 'Bole warehouse'): int
    {
        return $this->as($this->seller->user)->postJson('/api/seller/erp/warehouses', ['name' => $name, 'address_line' => 'Bole, near Edna Mall', 'contact_name' => 'Hana', 'phone' => '0911 000 111'])
            ->assertCreated()->json('data.id');
    }

    private function main(): int
    {
        return Warehouse::where('default_for', $this->seller->id)->value('id');
    }

    private function move(int $from, int $to, int $quantity, array $extra = [], ?Product $product = null, ?int $variant = null): TestResponse
    {
        return $this->postJson('/api/seller/erp/transfers', $extra + [
            'from_warehouse_id' => $from, 'to_warehouse_id' => $to,
            'items' => [['product_id' => ($product ?? $this->sofa)->id, 'variant_id' => $variant, 'quantity' => $quantity]],
        ]);
    }

    private function at(int $warehouseId): int
    {
        $row = collect($this->getJson("/api/seller/erp/warehouses/{$warehouseId}")->assertOk()->json('data.stock'))->firstWhere('product_id', $this->sofa->id);

        return $row['quantity'] ?? 0;
    }

    public function test_units_on_the_way_are_out_of_stock_until_they_arrive(): void
    {
        $bole = $this->location();
        $main = $this->main();
        $t = $this->move($main, $bole, 10, ['note' => 'For the weekend'])->assertCreated()
            ->assertJsonPath('data.status', 'in_transit')->assertJsonPath('data.number', 'TR-00001')->json('data');
        $this->assertSame(40, $this->sofa->fresh()->stock_quantity);
        $this->assertSame([40, 0], [$this->at($main), $this->at($bole)]);
        $this->getJson('/api/seller/summary')->assertJsonPath('data.erp.transfers_in_transit', 1);

        // 9 of 10 arrived: one was damaged on the way and stays out of stock.
        $this->postJson("/api/seller/erp/transfers/{$t['id']}/receive", ['items' => [['id' => $t['items'][0]['id'], 'received_quantity' => 11]]])->assertUnprocessable();
        $this->postJson("/api/seller/erp/transfers/{$t['id']}/receive", ['items' => [['id' => $t['items'][0]['id'], 'received_quantity' => 9]]])->assertOk()
            ->assertJsonPath('data.status', 'received')->assertJsonPath('data.items.0.received_quantity', 9);
        $this->assertSame(49, $this->sofa->fresh()->stock_quantity);
        $this->assertSame([40, 9], [$this->at($main), $this->at($bole)]);
        $this->postJson("/api/seller/erp/transfers/{$t['id']}/receive")->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/seller/erp/transfers/{$t['id']}/cancel")->assertUnprocessable();
        $this->assertSame(49, $this->sofa->fresh()->stock_quantity);

        $types = StockMovement::where('reference_type', 'stock_transfer')->orderBy('id')->get(['type', 'quantity', 'warehouse_id'])
            ->map(fn ($m) => [$m->type, $m->quantity, $m->warehouse_id])->all();
        $this->assertSame([['transfer_out', -10, $main], ['transfer_in', 9, $bole]], $types);
    }

    public function test_cancelled_transfers_go_back_and_moved_now_does_both_steps(): void
    {
        $bole = $this->location();
        $main = $this->main();
        $t = $this->move($main, $bole, 5)->assertCreated()->json('data');
        $this->postJson("/api/seller/erp/transfers/{$t['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame([50, 50, 0], [$this->sofa->fresh()->stock_quantity, $this->at($main), $this->at($bole)]);
        $this->postJson("/api/seller/erp/transfers/{$t['id']}/receive")->assertUnprocessable();

        $this->move($main, $bole, 7, ['received_now' => true, 'request_id' => 'abc'])->assertCreated()->assertJsonPath('data.status', 'received');
        $this->move($main, $bole, 7, ['received_now' => true, 'request_id' => 'abc'])->assertCreated();
        $this->assertSame(2, StockTransfer::count());
        $this->assertSame([50, 43, 7], [$this->sofa->fresh()->stock_quantity, $this->at($main), $this->at($bole)]);

        // Orders take from the main location first, then the others; a cancellation puts units back where they came from.
        $this->move($main, $bole, 41, ['received_now' => true])->assertCreated();
        $order = $this->placeOrder(4);
        $this->as($this->seller->user);
        $this->assertSame([0, 46], [$this->at($main), $this->at($bole)]);
        $this->as($this->buyer)->postJson("/api/orders/{$order->id}/cancel", ['reason' => 'Changed my mind'])->assertOk();
        $this->as($this->seller->user);
        $this->assertSame(50, $this->sofa->fresh()->stock_quantity);
        $this->assertSame([2, 48], [$this->at($main), $this->at($bole)]);
    }

    public function test_transfers_are_checked(): void
    {
        $bole = $this->location();
        $main = $this->main();
        $this->move($main, $main, 1)->assertUnprocessable()->assertJsonValidationErrors('to_warehouse_id');
        $this->move($bole, $main, 1)->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->move($main, $bole, 51)->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->move($main, $bole, 0)->assertUnprocessable();
        $this->postJson('/api/seller/erp/transfers', ['from_warehouse_id' => $main, 'to_warehouse_id' => $bole, 'items' => [
            ['product_id' => $this->sofa->id, 'quantity' => 1], ['product_id' => $this->sofa->id, 'quantity' => 1],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.1.product_id');

        $other = $this->approvedSeller();
        $theirs = Product::factory()->published()->create(['seller_profile_id' => $other->id, 'category_id' => $this->category()->id, 'stock_quantity' => 5]);
        $this->move($main, $bole, 1, product: $theirs)->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
        $theirPlace = Warehouse::forceCreate(['seller_profile_id' => $other->id, 'name' => 'Theirs']);
        $this->move($main, $theirPlace->id, 1)->assertUnprocessable()->assertJsonValidationErrors('to_warehouse_id');

        $this->postJson("/api/seller/products/{$this->sofa->id}/variants", ['color' => 'Grey'])->assertOk();
        $this->move($main, $bole, 1)->assertUnprocessable()->assertJsonValidationErrors('items.0.variant_id');
        $grey = ProductVariant::sole();
        $this->move($main, $bole, 3, variant: $grey->id)->assertCreated();
        $this->assertSame(47, $grey->fresh()->stock_quantity);

        $this->postJson("/api/seller/erp/warehouses/{$bole}/close")->assertUnprocessable();
        $this->assertSame(50, $this->sofa->fresh()->stock_quantity + 3);
    }

    public function test_listings_and_options_on_the_way_are_kept(): void
    {
        $bole = $this->location();
        $t = $this->move($this->main(), $bole, 50)->assertCreated()->json('data');
        $this->assertSame(0, $this->sofa->fresh()->stock_quantity);
        $this->deleteJson("/api/seller/products/{$this->sofa->id}")->assertUnprocessable()->assertJsonValidationErrors('product');
        $this->putJson("/api/seller/products/{$this->sofa->id}", ['stock_type' => 'made_to_order', 'production_days' => 5])->assertUnprocessable();

        // Options added while the units travel: they arrive as the first option.
        $this->postJson("/api/seller/products/{$this->sofa->id}/variants", ['color' => 'Grey'])->assertOk();
        $grey = ProductVariant::sole();
        $this->postJson("/api/seller/products/{$this->sofa->id}/variants", ['color' => 'Blue'])->assertOk();
        $this->assertSame(0, $grey->fresh()->stock_quantity);
        $this->deleteJson("/api/seller/products/{$this->sofa->id}/variants/{$grey->id}")->assertUnprocessable()->assertJsonValidationErrors('variant');
        $this->postJson("/api/seller/erp/transfers/{$t['id']}/receive")->assertOk();
        $this->assertSame([50, 50], [$grey->fresh()->stock_quantity, $this->sofa->fresh()->stock_quantity]);
        $this->assertSame(50, (int) WarehouseStock::where('warehouse_id', $bole)->where('variant_id', $grey->id)->value('quantity'));

        // A grey option now on the way blocks removing grey, not blue.
        $this->move($bole, $this->main(), 50, variant: $grey->id)->assertCreated();
        $blue = ProductVariant::where('color', 'Blue')->sole();
        $this->deleteJson("/api/seller/products/{$this->sofa->id}/variants/{$blue->id}")->assertOk();
        $this->deleteJson("/api/seller/products/{$this->sofa->id}/variants/{$grey->id}")->assertUnprocessable();
    }

    public function test_locations_are_named_once_closed_only_when_empty_and_private(): void
    {
        $bole = $this->location();
        $main = $this->main();
        $this->postJson('/api/seller/erp/warehouses', ['name' => 'bole WAREHOUSE'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/seller/erp/warehouses', ['name' => 'Ok', 'phone' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->putJson("/api/seller/erp/warehouses/{$main}", ['name' => 'Piassa shop'])->assertOk()->assertJsonPath('data.name', 'Piassa shop');
        $this->postJson("/api/seller/erp/warehouses/{$main}/close")->assertUnprocessable();

        $this->move($main, $bole, 2, ['received_now' => true])->assertCreated();
        $this->postJson("/api/seller/erp/warehouses/{$bole}/close")->assertUnprocessable()->assertJsonValidationErrors('warehouse');
        $back = $this->move($bole, $main, 2)->assertCreated()->json('data');
        $this->postJson("/api/seller/erp/warehouses/{$bole}/close")->assertUnprocessable();
        $this->postJson("/api/seller/erp/transfers/{$back['id']}/receive")->assertOk();

        $supplier = $this->postJson('/api/seller/erp/suppliers', ['name' => 'Merkato Wood'])->json('data.id');
        $po = $this->postJson('/api/seller/erp/purchases', ['supplier_id' => $supplier, 'warehouse_id' => $bole,
            'items' => [['product_id' => $this->sofa->id, 'quantity' => 1, 'unit_cost' => '1']]])->assertCreated()->json('data');
        $this->postJson("/api/seller/erp/warehouses/{$bole}/close")->assertUnprocessable();
        $this->postJson("/api/seller/erp/purchases/{$po['id']}/cancel", ['reason' => 'Not needed'])->assertOk();
        $this->postJson("/api/seller/erp/warehouses/{$bole}/close")->assertOk()->assertJsonPath('data.is_active', false);

        $this->move($main, $bole, 1)->assertUnprocessable()->assertJsonValidationErrors('to_warehouse_id');
        $this->postJson("/api/seller/erp/inventory/{$this->sofa->id}/adjust", ['type' => 'found', 'quantity' => 1, 'warehouse_id' => $bole])->assertUnprocessable();
        $this->postJson("/api/seller/erp/warehouses/{$bole}/reopen")->assertOk()->assertJsonPath('data.is_active', true);

        $list = $this->getJson('/api/seller/erp/warehouses')->assertOk()->json('data');
        $this->assertSame(['Piassa shop', 'Bole warehouse'], array_column($list, 'name'));
        $this->assertSame([50, 0], array_column($list, 'units'));

        $other = $this->approvedSeller();
        $this->as($other->user)->getJson("/api/seller/erp/warehouses/{$bole}")->assertNotFound();
        $this->putJson("/api/seller/erp/warehouses/{$bole}", ['name' => 'Mine'])->assertNotFound();
        $this->postJson("/api/seller/erp/warehouses/{$bole}/close")->assertNotFound();
        $this->getJson("/api/seller/erp/transfers/{$back['id']}")->assertNotFound();
        $this->postJson("/api/seller/erp/transfers/{$back['id']}/cancel")->assertNotFound();
        $this->getJson('/api/seller/erp/transfers')->assertOk()->assertJsonCount(0, 'data');
        $this->assertCount(1, $this->getJson('/api/seller/erp/warehouses')->json('data'));
    }

    public function test_warehouse_figures_and_the_item_picker(): void
    {
        $bole = $this->location();
        $this->sofa->forceFill(['cost_price_santim' => 600_000, 'sku' => 'SF-1'])->save();
        $this->move($this->main(), $bole, 5, ['received_now' => true])->assertCreated();

        $w = $this->getJson("/api/seller/erp/warehouses/{$bole}")->assertOk()->json('data');
        $this->assertSame([5, 1, 3_000_000, 'Hana'], [$w['units'], $w['items'], $w['value_cost_santim'], $w['contact_name']]);
        $this->assertSame('TR-00001', $w['transfers'][0]['number']);

        $items = $this->getJson("/api/seller/erp/items?q=sf-1&warehouse_id={$bole}")->assertOk()->json('data');
        $this->assertSame([45 + 5, 5, 600_000], [$items[0]['available'], $items[0]['at_location'], $items[0]['cost_price_santim']]);
    }
}
