<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // For parents. The phone number comes from the school (admin or SIS), never typed in the app.
            $table->string('phone', 32)->nullable()->after('email');
            $table->boolean('notify_topup')->default(true);
            $table->boolean('notify_low_balance')->default(true);
            $table->boolean('notify_purchase')->default(false);
            $table->boolean('notify_sms')->default(true);
        });

        Schema::table('accounts', function (Blueprint $table) {
            // null = the school's default; 0 = no low-balance alerts for this child
            $table->bigInteger('low_balance_threshold')->nullable()->after('weekly_limit');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'notify_topup', 'notify_low_balance', 'notify_purchase', 'notify_sms']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('low_balance_threshold');
        });
    }
};
