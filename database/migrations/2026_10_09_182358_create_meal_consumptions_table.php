<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('meal_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('meal_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('meal_subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('service_date');
            $table->string('identification_type', 20);
            $table->string('identification_method', 20);
            $table->string('identifier', 80);
            $table->string('idempotency_key', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['school_id', 'idempotency_key']);
            $table->unique(['student_id', 'meal_type_id', 'service_date']);
            $table->index(['school_id', 'service_date', 'meal_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meal_consumptions');
    }
};
