<?php

use App\Exceptions\CardException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\SaleException;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Sale;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\CardService;
use App\Services\LedgerService;
use App\Services\SaleService;
use Spatie\Permission\Models\Role;

/** @return array{0: School, 1: Student, 2: string} */
function saleSetup(int $balance = 10000, string $schoolCode = 'SALE'): array
{
    static $n = 0;
    $n++;

    $school = School::firstOrCreate(['code' => $schoolCode], ['name' => "School {$schoolCode}"]);
    $student = Student::create([
        'school_id' => $school->id,
        'student_code' => "P{$n}",
        'name' => "Pupil {$n}",
    ]);

    $uid = strtoupper(dechex(0x10000000 + $n));
    app(CardService::class)->bind($student, $uid);

    if ($balance > 0) {
        app(LedgerService::class)->topUp($student->account, $balance, "seed-{$n}");
    }

    return [$school, $student, $uid];
}

function saleProduct(School $school, string $name, int $price, bool $active = true): Product
{
    return Product::create([
        'school_id' => $school->id,
        'name' => $name,
        'price' => $price,
        'is_active' => $active,
    ]);
}

function saleReasonOf(callable $fn): ?string
{
    try {
        $fn();
    } catch (SaleException $e) {
        return $e->reason;
    }

    return null;
}

function tillUser(School $school, string $role = 'cashier'): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user;
}

// ------------------------------------------------------------- SaleService

it('charges the database price for each item and records the sale', function () {
    [$school, $student, $uid] = saleSetup(10000);
    $cola = saleProduct($school, 'Cola', 350);
    $rice = saleProduct($school, 'Fried rice', 500);

    $sale = app(SaleService::class)->checkout($school->id, null, $uid, [
        ['product_id' => $cola->id, 'quantity' => 2],
        ['product_id' => $rice->id, 'quantity' => 1],
    ], 'key-1');

    expect($sale->total)->toBe(1200)
        ->and($sale->items)->toHaveCount(2)
        ->and($sale->ledgerEntry->balance_after)->toBe(8800)
        ->and($student->account->refresh()->balance)->toBe(8800)
        ->and(app(LedgerService::class)->isConsistent($student->account))->toBeTrue();
});

it('ignores any price sent by the client', function () {
    [$school, , $uid] = saleSetup(10000);
    $cola = saleProduct($school, 'Cola', 350);

    $sale = app(SaleService::class)->checkout($school->id, null, $uid, [
        ['product_id' => $cola->id, 'quantity' => 1, 'price' => 1, 'unit_price' => 1],
    ], 'key-1');

    expect($sale->total)->toBe(350);
});

it('does not charge twice when the same sale is retried', function () {
    [$school, $student, $uid] = saleSetup(10000);
    $cola = saleProduct($school, 'Cola', 350);
    $items = [['product_id' => $cola->id, 'quantity' => 1]];

    $first = app(SaleService::class)->checkout($school->id, null, $uid, $items, 'same-key');
    $retry = app(SaleService::class)->checkout($school->id, null, $uid, $items, 'same-key');

    expect($retry->id)->toBe($first->id)
        ->and(Sale::count())->toBe(1)
        ->and($student->account->refresh()->balance)->toBe(9650)
        ->and(LedgerEntry::where('account_id', $student->account->id)->count())->toBe(2);
});

it('refuses to reuse a sale idempotency key for a different cart', function () {
    [$school, $student, $uid] = saleSetup(10000);
    $cola = saleProduct($school, 'Cola', 350);
    $water = saleProduct($school, 'Water', 100);

    $first = app(SaleService::class)->checkout($school->id, null, $uid, [
        ['product_id' => $cola->id, 'quantity' => 1],
    ], 'different-cart');

    expect(fn () => app(SaleService::class)->checkout($school->id, null, $uid, [
        ['product_id' => $water->id, 'quantity' => 1],
    ], 'different-cart'))->toThrow(InvalidArgumentException::class)
        ->and(Sale::count())->toBe(1)
        ->and($first->total)->toBe(350)
        ->and($student->account->refresh()->balance)->toBe(9650);
});

it('records nothing when the balance is too low', function () {
    [$school, $student, $uid] = saleSetup(300);
    $rice = saleProduct($school, 'Fried rice', 500);

    expect(fn () => app(SaleService::class)->checkout($school->id, null, $uid, [
        ['product_id' => $rice->id, 'quantity' => 1],
    ], 'key-1'))->toThrow(InsufficientBalanceException::class);

    expect(Sale::count())->toBe(0)
        ->and($student->account->refresh()->balance)->toBe(300);
});

it('refuses a blocked card', function () {
    [$school, $student, $uid] = saleSetup(10000);
    $cola = saleProduct($school, 'Cola', 350);
    app(CardService::class)->block(app(CardService::class)->currentCard($student));

    expect(fn () => app(SaleService::class)->checkout($school->id, null, $uid, [
        ['product_id' => $cola->id, 'quantity' => 1],
    ], 'key-1'))->toThrow(CardException::class);

    expect(Sale::count())->toBe(0);
});

it('refuses an empty cart', function () {
    [$school, , $uid] = saleSetup(10000);

    expect(saleReasonOf(fn () => app(SaleService::class)->checkout($school->id, null, $uid, [], 'key-1')))
        ->toBe('empty_cart');
});

it('refuses a hidden product', function () {
    [$school, $student, $uid] = saleSetup(10000);
    $hidden = saleProduct($school, 'Old stock', 100, active: false);

    expect(saleReasonOf(fn () => app(SaleService::class)->checkout($school->id, null, $uid, [
        ['product_id' => $hidden->id, 'quantity' => 1],
    ], 'key-1')))->toBe('invalid_product');

    expect($student->account->refresh()->balance)->toBe(10000);
});

it("refuses another school's product", function () {
    [$school, , $uid] = saleSetup(10000);
    $otherSchool = School::create(['name' => 'Other', 'code' => 'OTHER']);
    $foreign = saleProduct($otherSchool, 'Foreign cola', 100);

    expect(saleReasonOf(fn () => app(SaleService::class)->checkout($school->id, null, $uid, [
        ['product_id' => $foreign->id, 'quantity' => 1],
    ], 'key-1')))->toBe('invalid_product');
});

// ---------------------------------------------------------- till endpoint

it('charges a sale through the till endpoint', function () {
    [$school, $student, $uid] = saleSetup(10000);
    $cola = saleProduct($school, 'Cola', 350);
    $this->actingAs(tillUser($school));

    $this->postJson('/till/checkout', [
        'uid' => $uid,
        'key' => 'k1',
        'items' => [['product_id' => $cola->id, 'quantity' => 2]],
    ])
        ->assertOk()
        ->assertJsonPath('total', 700)
        ->assertJsonPath('balance', 9300)
        ->assertJsonPath('balance_formatted', '$93.00')
        ->assertJsonPath('student.name', $student->name);
});

it('shows a clear reason for an unknown card', function () {
    [$school] = saleSetup(10000);
    $cola = saleProduct($school, 'Cola', 350);
    $this->actingAs(tillUser($school));

    $this->postJson('/till/checkout', [
        'uid' => 'AABBCCDD',
        'key' => 'k1',
        'items' => [['product_id' => $cola->id, 'quantity' => 1]],
    ])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'unknown_card');
});

it('shows a clear reason for insufficient balance', function () {
    [$school, , $uid] = saleSetup(100);
    $rice = saleProduct($school, 'Fried rice', 500);
    $this->actingAs(tillUser($school));

    $this->postJson('/till/checkout', [
        'uid' => $uid,
        'key' => 'k1',
        'items' => [['product_id' => $rice->id, 'quantity' => 1]],
    ])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'insufficient_balance');
});

it('lists active products on the till page', function () {
    [$school] = saleSetup(0);
    saleProduct($school, 'Cola', 350);
    saleProduct($school, 'Secret item', 100, active: false);
    $this->actingAs(tillUser($school));

    $this->get('/till')->assertOk()->assertSee('Cola')->assertDontSee('Secret item');
});
