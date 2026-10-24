<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per thing that must keep happening: scheduler, queue, backup, offsite
        Schema::create('heartbeats', function (Blueprint $table) {
            $table->string('name', 40)->primary();
            $table->timestamp('beat_at');
            $table->string('note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heartbeats');
    }
};
