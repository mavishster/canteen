<?php

use App\Exceptions\CardException;
use App\Exceptions\MealException;
use App\Models\MealConsumption;
use App\Models\MealPlan;
use App\Models\MealType;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\CardService;
use App\Services\LedgerService;
use App\Services\MealCollectionService;
use App\Services\MealSubscriptionService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Spatie\Permission\Models\Role;

function mscAt(string $when): Carbon
{
    return Carbon::parse($when, 'Asia/Phnom_Penh');
}

function mscUser(School $school, string $role): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user;
}

/** @return array{0: School, 1: Student, 2: string, 3: MealType, 4: MealPlan, 5: User} */
function mscSetup(int $balance = 20000): array
{
    static $n = 0;
    $n++;

    $school = School::create(['name' => "Screen School {$n}", 'code' => "SCR{$n}"]);
    $student = Student::create([
        'school_id' => $school->id,
        'student_code' => (string) (400000 + $n),
        'name' => "Screen{$n} Child",
        'grade' => 2,
    ]);

    $uid = strtoupper(dechex(0x80000000 + $n));
    app(CardService::class)->bind($student, $uid);
    app(LedgerService::class)->topUp($student->account, $balance, "scr-seed-{$n}");

    $lunch = MealType::create([
        'school_id' => $school->id,
        'code' => 'lunch',
        'name' => 'Lunch',
        'service_starts_at' => '11:00',
        'service_ends_at' => '13:30',
        'available_days' => [1, 2, 3, 4, 5],
        'is_active' => true,
    ]);

    $plan = MealPlan::create([
        'school_id' => $school->id,
        'meal_type_id' => $lunch->id,
        'name' => 'Lunch 1 month',
        'duration_months' => 1,
        'price' => 3000,
        'is_active' => true,
    ]);

    return [$school, $student, $uid, $lunch, $plan, mscUser($school, 'cashier')];
}

function mscTap($test, string $identifier, int $mealTypeId, string $key, ?string $confirm = null)
{
    return $test->postJson('/till/meals/collect', [
        'identifier' => $identifier,
        'meal_type_id' => $mealTypeId,
        'key' => $key,
        'confirm_student' => $confirm,
    ]);
}

// ------------------------------------------------------------ the happy path

it('serves a lunch to a card holder with a plan and counts it, moving no money', function () {
    $this->travelTo(mscAt('2026-10-12 12:00'));
    [, $student, $uid, $lunch, $plan, $cashier] = mscSetup();
    app(MealSubscriptionService::class)->purchase($student, $plan->id, 'sub-1');
    $balance = $student->account->refresh()->balance;

    $this->actingAs($cashier);
    mscTap($this, $uid, $lunch->id, 'tap-1')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('student.first_name', 'Screen1')
        ->assertJsonPath('meal', 'Lunch')
        ->assertJsonPath('time', '12:00')
        ->assertJsonPath('counts.0.count', 1);

    expect(MealConsumption::count())->toBe(1)
        ->and(MealConsumption::first()->identified_by)->toBe('card')
        ->and($student->account->refresh()->balance)->toBe($balance);
});

it('refuses a second lunch the same day, but serves breakfast', function () {
    $this->travelTo(mscAt('2026-10-12 07:00'));
    [$school, $student, $uid, $lunch, , $cashier] = mscSetup();
    $breakfast = MealType::create(['school_id' => $school->id, 'code' => 'breakfast', 'name' => 'Breakfast', 'is_active' => true]);
    $breakfastPlan = MealPlan::create(['school_id' => $school->id, 'meal_type_id' => $breakfast->id, 'name' => 'Breakfast 1 month', 'duration_months' => 1, 'price' => 2000, 'is_active' => true]);
    app(MealSubscriptionService::class)->purchase($student, $breakfastPlan->id, 'b-sub');
    app(MealSubscriptionService::class)->purchase($student, MealPlan::where('meal_type_id', $lunch->id)->value('id'), 'l-sub');

    $this->actingAs($cashier);
    mscTap($this, $uid, $breakfast->id, 'tap-b1')->assertOk();
    mscTap($this, $uid, $breakfast->id, 'tap-b2')->assertStatus(422)->assertJsonPath('reason', 'already_collected');

    $this->travelTo(mscAt('2026-10-12 12:00'));
    mscTap($this, $uid, $lunch->id, 'tap-l1')->assertOk();

    expect(MealConsumption::count())->toBe(2);
});

it('does not count the same tap twice when it is sent again', function () {
    $this->travelTo(mscAt('2026-10-12 12:00'));
    [, $student, $uid, $lunch, $plan, $cashier] = mscSetup();
    app(MealSubscriptionService::class)->purchase($student, $plan->id, 'sub-1');

    $this->actingAs($cashier);
    mscTap($this, $uid, $lunch->id, 'same-tap')->assertOk();
    mscTap($this, $uid, $lunch->id, 'same-tap')->assertOk();

    expect(MealConsumption::count())->toBe(1);
});

// ------------------------------------------------------------ why a meal is refused

it('refuses a child who has no plan for that meal', function () {
    $this->travelTo(mscAt('2026-10-12 12:00'));
    [, , $uid, $lunch, , $cashier] = mscSetup();

    $this->actingAs($cashier);
    mscTap($this, $uid, $lunch->id, 'k1')->assertStatus(422)->assertJsonPath('reason', 'no_subscription');
});

it('refuses a plan that has already ended', function () {
    $this->travelTo(mscAt('2026-09-01 12:00'));
    [, $student, $uid, $lunch, $plan, $cashier] = mscSetup();
    app(MealSubscriptionService::class)->purchase($student, $plan->id, 'sub-1');   // covers September

    $this->travelTo(mscAt('2026-10-12 12:00'));

    $this->actingAs($cashier);
    mscTap($this, $uid, $lunch->id, 'k1')->assertStatus(422)->assertJsonPath('reason', 'no_subscription');
});

it('refuses a meal outside its hours', function () {
    $this->travelTo(mscAt('2026-10-12 10:00'));
    [, $student, $uid, $lunch, $plan, $cashier] = mscSetup();
    app(MealSubscriptionService::class)->purchase($student, $plan->id, 'sub-1');

    $this->actingAs($cashier);
    mscTap($this, $uid, $lunch->id, 'k1')->assertStatus(422)->assertJsonPath('reason', 'outside_hours');
});

it('refuses a meal on a day it is not served', function () {
    $this->travelTo(mscAt('2026-10-12 12:00'));
    [, $student, $uid, $lunch, $plan, $cashier] = mscSetup();
    app(MealSubscriptionService::class)->purchase($student, $plan->id, 'sub-1');

    $this->travelTo(mscAt('2026-10-17 12:00'));   // Saturday

    $this->actingAs($cashier);
    mscTap($this, $uid, $lunch->id, 'k1')->assertStatus(422)->assertJsonPath('reason', 'meal_unavailable');
});

it('refuses every meal on a school closed day', function () {
    $this->travelTo(mscAt('2026-10-12 12:00'));
    [$school, $student, $uid, $lunch, $plan, $cashier] = mscSetup();
    app(MealSubscriptionService::class)->purchase($student, $plan->id, 'sub-1');
    $school->update(['settings' => ['calendar' => ['closed_dates' => ['2026-10-12']]]]);

    $this->actingAs($cashier);
    mscTap($this, $uid, $lunch->id, 'k1')->assertStatus(422)->assertJsonPath('reason', 'meal_unavailable');
});

it('refuses a blocked card, and an inactive student', function () {
    $this->travelTo(mscAt('2026-10-12 12:00'));
    [, $student, $uid, $lunch, $plan, $cashier] = mscSetup();
    app(MealSubscriptionService::class)->purchase($student, $plan->id, 'sub-1');
    app(CardService::class)->block(app(CardService::class)->currentCard($student));

    $this->actingAs($cashier);
    mscTap($this, $uid, $lunch->id, 'k1')->assertStatus(422)->assertJsonPath('reason', 'card_blocked');

    app(CardService::class)->unblock(app(CardService::class)->currentCard($student));
    $student->update(['is_active' => false]);
    mscTap($this, $uid, $lunch->id, 'k2')->assertStatus(422)->assertJsonPath('reason', 'student_inactive');
});

it('says so for an unknown card and an unknown number', function () {
    $this->travelTo(mscAt('2026-10-12 12:00'));
    [, , , $lunch, , $cashier] = mscSetup();

    $this->actingAs($cashier);
    mscTap($this, 'AABBCCDD', $lunch->id, 'k1')->assertStatus(422)->assertJsonPath('reason', 'unknown_card');
    mscTap($this, '123456', $lunch->id, 'k2')->assertStatus(422)->assertJsonPath('reason', 'unknown_identifier');
});

// ------------------------------------------------------------ typed numbers

it('asks for confirmation before recording a typed student number', function () {
    $this->travelTo(mscAt('2026-10-12 12:00'));
    [, $student, , $lunch, $plan, $cashier] = mscSetup();
    app(MealSubscriptionService::class)->purchase($student, $plan->id, 'sub-1');

    $this->actingAs($cashier);

    mscTap($this, $student->student_code, $lunch->id, 'k1')
        ->assertOk()
        ->assertJsonPath('confirm', true)
        ->assertJsonPath('student.first_name', explode(' ', $student->name)[0]);

    expect(MealConsumption::count())->toBe(0);

    mscTap($this, $student->student_code, $lunch->id, 'k1', confirm: $student->student_code)
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(MealConsumption::first()->identified_by)->toBe('manual');
});

it('refuses typed numbers when the school has switched them off, but cards still work', function () {
    $this->travelTo(mscAt('2026-10-12 12:00'));
    [$school, $student, $uid, $lunch, $plan, $cashier] = mscSetup();
    app(MealSubscriptionService::class)->purchase($student, $plan->id, 'sub-1');
    $school->update(['settings' => ['till' => ['manual_entry' => false]]]);

    $this->actingAs($cashier);
    mscTap($this, $student->student_code, $lunch->id, 'k1', confirm: $student->student_code)
        ->assertStatus(422)->assertJsonPath('reason', 'manual_disabled');
    mscTap($this, $uid, $lunch->id, 'k2')->assertOk();
});

// ------------------------------------------------------------ safety

it('never serves a student of another school', function () {
    $this->travelTo(mscAt('2026-10-12 12:00'));
    [, $otherStudent, , , ,] = mscSetup();
    [, , , $lunch, $plan, $cashier] = mscSetup();

    $this->actingAs($cashier);
    mscTap($this, $otherStudent->student_code, $lunch->id, 'k1')->assertStatus(422)->assertJsonPath('reason', 'unknown_identifier');

    expect(fn() => app(MealCollectionService::class)->collect($otherStudent, (int) $cashier->school_id, null, $lunch->id, 'card', 'k2'))
        ->toThrow(CardException::class);
});

it('returns the original record for a repeated key, and refuses that key for another child', function () {
    $this->travelTo(mscAt('2026-10-12 12:00'));
    [$school, $student, , $lunch, $plan] = mscSetup();
    app(MealSubscriptionService::class)->purchase($student, $plan->id, 'sub-1');
    $other = Student::create(['school_id' => $school->id, 'student_code' => 'OTHERKID', 'name' => 'Other Kid']);
    $meals = app(MealCollectionService::class);

    $first = $meals->collect($student, $school->id, null, $lunch->id, 'card', 'key-x');
    $again = $meals->collect($student, $school->id, null, $lunch->id, 'card', 'key-x');

    expect($again->id)->toBe($first->id);

    expect(fn() => $meals->collect($other, $school->id, null, $lunch->id, 'card', 'key-x'))
        ->toThrow(MealException::class);
});

it('lets the database refuse a second lunch for the same child and day, whatever the code does', function () {
    $this->travelTo(mscAt('2026-10-12 12:00'));
    [$school, $student, , $lunch, $plan] = mscSetup();
    app(MealSubscriptionService::class)->purchase($student, $plan->id, 'sub-1');
    $first = app(MealCollectionService::class)->collect($student, $school->id, null, $lunch->id, 'card', 'k1');

    expect(fn() => MealConsumption::create([
        'school_id' => $school->id,
        'student_id' => $student->id,
        'meal_type_id' => $lunch->id,
        'meal_subscription_id' => $first->meal_subscription_id,
        'service_date' => '2026-10-12',
        'identified_by' => 'card',
        'idempotency_key' => 'sneaky',
    ]))->toThrow(QueryException::class);
});

it('reports today\'s counts per meal', function () {
    $this->travelTo(mscAt('2026-10-12 12:00'));
    [$school, $student, $uid, $lunch, $plan, $cashier] = mscSetup();
    app(MealSubscriptionService::class)->purchase($student, $plan->id, 'sub-1');
    $this->actingAs($cashier);
    mscTap($this, $uid, $lunch->id, 'k1')->assertOk();

    $this->getJson('/till/meals/counts')
        ->assertOk()
        ->assertJsonPath('counts.0.name', 'Lunch')
        ->assertJsonPath('counts.0.count', 1);
});

// ------------------------------------------------------------ pages and calendar

it('opens the meal screen for staff and refuses parents', function () {
    [$school] = mscSetup();

    $this->actingAs(mscUser($school, 'cashier'))->get('/till/meals')->assertOk()->assertSee('Lunch');
    $this->actingAs(mscUser($school, 'parent'))->get('/till/meals')->assertForbidden();
});

it('lets an admin save closed days, sorted and without duplicates', function () {
    [$school] = mscSetup();
    $this->actingAs(mscUser($school, 'admin'));

    $this->post('/admin/meals/closed-days', ['dates' => "2026-12-25\n2026-01-01, 2026-12-25"])->assertRedirect(route('admin.meals'));

    expect($school->refresh()->settings['calendar']['closed_dates'])->toBe(['2026-01-01', '2026-12-25']);
});

it('rejects a closed day that is not a real date', function () {
    [$school] = mscSetup();
    $this->actingAs(mscUser($school, 'admin'));

    $this->post('/admin/meals/closed-days', ['dates' => '2026-02-30'])->assertSessionHasErrors('dates');
    $this->post('/admin/meals/closed-days', ['dates' => 'tomorrow'])->assertSessionHasErrors('dates');
});

it('keeps cashiers out of the closed days', function () {
    [$school] = mscSetup();

    $this->actingAs(mscUser($school, 'cashier'))->post('/admin/meals/closed-days', ['dates' => '2026-12-25'])->assertForbidden();
});
