<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seller ERP, part 2: suppliers, purchase orders with receiving, supplier invoices and payments,
 * and stock transfers between a store's locations.
 *
 * Only additions. Stock still changes only through InventoryService, so a receipt or a transfer
 * shows up in stock_movements like any other change. Money is integer santim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $t) {
            $t->string('contact_name', 100)->nullable()->after('address_line');
        });

        // On: receiving goods updates the item's cost price to the weighted average.
        Schema::table('seller_erp_settings', function (Blueprint $t) {
            $t->boolean('cost_from_purchases')->default(true)->after('low_stock_alerts');
        });

        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $t->string('name', 150);
            $t->string('contact_person', 100)->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('address', 255)->nullable();
            $t->string('tin', 20)->nullable();
            // Free text ("50% advance, rest on delivery") plus days for a default invoice due date.
            $t->string('payment_terms', 120)->nullable();
            $t->unsignedSmallInteger('payment_days')->nullable();
            $t->string('notes', 1000)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique(['seller_profile_id', 'name']);
            $t->index(['seller_profile_id', 'is_active']);
        });

        // The supplier a store usually buys a listing from.
        Schema::table('products', function (Blueprint $t) {
            $t->foreignId('supplier_id')->nullable()->after('seller_profile_id')->constrained()->nullOnDelete();
        });

        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            // Per-store running number; `number` is what people see (PO-00012).
            $t->unsignedInteger('sequence');
            $t->string('number', 20);
            // draft | ordered | partially_received | received | cancelled
            $t->string('status', 20)->default('draft');
            // Where the goods are expected.
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->date('order_date');
            $t->date('expected_date')->nullable();
            $t->string('supplier_reference', 60)->nullable();
            $t->unsignedBigInteger('subtotal_santim')->default(0);
            $t->unsignedBigInteger('discount_santim')->default(0);
            $t->unsignedBigInteger('charges_santim')->default(0);
            $t->string('charges_note', 120)->nullable();
            $t->unsignedBigInteger('total_santim')->default(0);
            $t->string('notes', 1000)->nullable();
            // Received, but the rest of the order was not coming and the store stopped waiting.
            $t->boolean('closed_short')->default(false);
            $t->timestamp('ordered_at')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['seller_profile_id', 'sequence']);
            $t->unique(['seller_profile_id', 'number']);
            $t->index(['seller_profile_id', 'status']);
            $t->index(['supplier_id', 'status']);
        });

        Schema::create('purchase_order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            // Listing name and option at the time, for the printed order.
            $t->string('description', 255);
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('unit_cost_santim');
            // Discount on the whole line.
            $t->unsignedBigInteger('discount_santim')->default(0);
            $t->unsignedBigInteger('line_total_santim');
            $t->unsignedInteger('received_quantity')->default(0);
            $t->unsignedSmallInteger('sort_order')->default(0);

            $t->index(['purchase_order_id', 'sort_order']);
            $t->index(['product_id', 'variant_id']);
        });

        // One delivery from a supplier against a purchase order. Never edited or deleted.
        Schema::create('purchase_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('sequence');
            $t->string('number', 20);
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->date('received_on');
            $t->string('note', 255)->nullable();
            // Sent by the form, so a double submit returns the first receipt instead of adding stock twice.
            $t->string('request_id', 64)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();

            $t->unique(['seller_profile_id', 'sequence']);
            $t->unique(['seller_profile_id', 'request_id']);
            $t->index('purchase_order_id');
        });

        Schema::create('purchase_receipt_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_receipt_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_order_item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $t->unsignedInteger('quantity');
            // Cost per unit including its share of the order's discount and charges.
            $t->unsignedBigInteger('unit_cost_santim');

            $t->index('purchase_order_item_id');
        });

        // What a supplier billed. Voided rather than deleted.
        Schema::create('supplier_invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('invoice_number', 60);
            $t->date('invoice_date');
            $t->date('due_date')->nullable();
            $t->unsignedBigInteger('amount_santim');
            $t->string('note', 255)->nullable();
            // recorded | voided
            $t->string('status', 10)->default('recorded');
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason', 255)->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['supplier_id', 'status']);
            $t->index(['seller_profile_id', 'invoice_date']);
            $t->index('purchase_order_id');
        });

        // Money the store paid a supplier, optionally against one purchase order (a deposit or payment).
        Schema::create('supplier_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
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
            $t->index(['supplier_id', 'status']);
            $t->index(['seller_profile_id', 'paid_on']);
            $t->index('purchase_order_id');
        });

        // Moving units from one of the store's locations to another.
        Schema::create('stock_transfers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('sequence');
            $t->string('number', 20);
            $t->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $t->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            // in_transit (left the first location, not yet counted at the second) | received | cancelled
            $t->string('status', 12);
            $t->string('note', 255)->nullable();
            $t->string('request_id', 64)->nullable();
            $t->timestamp('dispatched_at');
            $t->timestamp('received_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['seller_profile_id', 'sequence']);
            $t->unique(['seller_profile_id', 'request_id']);
            $t->index(['seller_profile_id', 'status']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $t->string('description', 255);
            $t->unsignedInteger('quantity');
            // Null until received; can be less than sent when units were lost or damaged on the way.
            $t->unsignedInteger('received_quantity')->nullable();

            $t->index(['product_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::table('products', fn (Blueprint $t) => $t->dropConstrainedForeignId('supplier_id'));
        Schema::dropIfExists('suppliers');
        Schema::table('seller_erp_settings', fn (Blueprint $t) => $t->dropColumn('cost_from_purchases'));
        Schema::table('warehouses', fn (Blueprint $t) => $t->dropColumn('contact_name'));
    }
};
