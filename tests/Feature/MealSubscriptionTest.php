<?php

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\MealException;
use App\Models\LedgerEntry;
use App\Models\MealConsumption;
use App\Models\MealPlan;
use App\Models\MealSubscription;
use App\Models\MealType;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\CardService;
use App\Services\LedgerService;
use App\Services\MealSubscriptionService;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

function mealModuleAdmin(School $school, string $role = 'admin'): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user;
}

/** @return array{0:School, 1:Student, 2:MealType} */
function mealModuleSetup(string $studentCode = '00042'): array
{
    $school = School::create(['name' => 'Meal School', 'code' => 'MEAL']);
    $student = Student::create([
        'school_id' => $school->id,
        'student_code' => $studentCode,
        'name' => 'Meal Student',
        'grade' => 3,
    ]);
    $mealType = MealType::create([
        'school_id' => $school->id,
        'code' => 'lunch',
        'name' => 'Lunch',
        'is_active' => true,
    ]);

    return [$school, $student, $mealType];
}

function mealModulePlan(MealType $mealType, int $price = 2500, int $quantity = 8, int $duration = 1, ?int $grade = 3): MealPlan
{
    return MealPlan::create([
        'school_id' => $mealType->school_id,
        'meal_type_id' => $mealType->id,
        'name' => 'Monthly plan',
        'grade' => $grade,
        'duration_months' => $duration,
        'entitlement_quantity' => $quantity,
        'price' => $price,
        'is_active' => true,
    ]);
}

it('activates a fixed-price subscription and deducts the student wallet once', function () {
    $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'Asia/Phnom_Penh'));
    [, $student, $mealType] = mealModuleSetup();
    $plan = mealModulePlan($mealType, price: 2500, quantity: 8, duration: 1);
    app(LedgerService::class)->topUp($student->account, 5000, 'meal-seed');

    $service = app(MealSubscriptionService::class);
    $subscription = $service->purchase($student, $plan->id, 'sub-purchase-1');
    $retry = $service->purchase($student, $plan->id, 'sub-purchase-1');

    expect($retry->id)->toBe($subscription->id)
        ->and($subscription->status)->toBe('active')
        ->and($subscription->payment_status)->toBe('paid')
        ->and($subscription->price)->toBe(2500)
        ->and($subscription->entitlement_quantity)->toBe(8)
        ->and($subscription->consumed_quantity)->toBe(0)
        ->and($subscription->valid_from->toDateString())->toBe('2026-10-12')
        ->and($subscription->expires_on->toDateString())->toBe('2026-11-11')
        ->and($student->account->refresh()->balance)->toBe(2500)
        ->and(LedgerEntry::where('account_id', $student->account->id)->count())->toBe(2);
});

it('does not activate or charge a subscription when the wallet cannot cover it', function () {
    [, $student, $mealType] = mealModuleSetup();
    $plan = mealModulePlan($mealType);

    expect(fn () => app(MealSubscriptionService::class)->purchase($student, $plan->id, 'sub-too-poor'))
        ->toThrow(InsufficientBalanceException::class);

    expect(MealSubscription::count())->toBe(0)
        ->and($student->account->refresh()->balance)->toBe(0)
        ->and(LedgerEntry::count())->toBe(0);
});

it('does not allow a plan configured for another grade', function () {
    [, $student, $mealType] = mealModuleSetup();
    $plan = mealModulePlan($mealType, grade: 4);
    app(LedgerService::class)->topUp($student->account, 5000, 'meal-seed-grade');

    expect(fn () => app(MealSubscriptionService::class)->purchase($student, $plan->id, 'sub-grade'))
        ->toThrow(MealException::class);

    expect(MealSubscription::count())->toBe(0)
        ->and($student->account->refresh()->balance)->toBe(5000);
});

it('consumes one entitlement exactly once and rejects a second collection for that meal and date', function () {
    $this->travelTo(Carbon::parse('2026-10-12 12:00:00', 'Asia/Phnom_Penh'));
    [, $student, $lunch] = mealModuleSetup();
    $plan = mealModulePlan($lunch, quantity: 2);
    app(LedgerService::class)->topUp($student->account, 5000, 'meal-seed-collect');
    $service = app(MealSubscriptionService::class);
    $subscription = $service->purchase($student, $plan->id, 'sub-collect-seed');

    $first = $service->collect($student, $lunch->id, 'meal-collect-1', null, 'student_id', 'manual', '00042');
    $retry = $service->collect($student, $lunch->id, 'meal-collect-1', null, 'student_id', 'manual', '00042');

    expect($retry->id)->toBe($first->id)
        ->and($subscription->refresh()->consumed_quantity)->toBe(1)
        ->and($subscription->remainingEntitlements())->toBe(1)
        ->and(MealConsumption::count())->toBe(1);

    expect(fn () => $service->collect($student, $lunch->id, 'meal-collect-2', null, 'student_id', 'manual', '00042'))
        ->toThrow(MealException::class);

    expect($subscription->refresh()->consumed_quantity)->toBe(1)
        ->and(MealConsumption::count())->toBe(1);
});

it('keeps breakfast and lunch entitlements separate', function () {
    $this->travelTo(Carbon::parse('2026-10-12 08:00:00', 'Asia/Phnom_Penh'));
    [$school, $student, $lunch] = mealModuleSetup();
    $breakfast = MealType::create([
        'school_id' => $school->id,
        'code' => 'breakfast',
        'name' => 'Breakfast',
        'is_active' => true,
    ]);
    $lunchPlan = mealModulePlan($lunch, quantity: 4);
    $breakfastPlan = mealModulePlan($breakfast, price: 1500, quantity: 2);
    app(LedgerService::class)->topUp($student->account, 5000, 'meal-seed-types');
    $service = app(MealSubscriptionService::class);
    $lunchSubscription = $service->purchase($student, $lunchPlan->id, 'sub-lunch');
    $breakfastSubscription = $service->purchase($student, $breakfastPlan->id, 'sub-breakfast');

    $service->collect($student, $breakfast->id, 'collect-breakfast', null, 'student_id', 'manual', '00042');
    $service->collect($student, $lunch->id, 'collect-lunch', null, 'student_id', 'manual', '00042');

    expect($breakfastSubscription->refresh()->consumed_quantity)->toBe(1)
        ->and($lunchSubscription->refresh()->consumed_quantity)->toBe(1)
        ->and($student->account->refresh()->balance)->toBe(1000);
});

it('rejects expired entitlements without deleting payment or consumption history', function () {
    $this->travelTo(Carbon::parse('2026-10-12 12:00:00', 'Asia/Phnom_Penh'));
    [, $student, $mealType] = mealModuleSetup();
    $plan = mealModulePlan($mealType);
    app(LedgerService::class)->topUp($student->account, 5000, 'meal-seed-expire');
    $subscription = app(MealSubscriptionService::class)->purchase($student, $plan->id, 'sub-expire');
    $subscription->update(['expires_on' => '2026-10-11']);

    expect(fn () => app(MealSubscriptionService::class)->collect(
        $student,
        $mealType->id,
        'collect-expired',
        null,
        'rfid_card_id',
        'rfid',
        '00000001',
    ))->toThrow(MealException::class);

    expect($subscription->refresh()->consumed_quantity)->toBe(0)
        ->and(LedgerEntry::where('account_id', $student->account->id)->count())->toBe(2)
        ->and(MealConsumption::count())->toBe(0);
});

it('marks expired subscriptions while preserving their records', function () {
    $this->travelTo(Carbon::parse('2026-10-12 12:00:00', 'Asia/Phnom_Penh'));
    [, $student, $mealType] = mealModuleSetup();
    $plan = mealModulePlan($mealType);
    app(LedgerService::class)->topUp($student->account, 5000, 'meal-seed-expiry-job');
    $subscription = app(MealSubscriptionService::class)->purchase($student, $plan->id, 'sub-expiry-job');
    $subscription->update(['expires_on' => '2026-10-11']);

    $this->artisan('meals:expire-subscriptions')->assertSuccessful();

    expect($subscription->refresh()->status)->toBe('expired')
        ->and($subscription->remainingEntitlements())->toBe(0)
        ->and($subscription->exists)->toBeTrue()
        ->and(LedgerEntry::where('account_id', $student->account->id)->count())->toBe(2);
});

it('lets a cashier distribute an entitled meal by explicitly selected student ID, preserving leading zeros', function () {
    $this->travelTo(Carbon::parse('2026-10-12 12:00:00', 'Asia/Phnom_Penh'));
    config()->set('canteen.manual_meal_identification_enabled', true);
    [$school, $student, $mealType] = mealModuleSetup('000042');
    $plan = mealModulePlan($mealType);
    app(LedgerService::class)->topUp($student->account, 5000, 'meal-seed-manual');
    app(MealSubscriptionService::class)->purchase($student, $plan->id, 'sub-manual');
    $this->actingAs(mealModuleAdmin($school, 'cashier'));

    $this->postJson('/till/meals/collect', [
        'meal_type_id' => $mealType->id,
        'identifier_type' => 'student_id',
        'identification_method' => 'manual',
        'identifier' => '000042',
        'key' => 'collect-manual',
    ])
        ->assertOk()
        ->assertJsonPath('student.code', '000042')
        ->assertJsonPath('remaining_entitlements', 7);

    expect(MealConsumption::first()->identification_type)->toBe('student_id')
        ->and(MealConsumption::first()->identification_method)->toBe('manual')
        ->and(MealConsumption::first()->operator_id)->toBe(auth()->id());
});

it('distributes RFID and manually entered card IDs through the existing card resolver', function () {
    $this->travelTo(Carbon::parse('2026-10-12 12:00:00', 'Asia/Phnom_Penh'));
    config()->set('canteen.manual_meal_identification_enabled', true);
    [$school, $student, $mealType] = mealModuleSetup();
    $plan = mealModulePlan($mealType);
    app(LedgerService::class)->topUp($student->account, 5000, 'meal-seed-rfid');
    app(MealSubscriptionService::class)->purchase($student, $plan->id, 'sub-rfid');
    app(CardService::class)->bind($student, '00000001');
    $this->actingAs(mealModuleAdmin($school, 'cashier'));

    $this->postJson('/till/meals/collect', [
        'meal_type_id' => $mealType->id,
        'identifier_type' => 'rfid_card_id',
        'identification_method' => 'rfid',
        'identifier' => '00000001',
        'key' => 'collect-rfid',
    ])->assertOk();

    $this->postJson('/till/meals/collect', [
        'meal_type_id' => $mealType->id,
        'identifier_type' => 'rfid_card_id',
        'identification_method' => 'manual',
        'identifier' => '00000001',
        'key' => 'collect-manual-card',
    ])->assertUnprocessable()->assertJsonPath('reason', 'already_collected');

    expect(MealConsumption::count())->toBe(1)
        ->and(MealConsumption::first()->identification_type)->toBe('rfid_card_id');
});

it('requires an administrator to configure meal plans and prevents another school access', function () {
    $school = School::create(['name' => 'Admin Meal School', 'code' => 'MEALADMIN']);
    $otherSchool = School::create(['name' => 'Other Meal School', 'code' => 'MEALOTHER']);
    $foreignType = MealType::create([
        'school_id' => $otherSchool->id,
        'code' => 'lunch',
        'name' => 'Lunch',
        'is_active' => true,
    ]);
    $foreignPlan = mealModulePlan($foreignType);
    $this->actingAs(mealModuleAdmin($school, 'cashier'));

    $this->get('/admin/meal-plans')->assertForbidden();
    $this->post("/admin/meal-plans/{$foreignPlan->id}/toggle")->assertNotFound();
});

it('configures meal types and subscription prices in the school currency', function () {
    $school = School::create(['name' => 'Plan Admin School', 'code' => 'PLANADMIN', 'currency' => 'USD']);
    $this->actingAs(mealModuleAdmin($school));

    $this->post('/admin/meal-types', [
        'name' => 'Breakfast',
        'code' => 'BREAKFAST',
        'available_days' => ['1', '2', '3', '4', '5'],
        'service_starts_at' => '07:00',
        'service_ends_at' => '09:00',
    ])->assertRedirect(route('admin.meal-plans'));

    $mealType = MealType::where('school_id', $school->id)->firstOrFail();
    $this->post('/admin/meal-plans', [
        'meal_type_id' => $mealType->id,
        'name' => 'Grade 3 Monthly Breakfast',
        'grade' => 3,
        'duration_months' => 1,
        'entitlement_quantity' => 20,
        'price' => '12.50',
    ])->assertRedirect(route('admin.meal-plans'));

    $plan = MealPlan::where('school_id', $school->id)->firstOrFail();
    expect($mealType->refresh()->available_days)->toBe([1, 2, 3, 4, 5])
        ->and($plan->price)->toBe(1250)
        ->and($plan->duration_months)->toBe(1)
        ->and($plan->entitlement_quantity)->toBe(20);
});
