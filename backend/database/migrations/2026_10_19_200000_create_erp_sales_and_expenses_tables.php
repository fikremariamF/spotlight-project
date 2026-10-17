<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seller ERP, part 3: sales made outside the marketplace (at the shop, by phone), customer payments
 * for them, returns of those sales and of delivered marketplace orders, and the store's expenses.
 *
 * Only additions: marketplace orders, payments, commission and the ledger are not touched. A return
 * of a marketplace order records the goods coming back (and any cash the store gave back for a cash
 * order); it changes neither the order nor what the store owes Betoch. Stock still changes only
 * through InventoryService. Money is integer santim. Nothing here is edited away: sales, payments,
 * returns and expenses are voided with a reason.
 */
return new class extends Migration
{
    private const TABLES = ['sale_return_items', 'sale_returns', 'offline_sale_payments', 'offline_sale_items', 'offline_sales', 'expenses', 'expense_categories'];

    public function up(): void
    {
        Schema::create('offline_sales', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            // Per-store running number; `number` is what people see (SL-00012).
            $t->unsignedInteger('sequence');
            $t->string('number', 20);
            // completed | voided
            $t->string('status', 10)->default('completed');
            $t->date('sale_date');
            // For a sale on credit: when the customer said they would pay.
            $t->date('due_date')->nullable();
            // Where the units left from.
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            // The store's own customer, as the store wrote it down. All optional.
            $t->string('customer_name', 100)->nullable();
            $t->string('customer_phone', 20)->nullable();
            $t->string('customer_tin', 20)->nullable();
            // Lines after their own discounts, then a discount on the whole sale and a delivery charge.
            $t->unsignedBigInteger('subtotal_santim');
            $t->unsignedBigInteger('discount_santim')->default(0);
            $t->unsignedBigInteger('delivery_santim')->default(0);
            $t->unsignedBigInteger('total_santim');
            $t->string('note', 500)->nullable();
            // Sent by the form, so a double submit returns the first sale instead of taking stock twice.
            $t->string('request_id', 64)->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason', 255)->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['seller_profile_id', 'sequence']);
            $t->unique(['seller_profile_id', 'number']);
            $t->unique(['seller_profile_id', 'request_id']);
            $t->index(['seller_profile_id', 'sale_date']);
        });

        Schema::create('offline_sale_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('offline_sale_id')->constrained()->cascadeOnDelete();
            // Null for a free-text line (assembly, a service) that is not one of the store's listings.
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $t->string('description', 255);
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('unit_price_santim');
            $t->unsignedBigInteger('discount_santim')->default(0);
            $t->unsignedBigInteger('line_total_santim');
            // The line total less its share of the sale's discount: what a return of the line credits.
            $t->unsignedBigInteger('net_santim');
            // Cost price when sold, for profit figures. Null when unknown.
            $t->unsignedBigInteger('unit_cost_santim')->nullable();
            // Whether the units came out of stock (in-stock listings only).
            $t->boolean('stock_taken')->default(false);
            $t->unsignedSmallInteger('sort_order')->default(0);

            $t->index(['offline_sale_id', 'sort_order']);
            $t->index(['product_id', 'variant_id']);
        });

        // Money the customer paid for an offline sale: all at once, or a deposit and the rest later.
        Schema::create('offline_sale_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('offline_sale_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('amount_santim');
            $t->date('paid_on');
            // cash | bank_transfer | telebirr | cbe_birr | cheque | other
            $t->string('method', 20);
            $t->string('reference', 100)->nullable();
            $t->string('note', 255)->nullable();
            $t->string('status', 10)->default('recorded');
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason', 255)->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('request_id', 64)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['seller_profile_id', 'request_id']);
            $t->index(['offline_sale_id', 'status']);
            $t->index(['seller_profile_id', 'paid_on']);
        });

        // Goods a customer brought back: from an offline sale, or from a delivered marketplace order.
        Schema::create('sale_returns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('sequence');
            $t->string('number', 20);
            // Exactly one of the two is set.
            $t->foreignId('offline_sale_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            // recorded | voided
            $t->string('status', 10)->default('recorded');
            $t->date('returned_on');
            // Where restocked units went.
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            // Value of the goods at the price they were sold for, plus any delivery charge given back.
            $t->unsignedBigInteger('goods_credit_santim');
            $t->unsignedBigInteger('delivery_credit_santim')->default(0);
            // Cash the store itself handed back. Online marketplace payments are refunded by Betoch, never here.
            $t->unsignedBigInteger('refund_santim')->default(0);
            $t->string('refund_method', 20)->nullable();
            $t->string('reason', 500);
            $t->string('request_id', 64)->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason', 255)->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['seller_profile_id', 'sequence']);
            $t->unique(['seller_profile_id', 'request_id']);
            $t->index('offline_sale_id');
            $t->index('order_id');
            $t->index(['seller_profile_id', 'returned_on']);
        });

        Schema::create('sale_return_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $t->foreignId('offline_sale_item_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('order_item_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $t->string('description', 255);
            $t->unsignedInteger('quantity');
            // Back into available stock, or not (damaged, thrown away).
            $t->boolean('restocked');
            $t->unsignedBigInteger('credit_santim');
            // The line's cost price when sold, so profit figures can take the return into account.
            $t->unsignedBigInteger('unit_cost_santim')->nullable();

            $t->index('offline_sale_item_id');
            $t->index('order_item_id');
        });

        // A store's own list; the usual ones are added the first time it opens Expenses.
        Schema::create('expense_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $t->string('name', 60);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();

            $t->unique(['seller_profile_id', 'name']);
        });

        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('sequence');
            $t->string('number', 20);
            $t->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $t->date('spent_on');
            $t->unsignedBigInteger('amount_santim');
            $t->string('description', 255);
            $t->string('paid_to', 120)->nullable();
            // cash | bank_transfer | telebirr | cbe_birr | cheque | other
            $t->string('payment_method', 20);
            $t->string('reference', 100)->nullable();
            // A photo or PDF of the receipt on the private disk, served only to the store.
            $t->string('attachment_path', 255)->nullable();
            $t->string('attachment_name', 150)->nullable();
            $t->string('attachment_mime', 60)->nullable();
            $t->unsignedInteger('attachment_size')->nullable();
            // recorded | voided
            $t->string('status', 10)->default('recorded');
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason', 255)->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('request_id', 64)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['seller_profile_id', 'sequence']);
            $t->unique(['seller_profile_id', 'request_id']);
            $t->index(['seller_profile_id', 'spent_on']);
            $t->index('expense_category_id');
        });
    }

    /**
     * Refused once a store has recorded a sale, return or expense: those are money records and
     * dropping the tables would lose them. Expense categories alone are only the default list.
     */
    public function down(): void
    {
        foreach (['offline_sales', 'sale_returns', 'expenses'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Not rolled back: {$table} has records that would be lost. Export them and remove them on purpose first.");
            }
        }
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
