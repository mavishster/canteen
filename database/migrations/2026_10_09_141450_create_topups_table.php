<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->bigInteger('amount');                  // credited: minor units of the school's currency
            $table->char('pay_currency', 3);
            $table->bigInteger('pay_amount');              // what the payer pays: minor units of pay_currency
            $table->decimal('exchange_rate', 16, 6)->nullable();

            $table->string('gateway', 20);
            $table->string('gateway_ref', 32)->unique();   // our own reference, sent to the gateway
            $table->string('gateway_txn_id')->nullable();  // the bank's reference
            $table->text('checkout_url')->nullable();

            $table->string('status', 20)->default('pending');   // pending | paid | failed | review | expired
            $table->string('failure_reason')->nullable();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topups');
    }
};
