<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('meal_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('meal_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_entry_id')->constrained()->restrictOnDelete();   // the wallet payment
            $table->date('valid_from');
            $table->date('expires_on');                      // last day covered, in school time
            $table->unsignedBigInteger('price');             // what was paid: later price changes do not touch it
            $table->string('idempotency_key', 64);
            $table->timestamps();

            $table->unique(['school_id', 'idempotency_key']);
            $table->index(['student_id', 'meal_type_id', 'expires_on'], 'meal_subs_student_meal_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_subscriptions');
    }
};
