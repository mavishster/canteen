<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every published text is kept for ever: a parent's acceptance points at the exact words they saw
        Schema::create('consent_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->text('text');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['school_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_documents');
    }
};
