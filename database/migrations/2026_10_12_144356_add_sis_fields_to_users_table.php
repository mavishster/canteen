<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Set only for parents who signed in through the SIS. They have no usable canteen password.
            $table->string('sis_user_id', 32)->nullable()->unique()->after('school_id');
            $table->timestamp('sis_synced_at')->nullable()->after('sis_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['sis_user_id']);
            $table->dropColumn(['sis_user_id', 'sis_synced_at']);
        });
    }
};
