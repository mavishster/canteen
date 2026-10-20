<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();            // the parent
            $table->foreignId('consent_document_id')->constrained()->restrictOnDelete();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();                             // = when it was accepted

            $table->unique(['user_id', 'consent_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_consents');
    }
};
