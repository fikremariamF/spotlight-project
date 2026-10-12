<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In-app alerts (Laravel's database notification channel).
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            // morphs() columns without its own index: the composite index below covers those lookups too.
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        // Each user chooses whether alerts also go out by SMS and by email.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('alert_sms')->default(true)->after('phone');
            $table->boolean('alert_email')->default(true)->after('alert_sms');
        });

        // Every SMS the platform tries to send, for delivery checks, costs and retries.
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            // The alert this SMS belongs to; a retried queue job finds the row instead of sending twice.
            $table->uuid('notification_id')->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 50);
            $table->string('to', 20);
            $table->string('body', 480);
            $table->string('driver', 20);
            $table->string('status', 10)->default('pending'); // pending | sent | logged | failed
            $table->string('provider_message_id', 100)->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['alert_sms', 'alert_email']);
        });
        Schema::dropIfExists('notifications');
    }
};
