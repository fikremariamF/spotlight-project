<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            // Smaller copies made on upload (or by marketplace:image-variants); null means use the original.
            $table->string('thumb_path')->nullable()->after('path');
            $table->string('large_path')->nullable()->after('thumb_path');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn(['thumb_path', 'large_path']);
        });
    }
};
