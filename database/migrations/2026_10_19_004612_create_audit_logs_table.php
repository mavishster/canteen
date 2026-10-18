<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // who did it (null = the system)
            $table->string('actor_name');                  // a copy: it stays readable if the account is removed
            $table->string('actor_role', 30)->nullable();
            $table->string('action', 60);                  // product.updated, card.blocked, staff.disabled...
            $table->string('entity', 40);                  // product, card, school...
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();   // a copy of the name at that moment
            $table->text('summary');                       // one readable line
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent(); // never updated

            $table->index(['school_id', 'id']);
            $table->index(['school_id', 'entity']);
            $table->index('user_id');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
