<?php

namespace Tests\Feature;

use App\Models\SellerProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The ERP migration on a database that already has stores, listings and carts: nothing is lost,
 * every store gets a default location and stock history starts at today's numbers.
 */
class ErpMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_17_200000_create_erp_inventory_tables.php';

    // Parts 2 and 3 build on part 1's tables, so they are rolled back first and migrated after.
    private const PATHS = [
        self::MIGRATION,
        'database/migrations/2026_10_18_200000_create_erp_purchasing_tables.php',
        'database/migrations/2026_10_19_200000_create_erp_sales_and_expenses_tables.php',
    ];

    public function test_existing_data_is_kept_and_backfilled_and_rollback_restores_the_old_shape(): void
    {
        $this->seedReferenceData();
        $this->artisan('migrate:rollback', ['--path' => self::PATHS])->assertSuccessful();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('stock_movements'));

        // Rows written the way the app wrote them before the ERP existed.
        // Without model events: the new code would give them a location, which is what the migration must do.
        $seller = SellerProfile::withoutEvents(fn () => $this->approvedSeller());
        $quiet = SellerProfile::withoutEvents(fn () => $this->approvedSeller());
        $category = $this->category()->id;
        $row = fn ($stock, $type = 'in_stock', $deleted = null) => DB::table('products')->insertGetId([
            'seller_profile_id' => $seller->id, 'category_id' => $category, 'name' => 'Old listing', 'slug' => 'old-'.uniqid(),
            'description' => 'From before the ERP.', 'price_santim' => 100_000, 'condition' => 'new', 'stock_type' => $type,
            'stock_quantity' => $stock, 'offers_delivery' => true, 'status' => 'published', 'deleted_at' => $deleted,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $withStock = $row(7);
        $empty = $row(0);
        $custom = $row(0, 'made_to_order');
        $deleted = $row(3, 'in_stock', now());
        $buyer = $this->customer();
        DB::table('cart_items')->insert(['user_id' => $buyer->id, 'product_id' => $withStock, 'quantity' => 2, 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();

        foreach ([$seller->id, $quiet->id] as $id) {
            $this->assertSame(1, DB::table('warehouses')->where('seller_profile_id', $id)->where('default_for', $id)->where('name', 'Main store')->count());
        }
        $this->assertSame(7, (int) DB::table('products')->where('id', $withStock)->value('stock_quantity'));
        $opening = DB::table('stock_movements')->get();
        $this->assertCount(1, $opening);
        $this->assertSame([$withStock, 'opening', 7, 7], [(int) $opening[0]->product_id, $opening[0]->type, (int) $opening[0]->quantity, (int) $opening[0]->available_after]);
        $this->assertSame(1, DB::table('cart_items')->where('product_id', $withStock)->whereNull('variant_id')->count());
        $this->assertNotNull([$empty, $custom, $deleted]);

        // Part 2 rebuilds the products table on SQLite to add supplier_id. A real migrate runs that with
        // foreign keys off (SQLite schema changes are not wrapped in a transaction), so nothing cascades;
        // inside this test's own transaction the pragma does nothing, so rows are only checked on MySQL.
        $this->artisan('migrate', ['--path' => self::PATHS])->assertSuccessful();
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('products', 'supplier_id'));
        if (DB::getDriverName() !== 'sqlite') {
            $this->assertSame(1, DB::table('stock_movements')->where('product_id', $withStock)->where('type', 'opening')->count());
            $this->assertSame(1, DB::table('cart_items')->where('product_id', $withStock)->count());
        }
        DB::table('cart_items')->where('product_id', $withStock)->delete();
        DB::table('cart_items')->insert(['user_id' => $buyer->id, 'product_id' => $withStock, 'quantity' => 2, 'created_at' => now(), 'updated_at' => now()]);

        // The cart's one-line-per-listing rule now counts the option too.
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('cart_items')->insert(['user_id' => $buyer->id, 'product_id' => $withStock, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_rollback_brings_back_the_old_cart_rule(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::PATHS])->assertSuccessful();
        foreach (['warehouses', 'warehouse_stock', 'stock_movements', 'seller_erp_settings', 'product_variants'] as $table) {
            $this->assertFalse(DB::getSchemaBuilder()->hasTable($table), $table);
        }
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('products', 'sku'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('cart_items', 'variant_id'));
        $this->artisan('migrate', ['--path' => self::PATHS])->assertSuccessful();
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('cart_items', 'variant_key'));
    }
}
