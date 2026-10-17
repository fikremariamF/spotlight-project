<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seller ERP, part 1: stock items, options, locations and stock history.
 *
 * A listing is a stock item, or, when it has options (size, color, material), each option is.
 * Listings and options gain an SKU, a cost price and reorder levels. products.stock_quantity keeps
 * meaning "available to buy" across all of a store's locations; with options it is their total.
 * Each store gets one default location that holds whatever is not counted at another one, so
 * existing rows need no stock split. Only additions: no existing column changes meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->string('sku', 40)->nullable()->after('slug');
            // Average purchase cost per unit, for stock value and profit; null until the store sets it.
            $t->unsignedBigInteger('cost_price_santim')->nullable()->after('price_santim');
            // Null: the store's default low-stock level applies.
            $t->unsignedInteger('min_stock_level')->nullable()->after('stock_quantity');
            $t->unsignedInteger('reorder_quantity')->nullable()->after('min_stock_level');

            $t->unique(['seller_profile_id', 'sku']);
        });

        // Options of one listing, each with its own stock and, optionally, its own price.
        Schema::create('product_variants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Copied from the listing so SKUs can be unique per store.
            $t->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $t->string('sku', 40)->nullable();
            $t->string('size', 60)->nullable();
            $t->string('color', 60)->nullable();
            $t->string('material', 100)->nullable();
            // Null: the listing's price.
            $t->unsignedBigInteger('price_santim')->nullable();
            $t->unsignedBigInteger('cost_price_santim')->nullable();
            $t->unsignedInteger('stock_quantity')->default(0);
            $t->unsignedInteger('min_stock_level')->nullable();
            $t->unsignedInteger('reorder_quantity')->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
            // Removed options stay for order history.
            $t->softDeletes();

            $t->unique(['seller_profile_id', 'sku']);
            $t->index(['product_id', 'deleted_at']);
        });

        Schema::table('order_items', function (Blueprint $t) {
            $t->foreignId('variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
            // "Grey, 3-seater" at the time of the order.
            $t->string('variant_label', 200)->nullable()->after('name');
            // The item's cost price when the order was placed, so later cost changes keep old profit figures.
            $t->unsignedBigInteger('unit_cost_santim')->nullable()->after('unit_price_santim');
        });

        Schema::table('cart_items', function (Blueprint $t) {
            $t->foreignId('variant_id')->nullable()->after('product_id')->constrained('product_variants')->cascadeOnDelete();
            // Virtual: SQLite refuses to add a stored generated column to an existing table. Both engines can index it.
            $t->unsignedBigInteger('variant_key')->virtualAs('coalesce(variant_id, 0)');
            // The same listing may be in the cart once per option. Added before the old index is
            // dropped: MySQL needs an index starting with user_id for the user foreign key.
            $t->unique(['user_id', 'product_id', 'variant_key']);
        });
        Schema::table('cart_items', fn (Blueprint $t) => $t->dropUnique(['user_id', 'product_id']));

        Schema::create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('code', 20)->nullable();
            $t->string('address_line', 255)->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('notes', 500)->nullable();
            $t->boolean('is_default')->default(false);
            // Equals seller_profile_id on the default location and is null elsewhere: one default per store.
            $t->unsignedBigInteger('default_for')->nullable()->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique(['seller_profile_id', 'name']);
        });

        // Available units at locations other than the default one, per listing or option.
        Schema::create('warehouse_stock', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            // Virtual, not stored: MySQL refuses a cascading foreign key on a column a stored one is built from.
            $t->unsignedBigInteger('variant_key')->virtualAs('coalesce(variant_id, 0)');
            $t->unsignedInteger('quantity')->default(0);
            $t->timestamps();

            $t->unique(['warehouse_id', 'product_id', 'variant_key']);
            $t->index(['product_id', 'variant_id']);
        });

        // Every change to available stock, newest last. Never edited or deleted.
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('type', 24);
            // Signed change to available units: negative takes stock out.
            $t->integer('quantity');
            // The item's available units (all locations) and this location's, right after the change.
            $t->unsignedInteger('available_after');
            $t->unsignedInteger('location_after');
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            // Other source documents (purchase receipt, transfer, walk-in sale).
            $t->string('reference_type', 30)->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('note', 255)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['seller_profile_id', 'created_at']);
            $t->index(['product_id', 'id']);
            $t->index(['order_id', 'product_id']);
            $t->index(['reference_type', 'reference_id']);
        });

        Schema::create('seller_erp_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seller_profile_id')->unique()->constrained()->cascadeOnDelete();
            $t->unsignedInteger('low_stock_level')->default(2);
            $t->boolean('low_stock_alerts')->default(true);
            $t->string('sku_prefix', 10)->nullable();
            $t->timestamps();
        });

        $this->backfill();
    }

    /**
     * One default location per existing store, and an opening line in the stock history for every
     * listing that has stock now, so the history adds up to today's numbers.
     */
    private function backfill(): void
    {
        $now = now();
        DB::table('seller_profiles')->orderBy('id')->select('id')->chunk(500, function ($profiles) use ($now) {
            DB::table('warehouses')->insertOrIgnore($profiles->map(fn ($p) => [
                'seller_profile_id' => $p->id, 'name' => 'Main store', 'is_default' => true, 'default_for' => $p->id,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ])->all());
        });

        $defaults = DB::table('warehouses')->whereNotNull('default_for')->pluck('id', 'seller_profile_id');
        DB::table('products')->whereNull('deleted_at')->where('stock_type', 'in_stock')->where('stock_quantity', '>', 0)
            ->orderBy('id')->select(['id', 'seller_profile_id', 'stock_quantity'])
            ->chunkById(500, function ($products) use ($defaults, $now) {
                DB::table('stock_movements')->insert($products->filter(fn ($p) => isset($defaults[$p->seller_profile_id]))->map(fn ($p) => [
                    'seller_profile_id' => $p->seller_profile_id, 'product_id' => $p->id, 'warehouse_id' => $defaults[$p->seller_profile_id],
                    'type' => 'opening', 'quantity' => $p->stock_quantity, 'available_after' => $p->stock_quantity,
                    'location_after' => $p->stock_quantity, 'note' => 'Stock when stock tracking started', 'created_at' => $now,
                ])->values()->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_erp_settings');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('warehouse_stock');
        Schema::dropIfExists('warehouses');
        Schema::table('cart_items', function (Blueprint $t) {
            $t->unique(['user_id', 'product_id']);
        });
        // The generated key depends on variant_id, so it goes first.
        Schema::table('cart_items', function (Blueprint $t) {
            $t->dropUnique(['user_id', 'product_id', 'variant_key']);
            $t->dropColumn('variant_key');
        });
        Schema::table('cart_items', fn (Blueprint $t) => $t->dropConstrainedForeignId('variant_id'));
        Schema::table('order_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('variant_id');
            $t->dropColumn(['variant_label', 'unit_cost_santim']);
        });
        Schema::dropIfExists('product_variants');
        Schema::table('products', function (Blueprint $t) {
            $t->dropUnique(['seller_profile_id', 'sku']);
            $t->dropColumn(['sku', 'cost_price_santim', 'min_stock_level', 'reorder_quantity']);
        });
    }
};
