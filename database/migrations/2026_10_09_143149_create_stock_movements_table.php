<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);                    // received | sale | adjustment
            $table->integer('quantity');                   // signed change
            $table->integer('stock_after');
            $table->string('reference')->nullable();       // e.g. sale:<key>
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent(); // never updated

            $table->index(['product_id', 'id']);
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
