<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // track_stock = false means unlimited (e.g. meals cooked to order); stock is ignored then
            $table->boolean('track_stock')->default(false)->after('price');
            $table->integer('stock')->default(0)->after('track_stock');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['track_stock', 'stock']);
        });
    }
};
