<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // When this category may be bought, in school time. All empty = any time, any day.
            $table->time('sell_from')->nullable()->after('is_active');
            $table->time('sell_to')->nullable()->after('sell_from');
            $table->json('sell_days')->nullable()->after('sell_to');   // ISO weekdays 1-7; empty = every day
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['sell_from', 'sell_to', 'sell_days']);
        });
    }
};
