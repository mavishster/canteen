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
        $lookupColumns = ['student_id', 'meal_type_id', 'status', 'expires_on'];

        if (! Schema::hasTable('meal_subscriptions')) {
            Schema::create('meal_subscriptions', function (Blueprint $table) use ($lookupColumns) {
                $table->id();
                $table->foreignId('school_id')->constrained()->restrictOnDelete();
                $table->foreignId('student_id')->constrained()->restrictOnDelete();
                $table->foreignId('meal_plan_id')->constrained()->restrictOnDelete();
                $table->foreignId('meal_type_id')->constrained()->restrictOnDelete();
                $table->foreignId('ledger_entry_id')->constrained()->restrictOnDelete();
                $table->date('valid_from');
                $table->date('expires_on');
                $table->unsignedInteger('entitlement_quantity');
                $table->unsignedInteger('consumed_quantity')->default(0);
                $table->unsignedBigInteger('price');
                $table->string('payment_status', 20)->default('paid');
                $table->string('status', 20)->default('active');
                $table->string('idempotency_key', 64);
                $table->timestamps();

                $table->unique(['school_id', 'idempotency_key']);
                $table->index($lookupColumns, 'meal_subscriptions_student_meal_status_expiry_idx');
            });
        }

        if (! Schema::hasIndex('meal_subscriptions', $lookupColumns)) {
            Schema::table('meal_subscriptions', function (Blueprint $table) use ($lookupColumns) {
                $table->index($lookupColumns, 'meal_subscriptions_student_meal_status_expiry_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meal_subscriptions');
    }
};
