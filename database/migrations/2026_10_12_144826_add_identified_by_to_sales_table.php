<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // How the student was identified at the till: 'card' (a tap) or 'manual' (student number, confirmed by the cashier)
            $table->string('identified_by', 10)->default('card')->after('cashier_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('identified_by');
        });
    }
};
