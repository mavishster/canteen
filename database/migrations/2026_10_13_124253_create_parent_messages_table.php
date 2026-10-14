<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();   // the parent
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries')->nullOnDelete();
            $table->string('type', 20);                      // topup | low_balance | purchase
            $table->string('title');
            $table->text('body');
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->string('push_status', 16)->default('skipped');   // sent | failed | no_device | not_configured | skipped
            $table->string('sms_status', 16)->default('skipped');
            $table->timestamps();

            // The same event can never message the same parent twice, even if a queue job runs again
            $table->unique(['user_id', 'ledger_entry_id', 'type']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_messages');
    }
};
