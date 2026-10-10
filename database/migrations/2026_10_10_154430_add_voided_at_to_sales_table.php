<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Set when a refund is approved. The sale row itself is never deleted.
            $table->timestamp('voided_at')->nullable()->after('total');
            $table->index(['school_id', 'voided_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'voided_at']);
            $table->dropColumn('voided_at');
        });
    }
};
