<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Filled by the meal screen (next step). One breakfast and one lunch per student per day.
        Schema::create('meal_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('meal_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('meal_subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('service_date');                     // school time
            $table->string('identified_by', 10)->default('card');   // card | manual
            $table->string('idempotency_key', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['school_id', 'idempotency_key']);
            $table->unique(['student_id', 'meal_type_id', 'service_date'], 'meal_once_per_day');
            $table->index(['school_id', 'service_date', 'meal_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_consumptions');
    }
};
