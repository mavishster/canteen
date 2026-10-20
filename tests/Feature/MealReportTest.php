<?php

use App\Models\AuditLog;
use App\Models\MealPlan;
use App\Models\MealType;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\MealCollectionService;
use App\Services\MealSubscriptionService;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

function mrUser(School $school, string $role = 'manager'): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user;
}

function mrAt(string $when): Carbon
{
    return Carbon::parse($when, 'Asia/Phnom_Penh');   // 2026-10-12 is a Monday
}

/** @return array{0: School, 1: MealType, 2: MealType, 3: MealPlan, 4: MealPlan} */
function mrSetup(): array
{
    static $n = 0;
    $n++;

    $school = School::create(['name' => "Meal Report School {$n}", 'code' => "MR{$n}"]);
    $breakfast = MealType::create(['school_id' => $school->id, 'code' => 'breakfast', 'name' => 'Breakfast']);
    $lunch = MealType::create(['school_id' => $school->id, 'code' => 'lunch', 'name' => 'Lunch']);

    $bPlan = MealPlan::create(['school_id' => $school->id, 'meal_type_id' => $breakfast->id, 'name' => 'Breakfast 1 month', 'duration_months' => 1, 'price' => 1000]);
    $lPlan = MealPlan::create(['school_id' => $school->id, 'meal_type_id' => $lunch->id, 'name' => 'Lunch 1 month', 'duration_months' => 1, 'price' => 3000]);

    return [$school, $breakfast, $lunch, $bPlan, $lPlan];
}

function mrKid(School $school, string $code): Student
{
    $student = Student::create(['school_id' => $school->id, 'student_code' => $code, 'name' => "Kid {$code}", 'grade' => 3]);
    app(LedgerService::class)->topUp($student->account, 100000, "mr-seed-{$code}");

    return $student->refresh();
}

function mrBuy($test, Student $student, MealPlan $plan, string $when, string $key): void
{
    $test->travelTo(mrAt($when));
    app(MealSubscriptionService::class)->purchase($student, $plan->id, $key);
}

function mrServe($test, Student $student, MealType $type, string $when, string $key): void
{
    $test->travelTo(mrAt($when));
    app(MealCollectionService::class)->collect($student, (int) $student->school_id, null, $type->id, 'card', $key);
}

/** Two students, plans bought on 1 Oct, and a few meals served on 12 and 13 Oct. */
function mrScenario($test): array
{
    [$school, $breakfast, $lunch, $bPlan, $lPlan] = mrSetup();
    $a = mrKid($school, 'A1');
    $b = mrKid($school, 'B1');

    mrBuy($test, $a, $lPlan, '2026-10-01 09:00', 'a-l');
    mrBuy($test, $a, $bPlan, '2026-10-01 09:00', 'a-b');
    mrBuy($test, $b, $lPlan, '2026-10-01 09:00', 'b-l');

    mrServe($test, $a, $lunch, '2026-10-12 12:00', 'k1');
    mrServe($test, $b, $lunch, '2026-10-12 12:05', 'k2');
    mrServe($test, $a, $lunch, '2026-10-13 12:00', 'k3');
    mrServe($test, $a, $breakfast, '2026-10-13 07:00', 'k4');

    return [$school, $breakfast, $lunch, $a, $b];
}

// -------------------------------------------------------------- the numbers

it('counts the meals served per meal, per day and per student', function () {
    [$school, $breakfast, $lunch, $a, $b] = mrScenario($this);
    $this->travelTo(mrAt('2026-10-14 10:00'));

    $response = $this->actingAs(mrUser($school))
        ->get('/manager/reports/meals?from=2026-10-12&to=2026-10-13')
        ->assertOk()
        ->assertSee('A1')
        ->assertSee('B1');

    $perMeal = $response->viewData('perMeal');
    $byDay = $response->viewData('byDay');
    $matrix = $response->viewData('matrix');
    $rows = $response->viewData('students');

    expect((int) $perMeal[$lunch->id]->meals)->toBe(3)
        ->and((int) $perMeal[$lunch->id]->students)->toBe(2)
        ->and((int) $perMeal[$breakfast->id]->meals)->toBe(1)
        ->and($byDay['2026-10-12'][$lunch->id])->toBe(2)
        ->and($byDay['2026-10-13'][$lunch->id])->toBe(1)
        ->and($byDay['2026-10-13'][$breakfast->id])->toBe(1)
        ->and($rows->pluck('student_id')->all())->toBe([$a->id, $b->id])      // the biggest first
        ->and((int) $rows->first()->total)->toBe(3)
        ->and($matrix[$a->id][$lunch->id])->toBe(2)
        ->and($matrix[$a->id][$breakfast->id])->toBe(1)
        ->and($matrix[$b->id][$lunch->id])->toBe(1);
});

it('leaves out the days outside the period', function () {
    [$school, , $lunch] = mrScenario($this);
    $this->travelTo(mrAt('2026-10-14 10:00'));

    $response = $this->actingAs(mrUser($school))->get('/manager/reports/meals?from=2026-10-13&to=2026-10-13')->assertOk();

    expect((int) $response->viewData('perMeal')[$lunch->id]->meals)->toBe(1)
        ->and(array_keys($response->viewData('byDay')))->toBe(['2026-10-13']);
});

it('reports today\'s servings and the students who have a plan today', function () {
    [$school, , $lunch] = mrScenario($this);
    $this->travelTo(mrAt('2026-10-13 15:00'));

    $response = $this->actingAs(mrUser($school))->get('/manager/reports/meals?from=2026-10-01&to=2026-10-13')->assertOk();

    expect((int) $response->viewData('servedToday')[$lunch->id])->toBe(1)
        ->and((int) $response->viewData('withPlanToday')[$lunch->id])->toBe(2);
});

it('filters by meal and by student', function () {
    [$school, $breakfast, $lunch, $a, $b] = mrScenario($this);
    $this->travelTo(mrAt('2026-10-14 10:00'));
    $this->actingAs(mrUser($school));

    $byMeal = $this->get("/manager/reports/meals?from=2026-10-12&to=2026-10-13&meal={$breakfast->id}")->assertOk();
    expect($byMeal->viewData('students')->pluck('student_id')->all())->toBe([$a->id]);

    $byName = $this->get('/manager/reports/meals?from=2026-10-12&to=2026-10-13&q=B1')->assertOk();
    expect($byName->viewData('students')->pluck('student_id')->all())->toBe([$b->id]);
});

it('lists plans that end soon, but not the renewed ones or the far-off ones', function () {
    [$school, , , $bPlan, $lPlan] = mrSetup();
    $ending = mrKid($school, 'END1');
    $renewed = mrKid($school, 'REN1');
    $far = mrKid($school, 'FAR1');
    $sixMonths = MealPlan::create(['school_id' => $school->id, 'meal_type_id' => $lPlan->meal_type_id, 'name' => 'Lunch 6 months', 'duration_months' => 6, 'price' => 15000]);

    mrBuy($this, $ending, $lPlan, '2026-10-01 09:00', 'e1');      // ends 31 Oct
    mrBuy($this, $renewed, $lPlan, '2026-10-01 09:00', 'r1');     // ends 31 Oct...
    mrBuy($this, $renewed, $lPlan, '2026-10-02 09:00', 'r2');     // ...renewed: now ends 30 Nov
    mrBuy($this, $far, $sixMonths, '2026-10-01 09:00', 'f1');     // ends in March

    $this->travelTo(mrAt('2026-10-25 10:00'));

    $response = $this->actingAs(mrUser($school))->get('/manager/reports/meals')->assertOk();
    $codes = $response->viewData('endingSoon')->map(fn ($r) => $response->viewData('endingPeople')[$r->student_id]->student_code)->all();

    expect($codes)->toBe(['END1']);
});

it('rejects a bad period', function () {
    [$school] = mrScenario($this);
    $this->actingAs(mrUser($school));

    $this->get('/manager/reports/meals?from=2026-10-13&to=2026-10-01')->assertSessionHasErrors('from');
    $this->get('/manager/reports/meals?from=2020-01-01&to=2026-10-01')->assertSessionHasErrors('from');
    $this->get('/manager/reports/meals?from=oops')->assertSessionHasErrors('from');
});

it('keeps another school\'s meals out of the report', function () {
    [$school] = mrScenario($this);
    [$other] = mrSetup();
    $this->travelTo(mrAt('2026-10-14 10:00'));

    $response = $this->actingAs(mrUser($other))->get('/manager/reports/meals?from=2026-10-12&to=2026-10-13')->assertOk();

    expect($response->viewData('students')->count())->toBe(0)
        ->and($response->viewData('byDay'))->toBe([]);
});

// -------------------------------------------------------------------- CSV

it('exports counts per student without names, and writes the export to the audit log', function () {
    [$school] = mrScenario($this);
    $this->travelTo(mrAt('2026-10-14 10:00'));
    $this->actingAs(mrUser($school));

    $csv = $this->get('/manager/reports/meals.csv?from=2026-10-12&to=2026-10-13')->assertOk()->streamedContent();

    expect($csv)->toContain('student_code,grade,Breakfast,Lunch,total')
        ->toContain('A1,3,1,2,3')
        ->toContain('B1,3,0,1,1')
        ->not->toContain('Kid A1')
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'report.exported')->count())->toBe(1);
});

// ------------------------------------------------------------------ access

it('lets managers in, keeps cashiers out, and links the report from the menu', function () {
    [$school] = mrScenario($this);
    $this->travelTo(mrAt('2026-10-14 10:00'));

    $this->actingAs(mrUser($school, 'cashier'))->get('/manager/reports/meals')->assertForbidden();
    $this->actingAs(mrUser($school, 'cashier'))->get('/manager/reports/meals.csv')->assertForbidden();

    $this->actingAs(mrUser($school, 'manager'))->get('/manager/reports/meals')->assertOk()->assertSee('Meals report');
    $this->actingAs(mrUser($school, 'manager'))->get('/manager/reports/daily')->assertOk()->assertSee('href="' . route('manager.reports.meals') . '"', false);
});
