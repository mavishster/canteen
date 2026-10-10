<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            // The SIS calls a school a "branch". One canteen school = one SIS branch.
            $table->unsignedInteger('sis_branch_id')->nullable()->unique()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropUnique(['sis_branch_id']);
            $table->dropColumn('sis_branch_id');
        });
    }
};
