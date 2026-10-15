<?php

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\MealException;
use App\Models\GuardianLink;
use App\Models\LedgerEntry;
use App\Models\MealPlan;
use App\Models\MealSubscription;
use App\Models\MealType;
use App\Models\ParentMessage;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\MealSubscriptionService;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

/** @return array{0: School, 1: Student, 2: MealType, 3: MealPlan} */
function mlsSetup(int $balance = 10000, ?int $grade = 4): array
{
    static $n = 0;
    $n++;

    $school = School::create(['name' => "Meal School {$n}", 'code' => "MLS{$n}"]);
    $student = Student::create(['school_id' => $school->id, 'student_code' => (string) (300000 + $n), 'name' => "Meal{$n} Kid", 'grade' => $grade]);

    if ($balance > 0) {
        app(LedgerService::class)->topUp($student->account, $balance, "mls-seed-{$n}");
    }

    $type = MealType::create(['school_id' => $school->id, 'code' => 'lunch', 'name' => 'Lunch']);
    $plan = mlsPlan($school, $type, '1 month', 1, 3000);

    return [$school, $student, $type, $plan];
}

function mlsPlan(School $school, MealType $type, string $name, int $months, int $price, ?int $min = null, ?int $max = null): MealPlan
{
    return MealPlan::create([
        'school_id' => $school->id,
        'meal_type_id' => $type->id,
        'name' => "Lunch {$name}",
        'min_grade' => $min,
        'max_grade' => $max,
        'duration_months' => $months,
        'price' => $price,
    ]);
}

function mlsUser(School $school, string $role): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user;
}

function mlsReasonOf(callable $fn): ?string
{
    try {
        $fn();
    } catch (MealException $e) {
        return $e->reason;
    }

    return null;
}

function mlsToday(): Carbon
{
    return Carbon::parse('2026-10-12 10:00', 'Asia/Phnom_Penh');
}

// ------------------------------------------------------------------ buying

it('pays a plan from the wallet and covers the right dates', function () {
    $this->travelTo(mlsToday());
    [, $student, , $plan] = mlsSetup(10000);

    $sub = app(MealSubscriptionService::class)->purchase($student, $plan->id, 'k1');

    expect($sub->valid_from->toDateString())->toBe('2026-10-12')
        ->and($sub->expires_on->toDateString())->toBe('2026-11-11')
        ->and($sub->price)->toBe(3000)
        ->and($student->account->refresh()->balance)->toBe(7000)
        ->and(LedgerEntry::where('account_id', $student->account->id)->latest('id')->first()->description)->toBe('Meal plan: Lunch 1 month');
});

it('covers a whole 3-month plan', function () {
    $this->travelTo(mlsToday());
    [$school, $student, $type] = mlsSetup(100000);
    $plan = mlsPlan($school, $type, '3 months', 3, 8000);

    $sub = app(MealSubscriptionService::class)->purchase($student, $plan->id, 'k1');

    expect($sub->expires_on->toDateString())->toBe('2027-01-11');
});

it('continues a renewal where the current plan ends', function () {
    $this->travelTo(mlsToday());
    [, $student, , $plan] = mlsSetup(10000);
    $meals = app(MealSubscriptionService::class);

    $meals->purchase($student, $plan->id, 'k1');
    $second = $meals->purchase($student, $plan->id, 'k2');

    expect($second->valid_from->toDateString())->toBe('2026-11-12')
        ->and($second->expires_on->toDateString())->toBe('2026-12-11')
        ->and($student->account->refresh()->balance)->toBe(4000);
});

it('does not chain plans of a different meal', function () {
    $this->travelTo(mlsToday());
    [$school, $student, , $lunch] = mlsSetup(10000);
    $breakfast = MealType::create(['school_id' => $school->id, 'code' => 'breakfast', 'name' => 'Breakfast']);
    $breakfastPlan = mlsPlan($school, $breakfast, '1 month', 1, 2000);
    $meals = app(MealSubscriptionService::class);

    $meals->purchase($student, $lunch->id, 'k1');
    $sub = $meals->purchase($student, $breakfastPlan->id, 'k2');

    expect($sub->valid_from->toDateString())->toBe('2026-10-12');
});

it('starts today again when the last plan has already ended', function () {
    $this->travelTo(mlsToday());
    [, $student, , $plan] = mlsSetup(10000);
    $meals = app(MealSubscriptionService::class);
    $meals->purchase($student, $plan->id, 'k1');                 // ends 2026-11-11

    $this->travelTo(Carbon::parse('2026-12-01 10:00', 'Asia/Phnom_Penh'));
    $sub = $meals->purchase($student, $plan->id, 'k2');

    expect($sub->valid_from->toDateString())->toBe('2026-12-01');
});

it('stops anyone being paid up more than a year ahead', function () {
    $this->travelTo(mlsToday());
    [$school, $student, $type] = mlsSetup(1000000);
    $year = mlsPlan($school, $type, '12 months', 12, 90000);
    $month = mlsPlan($school, $type, '1 month', 1, 3000);
    $meals = app(MealSubscriptionService::class);

    $meals->purchase($student, $year->id, 'k1');

    expect(mlsReasonOf(fn () => $meals->purchase($student, $year->id, 'k2')))->toBe('too_far_ahead')
        ->and(mlsReasonOf(fn () => $meals->purchase($student, $month->id, 'k3')))->toBe('too_far_ahead')
        ->and(MealSubscription::count())->toBe(1);
});

it('records nothing when the balance is too low', function () {
    [, $student, , $plan] = mlsSetup(1000);

    expect(fn () => app(MealSubscriptionService::class)->purchase($student, $plan->id, 'k1'))
        ->toThrow(InsufficientBalanceException::class);

    expect(MealSubscription::count())->toBe(0)
        ->and($student->account->refresh()->balance)->toBe(1000);
});

it('never charges twice for the same purchase key', function () {
    [, $student, , $plan] = mlsSetup(10000);
    $meals = app(MealSubscriptionService::class);

    $first = $meals->purchase($student, $plan->id, 'same');
    $again = $meals->purchase($student, $plan->id, 'same');

    expect($again->id)->toBe($first->id)
        ->and(MealSubscription::count())->toBe(1)
        ->and($student->account->refresh()->balance)->toBe(7000);
});

it('refuses a key that was used for a different plan', function () {
    [$school, $student, $type, $plan] = mlsSetup(100000);
    $other = mlsPlan($school, $type, '3 months', 3, 8000);
    $meals = app(MealSubscriptionService::class);

    $meals->purchase($student, $plan->id, 'same');

    expect(mlsReasonOf(fn () => $meals->purchase($student, $other->id, 'same')))->toBe('idempotency_conflict');
});

// ------------------------------------------------------------ who may buy what

it('limits a plan to its grade range', function () {
    [$school, , $type] = mlsSetup(100000, 8);
    $primaryOnly = mlsPlan($school, $type, 'primary', 1, 2000, 0, 6);
    $student8 = Student::withoutGlobalScopes()->where('school_id', $school->id)->first();
    $kg = Student::create(['school_id' => $school->id, 'student_code' => 'KG1', 'name' => 'Little Kid', 'grade' => 0]);
    app(LedgerService::class)->topUp($kg->account, 10000, 'kg-seed');
    $noGrade = Student::create(['school_id' => $school->id, 'student_code' => 'NOGRADE', 'name' => 'No Grade']);
    app(LedgerService::class)->topUp($noGrade->account, 10000, 'ng-seed');
    $meals = app(MealSubscriptionService::class);

    expect(mlsReasonOf(fn () => $meals->purchase($student8, $primaryOnly->id, 'a')))->toBe('plan_unavailable')
        ->and(mlsReasonOf(fn () => $meals->purchase($noGrade, $primaryOnly->id, 'b')))->toBe('plan_unavailable')
        ->and($meals->purchase($kg, $primaryOnly->id, 'c')->student_id)->toBe($kg->id);
});

it('refuses switched-off plans and meals, inactive students, and plans of another school', function () {
    [$school, $student, $type, $plan] = mlsSetup(100000);
    $meals = app(MealSubscriptionService::class);

    $plan->update(['is_active' => false]);
    expect(mlsReasonOf(fn () => $meals->purchase($student, $plan->id, 'a')))->toBe('plan_unavailable');

    $plan->update(['is_active' => true]);
    $type->update(['is_active' => false]);
    expect(mlsReasonOf(fn () => $meals->purchase($student, $plan->id, 'b')))->toBe('plan_unavailable');

    $type->update(['is_active' => true]);
    $student->update(['is_active' => false]);
    expect(mlsReasonOf(fn () => $meals->purchase($student, $plan->id, 'c')))->toBe('student_inactive');

    $student->update(['is_active' => true]);
    [, , , $foreignPlan] = mlsSetup();
    expect(mlsReasonOf(fn () => $meals->purchase($student, $foreignPlan->id, 'd')))->toBe('plan_unavailable');
});

// ------------------------------------------------------------- admin screens

it('lets an admin define meals and plans', function () {
    $school = School::create(['name' => 'Admin School', 'code' => 'ADM']);
    $this->actingAs(mlsUser($school, 'admin'));

    $this->post('/admin/meals/types', [
        'name' => 'Breakfast',
        'service_starts_at' => '06:30',
        'service_ends_at' => '08:00',
        'days' => ['1', '2', '3', '4', '5'],
    ])->assertRedirect(route('admin.meals'));

    $type = MealType::first();
    expect($type->code)->toBe('breakfast')
        ->and($type->available_days)->toBe([1, 2, 3, 4, 5]);

    $this->post('/admin/meals/plans', [
        'meal_type_id' => $type->id, 'name' => 'Breakfast 3 months', 'min_grade' => '0', 'max_grade' => '6',
        'duration_months' => '3', 'price' => '45.50',
    ])->assertRedirect(route('admin.meals'));

    $plan = MealPlan::first();
    expect($plan->price)->toBe(4550)
        ->and($plan->min_grade)->toBe(0)
        ->and($plan->max_grade)->toBe(6)
        ->and($plan->duration_months)->toBe(3);

    $this->get('/admin/meals')->assertOk()->assertSee('Breakfast')->assertSee('Breakfast 3 months');
});

it('rejects bad meal and plan definitions', function () {
    $school = School::create(['name' => 'Admin School', 'code' => 'ADM']);
    $type = MealType::create(['school_id' => $school->id, 'code' => 'lunch', 'name' => 'Lunch']);
    $this->actingAs(mlsUser($school, 'admin'));

    $this->post('/admin/meals/types', ['name' => 'X', 'service_starts_at' => '08:00'])->assertSessionHasErrors('service_starts_at');
    $this->post('/admin/meals/types', ['name' => 'X', 'service_starts_at' => '09:00', 'service_ends_at' => '08:00'])->assertSessionHasErrors('service_starts_at');

    $plan = ['meal_type_id' => $type->id, 'name' => 'P', 'duration_months' => '3', 'price' => '10'];
    $this->post('/admin/meals/plans', ['duration_months' => '2'] + $plan)->assertSessionHasErrors('duration_months');
    $this->post('/admin/meals/plans', ['min_grade' => '8', 'max_grade' => '2'] + $plan)->assertSessionHasErrors('max_grade');
    $this->post('/admin/meals/plans', ['price' => '0'] + $plan)->assertSessionHasErrors('price');

    expect(MealPlan::count())->toBe(0);
});

it('gives meals with the same name different codes', function () {
    $school = School::create(['name' => 'Admin School', 'code' => 'ADM']);
    $this->actingAs(mlsUser($school, 'admin'));

    $this->post('/admin/meals/types', ['name' => 'Lunch'])->assertRedirect();
    $this->post('/admin/meals/types', ['name' => 'Lunch'])->assertRedirect();

    expect(MealType::pluck('code')->sort()->values()->all())->toBe(['lunch', 'lunch-2']);
});

it('switches meals and plans on and off', function () {
    [$school, , $type, $plan] = mlsSetup();
    $this->actingAs(mlsUser($school, 'admin'));

    $this->post("/admin/meals/plans/{$plan->id}/toggle")->assertRedirect();
    $this->post("/admin/meals/types/{$type->id}/toggle")->assertRedirect();

    expect($plan->refresh()->is_active)->toBeFalse()
        ->and($type->refresh()->is_active)->toBeFalse();
});

it('sells a plan from the admin page, once even if the form is sent twice', function () {
    [$school, $student, , $plan] = mlsSetup(10000);
    $this->actingAs(mlsUser($school, 'admin'));

    $form = ['student_code' => $student->student_code, 'plan_id' => $plan->id, 'key' => 'page-load-1'];

    $this->post('/admin/meals/sell', $form)->assertRedirect(route('admin.meals'));
    $this->post('/admin/meals/sell', $form)->assertRedirect(route('admin.meals'));

    expect(MealSubscription::count())->toBe(1)
        ->and($student->account->refresh()->balance)->toBe(7000);

    $this->get('/admin/meals')->assertOk()->assertSee($student->name);
});

it('explains an unknown student or a low balance when selling', function () {
    [$school, $student, , $plan] = mlsSetup(1000);
    $this->actingAs(mlsUser($school, 'admin'));

    $this->post('/admin/meals/sell', ['student_code' => 'NOPE', 'plan_id' => $plan->id, 'key' => 'a'])
        ->assertSessionHasErrors('sell');
    $this->post('/admin/meals/sell', ['student_code' => $student->student_code, 'plan_id' => $plan->id, 'key' => 'b'])
        ->assertSessionHasErrors('sell');

    expect(MealSubscription::count())->toBe(0);
});

it('keeps cashiers and managers out of the meals admin', function () {
    [$school] = mlsSetup();

    $this->actingAs(mlsUser($school, 'cashier'))->get('/admin/meals')->assertForbidden();
    $this->actingAs(mlsUser($school, 'manager'))->get('/admin/meals')->assertForbidden();
});

it('cannot change another school\'s plans', function () {
    [, , , $theirs] = mlsSetup();
    [$mine] = mlsSetup();
    $this->actingAs(mlsUser($mine, 'admin'));

    $this->post("/admin/meals/plans/{$theirs->id}/toggle")->assertNotFound();

    expect($theirs->refresh()->is_active)->toBeTrue();
});

// --------------------------------------------------------------- the parent API

/** @return array{0: School, 1: Student, 2: MealType, 3: MealPlan, 4: User} */
function mlsApiSetup(int $balance = 10000, ?int $grade = 4): array
{
    [$school, $student, $type, $plan] = mlsSetup($balance, $grade);
    $parent = mlsUser($school, 'parent');
    GuardianLink::create(['school_id' => $school->id, 'user_id' => $parent->id, 'student_id' => $student->id]);

    return [$school, $student, $type, $plan, $parent->refresh()];
}

it('shows a parent the plans that fit their child and what is already paid', function () {
    $this->travelTo(mlsToday());
    [$school, $student, $type, $plan, $parent] = mlsApiSetup(10000, 8);
    mlsPlan($school, $type, 'primary only', 1, 2000, 0, 6);   // does not fit grade 8
    Sanctum::actingAs($parent);

    $response = $this->getJson("/api/parent/children/{$student->id}/meals")->assertOk();

    expect($response->json('meals.0.meal_type.name'))->toBe('Lunch')
        ->and($response->json('meals.0.paid_until'))->toBeNull()
        ->and($response->json('meals.0.active_now'))->toBeFalse()
        ->and($response->json('meals.0.plans'))->toHaveCount(1)
        ->and($response->json('meals.0.plans.0.price_formatted'))->toBe('$30.00');

    app(MealSubscriptionService::class)->purchase($student, $plan->id, 'k1');

    $after = $this->getJson("/api/parent/children/{$student->id}/meals")->assertOk();
    expect($after->json('meals.0.paid_until'))->toBe('2026-11-11')
        ->and($after->json('meals.0.active_now'))->toBeTrue();
});

it('lets a parent buy a plan, and a retry does not charge again', function () {
    $this->travelTo(mlsToday());
    [, $student, , $plan, $parent] = mlsApiSetup(10000);
    Sanctum::actingAs($parent);

    $body = ['plan_id' => $plan->id, 'key' => 'phone-attempt-1'];

    $this->postJson("/api/parent/children/{$student->id}/meals/subscribe", $body)
        ->assertCreated()
        ->assertJsonPath('expires_on', '2026-11-11')
        ->assertJsonPath('balance', 7000)
        ->assertJsonPath('balance_formatted', '$70.00');

    $this->postJson("/api/parent/children/{$student->id}/meals/subscribe", $body)->assertCreated();

    expect(MealSubscription::count())->toBe(1)
        ->and($student->account->refresh()->balance)->toBe(7000);
});

it('answers clearly when a parent cannot buy', function () {
    [, $student, , $plan, $parent] = mlsApiSetup(1000);
    Sanctum::actingAs($parent);

    $this->postJson("/api/parent/children/{$student->id}/meals/subscribe", ['plan_id' => $plan->id, 'key' => 'a'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'insufficient_balance');

    $this->postJson("/api/parent/children/{$student->id}/meals/subscribe", ['plan_id' => 999999, 'key' => 'b'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'plan_unavailable');

    $this->postJson("/api/parent/children/{$student->id}/meals/subscribe", ['plan_id' => $plan->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('key');
});

it('never lets a parent see or buy for another family\'s child', function () {
    [, , , $plan, $parent] = mlsApiSetup();
    [, $stranger] = mlsApiSetup();
    Sanctum::actingAs($parent);

    $this->getJson("/api/parent/children/{$stranger->id}/meals")->assertNotFound();
    $this->postJson("/api/parent/children/{$stranger->id}/meals/subscribe", ['plan_id' => $plan->id, 'key' => 'x'])->assertNotFound();
});

it('names the meal plan in the parent\'s purchase alert', function () {
    config(['messaging.push' => 'fake', 'messaging.sms' => 'fake']);
    [, $student, , $plan, $parent] = mlsApiSetup(10000);
    $parent->forceFill(['notify_purchase' => true])->save();

    app(MealSubscriptionService::class)->purchase($student, $plan->id, 'k1');

    $message = ParentMessage::withoutGlobalScopes()->where('user_id', $parent->id)->where('type', 'purchase')->first();

    expect($message->body)->toContain('Meal plan: Lunch 1 month')->toContain('$30.00');
});
