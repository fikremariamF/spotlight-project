<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PlacesOrders;
use Tests\TestCase;

class ErpInventoryTest extends TestCase
{
    use PlacesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->setUpOrders();
    }

    private function listing(array $attrs = []): Product
    {
        return Product::factory()->published()->create(['seller_profile_id' => $this->seller->id, 'category_id' => $this->category()->id] + $attrs);
    }

    private function option(Product $product, array $attrs): ProductVariant
    {
        $this->as($this->seller->user)->postJson("/api/seller/products/{$product->id}/variants", $attrs)->assertOk();

        return ProductVariant::where('product_id', $product->id)->latest('id')->first();
    }

    public function test_list_shows_only_this_stores_items_with_stock_figures(): void
    {
        $this->sofa->forceFill(['cost_price_santim' => 600_000, 'sku' => 'SOFA-1'])->save();
        $this->placeOrder(2); // 2 held by an open order
        $this->deliver($this->placeOrder(1, $this->customer()));
        $other = $this->approvedSeller();
        Product::factory()->create(['seller_profile_id' => $other->id, 'category_id' => $this->category()->id, 'name' => 'Not mine']);

        $row = $this->as($this->seller->user)->getJson('/api/seller/erp/inventory')->assertOk()
            ->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame([47, 2, 1], [$row['available'], $row['held'], $row['sold']]);
        // Value counts units still in the store: available plus those held for orders not yet delivered.
        $this->assertSame(49 * 600_000, $row['value_cost_santim']);
        $this->assertSame(49 * 1_000_000, $row['value_price_santim']);
        $this->assertSame('SOFA-1', $row['items'][0]['sku']);
        $this->assertSame(0, $row['missing_cost']);

        $this->getJson("/api/seller/erp/inventory/{$this->sofa->id}")->assertOk()
            ->assertJsonPath('data.items.0.locations.0.name', 'Main store')
            ->assertJsonPath('data.items.0.locations.0.quantity', 47)
            ->assertJsonPath('data.movements.0.type', 'order_reserved');

        $mine = Product::where('seller_profile_id', $other->id)->first();
        $this->getJson("/api/seller/erp/inventory/{$mine->id}")->assertNotFound();
        $this->putJson("/api/seller/erp/inventory/{$mine->id}", ['sku' => 'X'])->assertNotFound();
        $this->postJson("/api/seller/erp/inventory/{$mine->id}/adjust", ['type' => 'found', 'quantity' => 1])->assertNotFound();
        $this->as($this->buyer)->getJson('/api/seller/erp/inventory')->assertForbidden();
    }

    public function test_filters_search_sort_and_pages(): void
    {
        $this->sofa->forceFill(['name' => 'Corner sofa'])->save();
        $chair = $this->listing(['name' => 'Oak chair', 'stock_quantity' => 1, 'sku' => 'CH-77']);
        $table = $this->listing(['name' => 'Dining table', 'stock_quantity' => 0]);
        $bed = $this->listing(['name' => 'Bed', 'stock_quantity' => 0, 'stock_type' => 'made_to_order', 'production_days' => 20]);
        $shelf = $this->listing(['name' => 'Shelf', 'stock_quantity' => 3]);
        $this->option($shelf, ['color' => 'White', 'stock_quantity' => 0]);
        $this->option($shelf, ['color' => 'Black', 'stock_quantity' => 9]);

        $ids = fn ($query) => collect($this->as($this->seller->user)->getJson('/api/seller/erp/inventory?'.$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$table->id, $shelf->id], $ids('stock=out'));
        $this->assertSame([$chair->id], $ids('stock=low'));
        $this->assertSame([$bed->id], $ids('stock=made_to_order'));
        $this->assertSame(collect([$this->sofa->id, $shelf->id])->sort()->values()->all(), $ids('stock=ok'));
        $this->assertSame([$chair->id], $ids('q=ch-7'));
        $this->assertSame([$shelf->id], $ids('q=black'));
        $this->assertSame([], $ids('q=%25'));

        // A store-wide level of 3 still finds only the chair (the shelf's options have 0 and 9); its own 0 turns that off.
        $this->putJson('/api/seller/erp/settings', ['low_stock_level' => 3])->assertOk();
        $this->assertSame([$chair->id], $ids('stock=low'));
        $chair->forceFill(['min_stock_level' => 0])->save();
        $this->assertSame([], $ids('stock=low'));

        $names = collect($this->getJson('/api/seller/erp/inventory?sort=name&dir=desc&per_page=5')->assertOk()->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 1)->json('data'))->pluck('name')->all();
        $this->assertSame('Shelf', $names[0]);
        $this->getJson('/api/seller/erp/inventory?sort=cost; drop')->assertUnprocessable();
        $this->getJson('/api/seller/erp/inventory?per_page=5&page=2')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_sku_cost_and_levels_with_unique_skus(): void
    {
        $shelf = $this->listing(['name' => 'Shelf', 'stock_quantity' => 3]);
        $white = $this->option($shelf, ['color' => 'White', 'stock_quantity' => 3]);
        $url = "/api/seller/erp/inventory/{$this->sofa->id}";

        $this->as($this->seller->user)->putJson($url, ['sku' => 'SF-001', 'cost_price' => '6500.50', 'min_stock_level' => 4, 'reorder_quantity' => 10])->assertOk()
            ->assertJsonPath('data.items.0.sku', 'SF-001')->assertJsonPath('data.items.0.cost_price_santim', 650050)
            ->assertJsonPath('data.items.0.low_level', 4);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.item_updated', 'subject_id' => $this->sofa->id]);
        // Cost and levels are private and never send the published listing back to review.
        $this->assertSame('published', $this->sofa->fresh()->status->value);

        // Unique per store, ignoring letter case, across listings, options and deleted listings.
        $this->putJson("/api/seller/erp/inventory/{$shelf->id}", ['variant_id' => $white->id, 'sku' => 'sf-001'])->assertUnprocessable()->assertJsonValidationErrors('sku');
        $gone = $this->listing(['sku' => 'OLD-1']);
        $gone->delete();
        $this->putJson("/api/seller/erp/inventory/{$shelf->id}", ['variant_id' => $white->id, 'sku' => 'old-1'])->assertUnprocessable();
        $this->putJson("/api/seller/erp/inventory/{$shelf->id}", ['variant_id' => $white->id, 'sku' => '=cmd'])->assertUnprocessable();
        $this->putJson("/api/seller/erp/inventory/{$shelf->id}", ['variant_id' => $white->id, 'sku' => 'SH-W'])->assertOk();
        // Another store may use the same SKU.
        $other = $this->approvedSeller();
        $theirs = Product::factory()->create(['seller_profile_id' => $other->id, 'category_id' => $this->category()->id]);
        $this->as($other->user)->putJson("/api/seller/erp/inventory/{$theirs->id}", ['sku' => 'SF-001'])->assertOk();

        // A listing with options: SKU and levels go on each option, the listing cost is the fallback.
        $this->as($this->seller->user)->putJson("/api/seller/erp/inventory/{$shelf->id}", ['sku' => 'SH'])->assertUnprocessable()->assertJsonValidationErrors('variant_id');
        $this->putJson("/api/seller/erp/inventory/{$shelf->id}", ['cost_price' => '1000'])->assertOk()->assertJsonPath('data.items.0.cost_price_santim', 100_000);

        // Someone else's option id under my listing does not resolve.
        $this->putJson("/api/seller/erp/inventory/{$this->sofa->id}", ['variant_id' => $white->id, 'sku' => 'Z'])->assertNotFound();
    }

    public function test_generated_skus_use_the_store_prefix_and_never_collide(): void
    {
        $this->as($this->seller->user)->putJson('/api/seller/erp/settings', ['sku_prefix' => 'abc'])->assertOk()->assertJsonPath('data.sku_prefix', 'ABC');
        $this->putJson('/api/seller/erp/settings', ['sku_prefix' => 'a-b'])->assertUnprocessable();
        $expected = 'ABC-'.str_pad((string) $this->sofa->id, 5, '0', STR_PAD_LEFT);
        $taken = $this->listing(['sku' => $expected]);

        $this->postJson("/api/seller/erp/inventory/{$this->sofa->id}/sku")->assertOk()->assertJsonPath('data.items.0.sku', $expected.'-A');
        // Already set: left alone.
        $this->postJson("/api/seller/erp/inventory/{$this->sofa->id}/sku")->assertOk()->assertJsonPath('data.items.0.sku', $expected.'-A');
        $this->assertNotNull($taken);
    }

    public function test_adjustments_by_type_with_checks(): void
    {
        $url = "/api/seller/erp/inventory/{$this->sofa->id}/adjust";
        $this->as($this->seller->user)->postJson($url, ['type' => 'damaged', 'quantity' => 2])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson($url, ['type' => 'damaged', 'quantity' => 2, 'note' => 'Water damage'])->assertOk()->assertJsonPath('data.available', 48);
        $this->postJson($url, ['type' => 'lost', 'quantity' => 49, 'note' => 'Theft'])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson($url, ['type' => 'returned', 'quantity' => 1])->assertOk()->assertJsonPath('data.available', 49);
        $this->postJson($url, ['type' => 'count', 'quantity' => 45])->assertOk()->assertJsonPath('data.available', 45);
        $this->postJson($url, ['type' => 'found', 'quantity' => 0])->assertUnprocessable();
        $this->postJson($url, ['type' => 'opening', 'quantity' => 5])->assertUnprocessable();
        $this->postJson($url, ['type' => 'found', 'quantity' => 1, 'expected_available' => 44])->assertUnprocessable()->assertJsonPath('errors.quantity.0', fn ($m) => str_contains($m, 'It is now 45'));

        $other = $this->approvedSeller();
        $theirWarehouse = Warehouse::where('seller_profile_id', $other->id)->first();
        $this->postJson($url, ['type' => 'found', 'quantity' => 1, 'warehouse_id' => $theirWarehouse->id])->assertNotFound();

        $types = StockMovement::where('product_id', $this->sofa->id)->orderBy('id')->pluck('quantity', 'type')->all();
        $this->assertSame(['damaged' => -2, 'returned' => 1, 'count' => -4], $types);
        $this->assertSame(3, AuditLog::where('action', 'inventory.adjusted')->count());

        // Made to order keeps no stock.
        $bed = $this->listing(['stock_type' => 'made_to_order', 'stock_quantity' => 0, 'production_days' => 10]);
        $this->postJson("/api/seller/erp/inventory/{$bed->id}/adjust", ['type' => 'found', 'quantity' => 1])->assertUnprocessable()->assertJsonValidationErrors('type');

        // Options must be named.
        $shelf = $this->listing(['stock_quantity' => 2]);
        $white = $this->option($shelf, ['color' => 'White', 'stock_quantity' => 2]);
        $this->postJson("/api/seller/erp/inventory/{$shelf->id}/adjust", ['type' => 'found', 'quantity' => 1])->assertUnprocessable()->assertJsonValidationErrors('variant_id');
        $this->postJson("/api/seller/erp/inventory/{$shelf->id}/adjust", ['type' => 'found', 'quantity' => 1, 'variant_id' => $white->id])->assertOk()
            ->assertJsonPath('data.items.0.available', 3)->assertJsonPath('data.available', 3);

        $this->seller->forceFill(['status' => 'suspended'])->save();
        $this->as($this->seller->user)->postJson($url, ['type' => 'found', 'quantity' => 1])->assertForbidden();
        $this->getJson('/api/seller/erp/inventory')->assertOk();
    }

    public function test_history_filters_and_csv_export(): void
    {
        $this->sofa->forceFill(['name' => '=HYPERLINK("x")'])->save();
        $this->placeOrder(1);
        $this->as($this->seller->user)->postJson("/api/seller/erp/inventory/{$this->sofa->id}/adjust", ['type' => 'found', 'quantity' => 3])->assertOk();

        $this->getJson('/api/seller/erp/movements')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.type', 'found')
            ->assertJsonPath('data.0.by', $this->seller->user->name);
        $this->getJson('/api/seller/erp/movements?type=order_reserved')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.order.number', fn ($n) => str_starts_with($n, 'ORD-'));
        $this->getJson('/api/seller/erp/movements?from=2000-01-01&to=2000-01-02')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/seller/erp/movements?type=bogus')->assertUnprocessable();

        $csv = $this->get('/api/seller/erp/inventory.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString(',52,1,0,', $csv);

        // Another store's history stays out.
        $this->as($this->approvedSeller()->user)->getJson('/api/seller/erp/movements')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_overview_counts_real_stock_and_delivered_sales_once(): void
    {
        $this->sofa->forceFill(['cost_price_santim' => 600_000, 'min_stock_level' => 60])->save(); // 50 left: low
        $lamp = $this->listing(['name' => 'Lamp', 'price_santim' => 200_000, 'stock_quantity' => 0]); // out, no cost
        $this->deliver($this->placeOrder(2));
        $this->placeOrder(1, $this->customer()); // open: held, not a sale
        $cancelled = $this->placeOrder(1, $this->customer());
        $this->as($this->seller->user)->postJson("/api/seller/orders/{$cancelled->id}/cancel", ['reason' => 'No stock'])->assertOk();

        $data = $this->as($this->seller->user)->getJson('/api/seller/erp/overview?period=7d')->assertOk()->json('data');
        $this->assertSame('7d', $data['range']['period']);
        $this->assertSame(2, $data['stock']['listings']);
        $this->assertSame([47, 1], [$data['stock']['available'], $data['stock']['held']]);
        $this->assertSame(48 * 600_000, $data['stock']['value_cost_santim']);
        $this->assertSame([1, 1, 1], [$data['stock']['low'], $data['stock']['out'], $data['stock']['missing_cost']]);
        $this->assertSame(['Lamp', $this->sofa->name], array_column($data['stock']['alerts'], 'name'));

        $m = $data['sales']['marketplace'];
        $this->assertSame([1, 2_000_000, 1_200_000, 800_000], [$m['orders'], $m['items_santim'], $m['cost_of_goods_santim'], $m['gross_profit_santim']]);
        $this->assertSame(0, $m['lines_without_cost']);
        $this->assertSame(1, $data['orders']['open']);
        $this->assertCount(7, $data['sales']['days']);
        $this->assertSame(2_000_000, collect($data['sales']['days'])->sum('items_santim'));
        $this->assertSame([2], array_column($data['sales']['top_products'], 'units'));
        $this->assertNotEmpty($data['recent_movements']);

        $this->getJson('/api/seller/summary')->assertOk()->assertJsonPath('data.stock', ['low' => 1, 'out' => 1]);
        $this->getJson('/api/seller/erp/overview?period=custom&from=2026-02-10&to=2026-01-01')->assertUnprocessable();
        $this->getJson('/api/seller/erp/overview?period=decade')->assertUnprocessable();
        $this->getJson('/api/seller/erp/overview?period=year')->assertOk()->assertJsonPath('data.range.from', now(config('marketplace.timezone'))->startOfYear()->toDateString());

        // Another store sees none of it.
        $this->as($this->approvedSeller()->user)->getJson('/api/seller/erp/overview')->assertOk()
            ->assertJsonPath('data.stock.listings', 0)->assertJsonPath('data.sales.marketplace.orders', 0);
        $this->assertNotNull($lamp);
    }

    public function test_settings_validation_and_suspended_read_only(): void
    {
        $this->as($this->seller->user)->getJson('/api/seller/erp/settings')->assertOk()->assertJsonPath('data.low_stock_level', 2)->assertJsonPath('data.low_stock_alerts', true);
        $this->putJson('/api/seller/erp/settings', ['low_stock_level' => -1])->assertUnprocessable();
        $this->putJson('/api/seller/erp/settings', ['low_stock_alerts' => false, 'low_stock_level' => 5])->assertOk()->assertJsonPath('data.low_stock_alerts', false);
        $this->seller->forceFill(['status' => 'suspended'])->save();
        $this->as($this->seller->user)->putJson('/api/seller/erp/settings', ['low_stock_level' => 1])->assertForbidden();
        $this->getJson('/api/seller/erp/settings')->assertOk()->assertJsonPath('data.low_stock_level', 5);
    }
}
