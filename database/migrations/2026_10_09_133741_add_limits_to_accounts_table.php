<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Set by the parent; can never be above the school's maximum for the grade. Null = no personal limit.
            $table->bigInteger('daily_limit')->nullable()->after('balance');
            $table->bigInteger('weekly_limit')->nullable()->after('daily_limit');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['daily_limit', 'weekly_limit']);
        });
    }
};
