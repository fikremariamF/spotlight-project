<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An online checkout must be paid by this time, or its orders are cancelled and the stock released.
        Schema::table('checkouts', function (Blueprint $table) {
            $table->timestamp('payment_due_at')->nullable()->after('grand_total_santim');
            $table->timestamp('paid_at')->nullable()->after('payment_due_at');
            $table->index(['payment_method', 'paid_at', 'payment_due_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // What the customer actually paid online for this order; refunds never exceed it.
            $table->unsignedBigInteger('paid_santim')->nullable()->after('payment_status');
        });

        // One attempt to pay a checkout online. A customer can retry, so a checkout may have several;
        // at most one of them ever pays for the orders.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->string('provider', 20);
            // Our reference sent to the provider; unique so a provider callback maps to one attempt.
            $table->string('tx_ref', 64)->unique();
            $table->unsignedBigInteger('amount_santim');
            $table->string('currency', 3);
            // pending | paid | failed | cancelled | expired
            $table->string('status', 12)->default('pending');
            $table->text('checkout_url')->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->string('method', 40)->nullable();
            // What the provider reported, if it differs from what we expected (amount or currency).
            $table->string('problem', 255)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->index(['checkout_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        // Money the platform has to send back: a paid order that was cancelled, or a payment that
        // arrived for orders that were already cancelled or already paid.
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_santim');
            $table->string('reason', 255);
            // due | refunded
            $table->string('status', 10)->default('due');
            $table->string('method', 20)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            // A payment-level refund (no order) is created once per payment, under the payment's row lock.
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('paid_santim'));
        Schema::table('checkouts', function (Blueprint $table) {
            $table->dropIndex(['payment_method', 'paid_at', 'payment_due_at']);
            $table->dropColumn(['payment_due_at', 'paid_at']);
        });
    }
};
