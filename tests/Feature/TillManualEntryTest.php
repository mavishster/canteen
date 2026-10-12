<?php

use App\Exceptions\CardException;
use App\Models\Product;
use App\Models\Sale;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\CardService;
use App\Services\LedgerService;
use App\Services\RuleService;
use App\Services\SaleService;
use Spatie\Permission\Models\Role;

/** @return array{0: School, 1: Student, 2: string|null, 3: User} school, student, card number, cashier */
function manSetup(int $balance = 10000, bool $withCard = true, array $settings = []): array
{
    static $n = 0;
    $n++;

    $school = School::create(['name' => "Manual School {$n}", 'code' => "MAN{$n}", 'settings' => $settings ?: null]);
    $student = Student::create([
        'school_id' => $school->id,
        'student_code' => (string) (100000 + $n),   // like a SIS user id: 6 digits
        'name' => "Manual Kid {$n}",
        'grade' => 4,
    ]);

    $uid = null;
    if ($withCard) {
        $uid = strtoupper(dechex(0x70000000 + $n));
        app(CardService::class)->bind($student, $uid);
    }

    if ($balance > 0) {
        app(LedgerService::class)->topUp($student->account, $balance, "man-seed-{$n}");
    }

    Role::findOrCreate('cashier', 'web');
    $cashier = User::factory()->create(['school_id' => $school->id]);
    $cashier->assignRole('cashier');

    return [$school, $student, $uid, $cashier];
}

function manProduct(School $school, string $name = 'Cola', int $price = 350, ?int $stock = null): Product
{
    return Product::create([
        'school_id' => $school->id,
        'name' => $name,
        'price' => $price,
        'track_stock' => $stock !== null,
        'stock' => $stock ?? 0,
    ]);
}

function manPay($test, string $input, Product $product, string $key, ?string $confirm = null)
{
    return $test->postJson('/till/checkout', [
        'uid' => $input,
        'key' => $key,
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
        'confirm_student' => $confirm,
    ]);
}

// ----------------------------------------------------------- the two-step flow

it('asks the cashier to confirm a student number before charging anything', function () {
    [$school, $student, , $cashier] = manSetup();
    $cola = manProduct($school);
    $this->actingAs($cashier);

    manPay($this, $student->student_code, $cola, 'k1')
        ->assertOk()
        ->assertJsonPath('confirm', true)
        ->assertJsonPath('student.name', $student->name)
        ->assertJsonPath('student.code', $student->student_code)
        ->assertJsonPath('student.grade', 4);

    expect(Sale::count())->toBe(0)
        ->and($student->account->refresh()->balance)->toBe(10000);
});

it('sells once the cashier has confirmed, and records that it was manual', function () {
    [$school, $student, , $cashier] = manSetup();
    $cola = manProduct($school);
    $this->actingAs($cashier);

    manPay($this, $student->student_code, $cola, 'k1', confirm: $student->student_code)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('identified_by', 'manual')
        ->assertJsonPath('balance', 9300);

    expect(Sale::first()->identified_by)->toBe('manual');
});

it('asks again when the confirmation is for a different student number', function () {
    [$school, $student, , $cashier] = manSetup();
    $this->actingAs($cashier);

    manPay($this, $student->student_code, manProduct($school), 'k1', confirm: '999999')
        ->assertOk()
        ->assertJsonPath('confirm', true);

    expect(Sale::count())->toBe(0);
});

it('does not charge twice when the confirmed sale is sent again', function () {
    [$school, $student, , $cashier] = manSetup();
    $cola = manProduct($school);
    $this->actingAs($cashier);

    $first = manPay($this, $student->student_code, $cola, 'k1', confirm: $student->student_code)->assertOk()->json('sale_id');
    $again = manPay($this, $student->student_code, $cola, 'k1', confirm: $student->student_code)->assertOk()->json('sale_id');

    expect($again)->toBe($first)
        ->and(Sale::count())->toBe(1)
        ->and($student->account->refresh()->balance)->toBe(9300);
});

// ------------------------------------------------------------- the card still works

it('sells by card tap straight away, with no confirmation', function () {
    [$school, $student, $uid, $cashier] = manSetup();
    $this->actingAs($cashier);

    manPay($this, $uid, manProduct($school), 'k1')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('identified_by', 'card')
        ->assertJsonMissingPath('confirm');

    expect($student->account->refresh()->balance)->toBe(9300);
});

it('still answers "card not recognised" for an unknown card-shaped number', function () {
    [$school, , , $cashier] = manSetup();
    $this->actingAs($cashier);

    manPay($this, 'AABBCCDD', manProduct($school), 'k1')
        ->assertStatus(422)
        ->assertJsonPath('reason', 'unknown_card');
});

it('says it cannot find an unknown student number', function () {
    [$school, , , $cashier] = manSetup();
    $this->actingAs($cashier);

    manPay($this, '123456', manProduct($school), 'k1')
        ->assertStatus(422)
        ->assertJsonPath('reason', 'unknown_identifier');
});

// ------------------------------------------------------------------- the checks

it('refuses a student number when the parent has blocked the card', function () {
    [$school, $student, , $cashier] = manSetup();
    app(CardService::class)->block(app(CardService::class)->currentCard($student));
    $this->actingAs($cashier);

    manPay($this, $student->student_code, manProduct($school), 'k1')
        ->assertStatus(422)
        ->assertJsonPath('reason', 'card_blocked');
});

it('refuses an inactive student', function () {
    [$school, $student, , $cashier] = manSetup();
    $student->update(['is_active' => false]);
    $this->actingAs($cashier);

    manPay($this, $student->student_code, manProduct($school), 'k1')
        ->assertStatus(422)
        ->assertJsonPath('reason', 'student_inactive');
});

it('lets a student without a card buy by number', function () {
    [$school, $student, , $cashier] = manSetup(withCard: false);
    $this->actingAs($cashier);

    manPay($this, $student->student_code, manProduct($school), 'k1', confirm: $student->student_code)
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(app(CardService::class)->currentCard($student))->toBeNull();
});

it('can be switched off for a school, while cards keep working', function () {
    [$school, $student, $uid, $cashier] = manSetup(settings: ['till' => ['manual_entry' => false]]);
    $cola = manProduct($school);
    $this->actingAs($cashier);

    manPay($this, $student->student_code, $cola, 'k1')
        ->assertStatus(422)
        ->assertJsonPath('reason', 'manual_disabled');

    manPay($this, $uid, $cola, 'k2')->assertOk()->assertJsonPath('ok', true);
});

it('never finds a student of another school', function () {
    [, $otherStudent] = manSetup();
    [$school, , , $cashier] = manSetup();
    $this->actingAs($cashier);

    manPay($this, $otherStudent->student_code, manProduct($school), 'k1')
        ->assertStatus(422)
        ->assertJsonPath('reason', 'unknown_identifier');
});

it('refuses a cross-school sale even when called directly', function () {
    [, $otherStudent] = manSetup();
    [$school] = manSetup();
    $cola = manProduct($school);

    expect(fn () => app(SaleService::class)->checkoutForStudent($school->id, null, $otherStudent, [
        ['product_id' => $cola->id, 'quantity' => 1],
    ], 'k1'))->toThrow(CardException::class);
});

// --------------------------------------------------------- the same rules as a tap

it('applies the student\'s bans to a manual sale', function () {
    [$school, $student, , $cashier] = manSetup();
    $cola = manProduct($school);
    app(RuleService::class)->banProduct($student, $cola);
    $this->actingAs($cashier);

    manPay($this, $student->student_code, $cola, 'k1', confirm: $student->student_code)
        ->assertStatus(422)
        ->assertJsonPath('reason', 'banned_product');

    expect(Sale::count())->toBe(0);
});

it('applies spending limits to a manual sale', function () {
    [$school, $student, , $cashier] = manSetup(10000, true, ['limits' => ['daily' => [[1, 12, 500]]]]);
    $cola = manProduct($school, 'Cola', 350);
    $this->actingAs($cashier);

    // two colas = 700, above the 500 daily cap
    manPay($this, $student->student_code, $cola, 'k1', confirm: $student->student_code)
        ->assertStatus(422)
        ->assertJsonPath('reason', 'over_daily_limit');
});

it('takes stock off for a manual sale just like for a tap', function () {
    [$school, $student, , $cashier] = manSetup();
    $cola = manProduct($school, 'Cola', 350, stock: 10);
    $this->actingAs($cashier);

    manPay($this, $student->student_code, $cola, 'k1', confirm: $student->student_code)->assertOk();

    expect($cola->refresh()->stock)->toBe(8);
});

// -------------------------------------------------------------- the sales list

it('marks manual sales in the sales list', function () {
    [$school, $student, $uid, $cashier] = manSetup();
    $cola = manProduct($school);
    $this->actingAs($cashier);

    manPay($this, $student->student_code, $cola, 'k1', confirm: $student->student_code)->assertOk();

    $this->get('/till/sales')->assertOk()->assertSee('manual');
});
