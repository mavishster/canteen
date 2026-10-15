<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A development database that ran an earlier DRAFT of this feature (from another branch) has tables with
        // the same names and a different shape (meal_plans.entitlement_quantity). They only hold draft data: replace them.
        // Our own tables never have that column, so this can never drop anything real.
        if (Schema::hasTable('meal_plans') && Schema::hasColumn('meal_plans', 'entitlement_quantity')) {
            Schema::dropIfExists('meal_consumptions');
            Schema::dropIfExists('meal_subscriptions');
            Schema::dropIfExists('meal_plans');
            Schema::dropIfExists('meal_types');

            DB::table('migrations')->whereIn('migration', [
                '2026_10_09_182355_create_meal_types_table',
                '2026_10_09_182356_create_meal_plans_table',
                '2026_10_09_182357_create_meal_subscriptions_table',
                '2026_10_09_182358_create_meal_consumptions_table',
            ])->delete();
        }

        Schema::create('meal_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->string('code', 60);                      // breakfast, lunch...
            $table->string('name');
            $table->time('service_starts_at')->nullable();   // collection hours, school time; both empty = no time limit
            $table->time('service_ends_at')->nullable();
            $table->json('available_days')->nullable();      // ISO weekdays 1-7; empty = every day
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_types');
    }
};
