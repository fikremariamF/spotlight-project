<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Offers Betoch sends on a store's behalf are quotes too, so checkout keeps working unchanged.
 * They belong to a support inquiry instead of a customer-store conversation, which makes
 * quotes.conversation_id optional. Existing quotes keep their conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $t) {
            $t->foreignId('conversation_id')->nullable()->change();
            $t->foreignId('support_ticket_id')->nullable()->after('conversation_id')->constrained()->restrictOnDelete();
            $t->foreignId('seller_request_id')->nullable()->after('support_ticket_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Rolling back would orphan offers sent through support; refuse rather than delete them.
        if (DB::table('quotes')->whereNull('conversation_id')->exists()) {
            throw new RuntimeException('Some quotes were sent through support and have no conversation. Keep this migration, or move those quotes first.');
        }

        Schema::table('quotes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('seller_request_id');
            $t->dropConstrainedForeignId('support_ticket_id');
        });
        Schema::table('quotes', function (Blueprint $t) {
            $t->foreignId('conversation_id')->nullable(false)->change();
        });
    }
};
