<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('type');                       // topup | purchase | refund | adjustment
            $table->bigInteger('amount');                 // signed, minor units of the school's currency
            $table->bigInteger('balance_after');
            $table->string('idempotency_key');
            $table->string('description')->nullable();
            $table->string('reference')->nullable();      // e.g. ABA transaction id, sale id

            // Only for top-ups paid in the other currency
            $table->char('source_currency', 3)->nullable();
            $table->bigInteger('source_amount')->nullable();
            $table->decimal('exchange_rate', 16, 6)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();   // no updated_at: entries never change

            $table->unique(['school_id', 'idempotency_key']);
            $table->index(['account_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
