<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('meal_type_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('min_grade')->nullable();   // who may buy it; both empty = the whole school
            $table->unsignedTinyInteger('max_grade')->nullable();
            $table->unsignedTinyInteger('duration_months');          // 1, 3, 6 or 12: paid time, not a number of meals
            $table->unsignedBigInteger('price');                     // minor units of the school's currency
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['school_id', 'meal_type_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plans');
    }
};
