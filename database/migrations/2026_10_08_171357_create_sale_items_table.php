<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('name');                         // snapshot: later renames don't change old sales
            $table->bigInteger('unit_price');
            $table->unsignedInteger('quantity');
            $table->bigInteger('line_total');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
