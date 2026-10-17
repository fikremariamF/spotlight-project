<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers talk to Betoch support instead of stores. Admins ask stores internally.
 * The old customer-store conversations stay as they are (read-only history); nothing is moved or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One customer inquiry, optionally about a listing, an order or a store.
        Schema::create('support_tickets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $t->string('topic', 20);
            $t->string('subject', 160);
            // new | open | waiting | resolved | closed
            $t->string('status', 12)->default('new');
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            // The store the inquiry concerns, from the listing or order; admins only.
            $t->foreignId('seller_profile_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('escalated_at')->nullable();
            $t->string('escalation_reason', 300)->nullable();
            // What the customer can see. Read state is tracked by message id, as in conversations.
            $t->unsignedBigInteger('last_public_message_id')->nullable();
            $t->timestamp('last_public_message_at')->nullable();
            $t->string('last_public_by', 10)->nullable();
            $t->unsignedBigInteger('customer_last_read_id')->nullable();
            // Any message, note or change, for the admin list.
            $t->timestamp('last_activity_at')->nullable();
            $t->timestamp('first_response_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();

            $t->index(['customer_id', 'last_public_message_at']);
            $t->index(['status', 'last_activity_at']);
            $t->index(['assigned_to', 'status']);
        });

        // Everything that happens on an inquiry. `internal` rows (notes and history) never reach the customer.
        Schema::create('support_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('support_ticket_id')->constrained()->restrictOnDelete();
            $t->foreignId('author_id')->nullable()->constrained('users')->restrictOnDelete();
            // customer | admin | system
            $t->string('author_role', 10);
            // message | note | event | offer
            $t->string('kind', 10);
            $t->boolean('internal');
            $t->text('body');
            $t->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $t->json('meta')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['support_ticket_id', 'internal', 'id']);
        });

        // Betoch asking a store something, usually for a customer inquiry. The store sees only this thread.
        Schema::create('seller_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('support_ticket_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('seller_profile_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('topic', 20);
            $t->string('subject', 160);
            // open (waiting for the store) | answered (waiting for Betoch) | closed
            $t->string('status', 10)->default('open');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('last_message_id')->nullable();
            $t->timestamp('last_message_at')->nullable();
            $t->string('last_message_by', 10)->nullable();
            $t->unsignedBigInteger('seller_last_read_id')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();

            $t->index(['seller_profile_id', 'status', 'last_message_at']);
            $t->index(['status', 'last_message_at']);
        });

        // A store's reply can carry a priced proposal that Betoch may send to the customer as an offer.
        Schema::create('seller_request_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seller_request_id')->constrained()->restrictOnDelete();
            $t->foreignId('author_id')->nullable()->constrained('users')->restrictOnDelete();
            // admin | seller | system
            $t->string('author_role', 10);
            $t->text('body');
            $t->unsignedBigInteger('price_santim')->nullable();
            $t->unsignedSmallInteger('quantity')->nullable();
            $t->unsignedBigInteger('delivery_fee_santim')->nullable();
            $t->unsignedSmallInteger('production_days')->nullable();
            $t->date('valid_until')->nullable();
            // Set once Betoch has sent this proposal to the customer.
            $t->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['seller_request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_request_messages');
        Schema::dropIfExists('seller_requests');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
    }
};
