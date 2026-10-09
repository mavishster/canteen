<?php

use App\Exceptions\SaleException;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\CardService;
use App\Services\LedgerService;
use App\Services\RuleService;
use App\Services\SaleService;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

/** @return array{0: School, 1: Student, 2: string} */
function ruleSetup(array $settings = [], ?int $grade = 4, int $balance = 100000): array
{
    static $n = 0;
    $n++;

    $school = School::create([
        'name' => "Rule School {$n}",
        'code' => "RULE{$n}",
        'settings' => $settings ?: null,
    ]);

    $student = Student::create([
        'school_id' => $school->id,
        'student_code' => "R{$n}",
        'name' => "Rule Kid {$n}",
        'grade' => $grade,
    ]);

    $uid = strtoupper(dechex(0x20000000 + $n));
    app(CardService::class)->bind($student, $uid);
    app(LedgerService::class)->topUp($student->account, $balance, "rule-seed-{$n}");

    return [$school, $student, $uid];
}

function ruleProduct(School $school, string $name, int $price, ?Category $category = null): Product
{
    return Product::create([
        'school_id' => $school->id,
        'category_id' => $category?->id,
        'name' => $name,
        'price' => $price,
    ]);
}

function ruleBuy(School $school, string $uid, Product $product, int $qty, string $key): Sale
{
    return app(SaleService::class)->checkout($school->id, null, $uid, [
        ['product_id' => $product->id, 'quantity' => $qty],
    ], $key);
}

/** @return array{reason:string, message:string}|null */
function ruleFail(callable $fn): ?array
{
    try {
        $fn();
    } catch (SaleException $e) {
        return ['reason' => $e->reason, 'message' => $e->getMessage()];
    }

    return null;
}

function ruleMonday(string $time = '10:00'): Carbon
{
    return Carbon::parse("2026-10-12 {$time}", 'Asia/Phnom_Penh');   // a Monday
}

// ------------------------------------------------------------------ limits

it('stops spending above the daily cap and allows exactly the cap', function () {
    $this->travelTo(ruleMonday());
    [$school, $student, $uid] = ruleSetup(['limits' => ['daily' => [[1, 12, 1000]]]]);
    $rice = ruleProduct($school, 'Rice', 600);
    $juice = ruleProduct($school, 'Juice', 400);

    ruleBuy($school, $uid, $rice, 1, 'a');                              // 600 of 1000

    $fail = ruleFail(fn () => ruleBuy($school, $uid, $rice, 1, 'b'));   // would be 1200
    expect($fail['reason'])->toBe('over_daily_limit')
        ->and($fail['message'])->toContain('$4.00');

    ruleBuy($school, $uid, $juice, 1, 'c');                             // exactly 1000

    expect($student->account->refresh()->balance)->toBe(100000 - 1000);
});

it('resets the daily cap the next day', function () {
    $this->travelTo(ruleMonday());
    [$school, , $uid] = ruleSetup(['limits' => ['daily' => [[1, 12, 1000]]]]);
    $rice = ruleProduct($school, 'Rice', 1000);

    ruleBuy($school, $uid, $rice, 1, 'a');
    expect(ruleFail(fn () => ruleBuy($school, $uid, $rice, 1, 'b'))['reason'])->toBe('over_daily_limit');

    $this->travelTo(ruleMonday()->addDay());
    ruleBuy($school, $uid, $rice, 1, 'c');   // no exception = allowed

    expect(Sale::count())->toBe(2);
});

it('applies the weekly cap for the right grade band', function () {
    $this->travelTo(ruleMonday());
    [$school, , $uid] = ruleSetup(['limits' => ['weekly' => [[1, 6, 2000], [7, 9, 5000]]]], grade: 8);
    $meal = ruleProduct($school, 'Meal', 3000);
    $snack = ruleProduct($school, 'Snack', 2500);

    ruleBuy($school, $uid, $meal, 1, 'a');   // 3000 of 5000 (grade 8 uses the 7-9 band)

    $fail = ruleFail(fn () => ruleBuy($school, $uid, $snack, 1, 'b'));

    expect($fail['reason'])->toBe('over_weekly_limit')
        ->and($fail['message'])->toContain('$20.00');
});

it('lets a parent set a lower limit, which then wins over the school cap', function () {
    $this->travelTo(ruleMonday());
    [$school, $student, $uid] = ruleSetup(['limits' => ['weekly' => [[1, 12, 5000]]]]);
    $meal = ruleProduct($school, 'Meal', 1500);

    app(RuleService::class)->setLimits($student, null, 1000);

    expect(ruleFail(fn () => ruleBuy($school, $uid, $meal, 1, 'a'))['reason'])->toBe('over_weekly_limit');
});

it('does not let a parent set a limit above the school maximum', function () {
    [, $student] = ruleSetup(['limits' => ['weekly' => [[1, 12, 5000]]]]);

    $fail = ruleFail(fn () => app(RuleService::class)->setLimits($student, null, 9000));

    expect($fail['reason'])->toBe('limit_above_school_max');
});

it('records nothing when a limit rejects the purchase', function () {
    $this->travelTo(ruleMonday());
    [$school, $student, $uid] = ruleSetup(['limits' => ['daily' => [[1, 12, 500]]]]);
    $meal = ruleProduct($school, 'Meal', 800);

    ruleFail(fn () => ruleBuy($school, $uid, $meal, 1, 'a'));

    expect(Sale::count())->toBe(0)
        ->and($student->account->refresh()->balance)->toBe(100000);
});

it('returns the original sale on a retry even after the limit is reached', function () {
    $this->travelTo(ruleMonday());
    [$school, , $uid] = ruleSetup(['limits' => ['daily' => [[1, 12, 1000]]]]);
    $meal = ruleProduct($school, 'Meal', 1000);

    $first = ruleBuy($school, $uid, $meal, 1, 'same-key');
    $retry = ruleBuy($school, $uid, $meal, 1, 'same-key');   // limit is full, but this is a retry

    expect($retry->id)->toBe($first->id)
        ->and(Sale::count())->toBe(1);
});

// -------------------------------------------------------------------- bans

it('refuses a banned product but allows others', function () {
    [$school, $student, $uid] = ruleSetup();
    $cola = ruleProduct($school, 'Cola', 100);
    $water = ruleProduct($school, 'Water', 50);

    app(RuleService::class)->banProduct($student, $cola);

    $fail = ruleFail(fn () => ruleBuy($school, $uid, $cola, 1, 'a'));
    expect($fail['reason'])->toBe('banned_product')
        ->and($fail['message'])->toContain('Cola');

    ruleBuy($school, $uid, $water, 1, 'b');
    expect(Sale::count())->toBe(1);
});

it('refuses every product in a banned category', function () {
    [$school, $student, $uid] = ruleSetup();
    $sweets = Category::create(['school_id' => $school->id, 'name' => 'Sweets']);
    $candy = ruleProduct($school, 'Candy', 50, $sweets);
    $gum = ruleProduct($school, 'Gum', 30, $sweets);

    app(RuleService::class)->banCategory($student, $sweets);

    expect(ruleFail(fn () => ruleBuy($school, $uid, $candy, 1, 'a'))['reason'])->toBe('banned_product');
    expect(ruleFail(fn () => ruleBuy($school, $uid, $gum, 1, 'b'))['reason'])->toBe('banned_product');
});

it('refuses the whole cart when one item is banned', function () {
    [$school, $student, $uid] = ruleSetup();
    $cola = ruleProduct($school, 'Cola', 100);
    $water = ruleProduct($school, 'Water', 50);
    app(RuleService::class)->banProduct($student, $cola);

    $fail = ruleFail(fn () => app(SaleService::class)->checkout($school->id, null, $uid, [
        ['product_id' => $water->id, 'quantity' => 1],
        ['product_id' => $cola->id, 'quantity' => 1],
    ], 'a'));

    expect($fail['reason'])->toBe('banned_product')
        ->and(Sale::count())->toBe(0);
});

it('allows the product again after the ban is removed', function () {
    [$school, $student, $uid] = ruleSetup();
    $cola = ruleProduct($school, 'Cola', 100);

    $ban = app(RuleService::class)->banProduct($student, $cola);
    app(RuleService::class)->unban($ban);

    ruleBuy($school, $uid, $cola, 1, 'a');
    expect(Sale::count())->toBe(1);
});

// ------------------------------------------------------------ buying hours

it('only allows purchases inside the buying hours, in school time', function () {
    $settings = ['buying_hours' => ['days' => [1, 2, 3, 4, 5], 'windows' => [['11:00', '13:00']]]];
    [$school, , $uid] = ruleSetup($settings);
    $meal = ruleProduct($school, 'Meal', 100);

    $this->travelTo(ruleMonday('10:00'));
    $fail = ruleFail(fn () => ruleBuy($school, $uid, $meal, 1, 'a'));
    expect($fail['reason'])->toBe('outside_hours')
        ->and($fail['message'])->toContain('11:00');

    $this->travelTo(ruleMonday('12:00'));
    ruleBuy($school, $uid, $meal, 1, 'b');

    $this->travelTo(Carbon::parse('2026-10-17 12:00', 'Asia/Phnom_Penh'));   // Saturday
    expect(ruleFail(fn () => ruleBuy($school, $uid, $meal, 1, 'c'))['reason'])->toBe('outside_hours');

    expect(Sale::count())->toBe(1);
});

it('allows purchases at any time when no buying hours are set', function () {
    $this->travelTo(Carbon::parse('2026-10-17 03:00', 'Asia/Phnom_Penh'));
    [$school, , $uid] = ruleSetup();
    $meal = ruleProduct($school, 'Meal', 100);

    ruleBuy($school, $uid, $meal, 1, 'a');

    expect(Sale::count())->toBe(1);
});

// ------------------------------------------------------- through the till

it('shows the ban message at the till', function () {
    [$school, $student, $uid] = ruleSetup();
    $cola = ruleProduct($school, 'Cola', 100);
    app(RuleService::class)->banProduct($student, $cola);

    Role::findOrCreate('cashier', 'web');
    $cashier = User::factory()->create(['school_id' => $school->id]);
    $cashier->assignRole('cashier');
    $this->actingAs($cashier);

    $this->postJson('/till/checkout', [
        'uid' => $uid,
        'key' => 'k1',
        'items' => [['product_id' => $cola->id, 'quantity' => 1]],
    ])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'banned_product');
});
