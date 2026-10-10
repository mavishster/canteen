<?php

use App\Models\LedgerEntry;
use App\Models\MealPlan;
use App\Models\MealType;
use App\Models\School;
use App\Models\Student;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/** @return array{0: School, 1: Student, 2: MealType} */
function parentMealSetup(string $studentCode = '42'): array
{
    $school = School::create(['name' => 'Parent API School', 'code' => 'PARENTAPI']);
    $student = Student::create([
        'school_id' => $school->id,
        'student_code' => $studentCode,
        'name' => 'Parent Child',
        'grade' => 2,
    ]);
    $mealType = MealType::create([
        'school_id' => $school->id,
        'code' => 'lunch',
        'name' => 'Lunch',
        'is_active' => true,
    ]);

    config([
        'services.sis.userinfo_url' => 'https://sis.test/oauth/userinfo',
        'services.sis.parent_id_claim' => 'parent_id',
        'database.connections.sis' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);
    DB::purge('sis');
    Schema::connection('sis')->create('student_parents', function ($table) {
        $table->unsignedInteger('student_id');
        $table->unsignedInteger('parent_id');
    });
    DB::connection('sis')->table('student_parents')->insert([
        'student_id' => (int) $studentCode,
        'parent_id' => 9001,
    ]);
    Http::fake(['https://sis.test/oauth/userinfo' => Http::response(['parent_id' => 9001], 200)]);
    Http::preventStrayRequests();

    return [$school, $student, $mealType];
}

it('authenticates parents with the SIS bearer token and returns only SIS-linked students', function () {
    [, $student] = parentMealSetup('00042');
    $otherSchool = School::create(['name' => 'Other Parent API School', 'code' => 'OTHERPARENT']);
    Student::create([
        'school_id' => $otherSchool->id,
        'student_code' => '99',
        'name' => 'Unlinked Child',
    ]);

    $this->withToken('valid-sis-access-token')
        ->getJson('/api/parent/students')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $student->id)
        ->assertJsonPath('data.0.student_id', '00042')
        ->assertJsonPath('data.0.name', 'Parent Child');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer valid-sis-access-token'));
});

it('requires an accepted SIS bearer token for parent endpoints', function () {
    $this->getJson('/api/parent/students')->assertUnauthorized();

    config([
        'services.sis.userinfo_url' => 'https://sis.test/oauth/userinfo',
        'services.sis.parent_id_claim' => 'parent_id',
    ]);
    Http::fake(['https://sis.test/oauth/userinfo' => Http::response([], 401)]);
    Http::preventStrayRequests();

    $this->withToken('expired-sis-access-token')
        ->getJson('/api/parent/students')
        ->assertUnauthorized();
});

it('returns not found when a parent requests an unlinked student', function () {
    parentMealSetup();
    $unlinked = Student::create([
        'school_id' => School::where('code', 'PARENTAPI')->value('id'),
        'student_code' => '77',
        'name' => 'Not Linked',
    ]);

    $this->withToken('valid-sis-access-token')
        ->getJson("/api/parent/students/{$unlinked->id}/meal-plans")
        ->assertNotFound();
});

it('lets a linked parent purchase a plan from the trusted student wallet', function () {
    [, $student, $mealType] = parentMealSetup();
    $plan = MealPlan::create([
        'school_id' => $student->school_id,
        'meal_type_id' => $mealType->id,
        'name' => 'Monthly lunch',
        'grade' => 2,
        'duration_months' => 1,
        'entitlement_quantity' => 20,
        'price' => 2500,
        'is_active' => true,
    ]);
    app(LedgerService::class)->topUp($student->account, 5000, 'parent-api-seed');

    $firstResponse = $this->withToken('valid-sis-access-token')
        ->withHeader('Idempotency-Key', 'parent-subscription-1')
        ->postJson("/api/parent/students/{$student->id}/subscriptions", ['meal_plan_id' => $plan->id])
        ->assertCreated()
        ->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonPath('data.remaining_entitlements', 20)
        ->assertJsonPath('data.wallet_balance', 2500);

    $this->withToken('valid-sis-access-token')
        ->withHeader('Idempotency-Key', 'parent-subscription-1')
        ->postJson("/api/parent/students/{$student->id}/subscriptions", ['meal_plan_id' => $plan->id])
        ->assertCreated()
        ->assertJsonPath('data.id', $firstResponse->json('data.id'));

    expect($student->mealSubscriptions()->count())->toBe(1)
        ->and(LedgerEntry::where('account_id', $student->account->id)->count())->toBe(2)
        ->and($student->account->refresh()->balance)->toBe(2500);
});

it('does not charge or create a subscription when a linked student wallet is insufficient', function () {
    [, $student, $mealType] = parentMealSetup();
    $plan = MealPlan::create([
        'school_id' => $student->school_id,
        'meal_type_id' => $mealType->id,
        'name' => 'Monthly lunch',
        'grade' => 2,
        'duration_months' => 1,
        'entitlement_quantity' => 20,
        'price' => 2500,
        'is_active' => true,
    ]);

    $this->withToken('valid-sis-access-token')
        ->withHeader('Idempotency-Key', 'parent-subscription-poor')
        ->postJson("/api/parent/students/{$student->id}/subscriptions", ['meal_plan_id' => $plan->id])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'insufficient_balance');

    expect($student->mealSubscriptions()->count())->toBe(0)
        ->and(LedgerEntry::count())->toBe(0)
        ->and($student->account->refresh()->balance)->toBe(0);
});
