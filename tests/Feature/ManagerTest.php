<?php

use App\Exceptions\RefundException;
use App\Exceptions\SaleException;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleRefund;
use App\Models\School;
use App\Models\Student;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CardService;
use App\Services\LedgerService;
use App\Services\Payments\FakeGateway;
use App\Services\RefundService;
use App\Services\SaleService;
use App\Services\TopUpService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/** @return array{0: School, 1: Student, 2: string} */
function mgrSetup(int $balance = 10000, array $settings = []): array
{
    static $n = 0;
    $n++;

    $school = School::create(['name' => "Mgr School {$n}", 'code' => "MGR{$n}", 'settings' => $settings ?: null]);
    $student = Student::create([
        'school_id' => $school->id,
        'student_code' => "MG{$n}",
        'name' => "Mgr Kid {$n}",
        'grade' => 4,
    ]);

    $uid = strtoupper(dechex(0x50000000 + $n));
    app(CardService::class)->bind($student, $uid);
    app(LedgerService::class)->topUp($student->account, $balance, "mgr-seed-{$n}");

    return [$school, $student, $uid];
}

function mgrProduct(School $school, string $name, int $price, ?int $stock = null): Product
{
    return Product::create([
        'school_id' => $school->id,
        'name' => $name,
        'price' => $price,
        'track_stock' => $stock !== null,
        'stock' => $stock ?? 0,
    ]);
}

function mgrSell(School $school, string $uid, Product $product, int $qty, string $key): Sale
{
    return app(SaleService::class)->checkout($school->id, null, $uid, [
        ['product_id' => $product->id, 'quantity' => $qty],
    ], $key);
}

function mgrUser(School $school, string $role): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user;
}

function mgrReasonOf(callable $fn): ?string
{
    try {
        $fn();
    } catch (RefundException $e) {
        return $e->reason;
    }

    return null;
}

function mgrMonday(): Carbon
{
    return Carbon::parse('2026-10-12 10:00', 'Asia/Phnom_Penh');
}

// ----------------------------------------------------------------- refunds

it('returns the money and the stock when a manager approves a refund', function () {
    [$school, $student, $uid] = mgrSetup(10000);
    $cola = mgrProduct($school, 'Cola', 350, stock: 10);
    $sale = mgrSell($school, $uid, $cola, 2, 'k1');   // $7.00, stock 8, balance 9300
    $service = app(RefundService::class);

    $refund = $service->request($sale, mgrUser($school, 'cashier'), 'Wrong item');

    expect($refund->status)->toBe('pending')
        ->and($student->account->refresh()->balance)->toBe(9300);   // nothing moves yet

    $manager = mgrUser($school, 'manager');
    $done = $service->approve($refund, $manager, 'OK');

    expect($done->status)->toBe('approved')
        ->and($done->decided_by)->toBe($manager->id)
        ->and($student->account->refresh()->balance)->toBe(10000)
        ->and($cola->refresh()->stock)->toBe(10)
        ->and($sale->refresh()->voided_at)->not->toBeNull()
        ->and(StockMovement::where('type', 'return')->count())->toBe(1)
        ->and(LedgerEntry::where('account_id', $student->account->id)->count())->toBe(3)         // top-up, purchase, refund
        ->and(LedgerEntry::where('reference', "sale:{$sale->id}")->first()->amount)->toBe(700)   // the refund entry
        ->and(app(LedgerService::class)->isConsistent($student->account))->toBeTrue();
});

it('never refunds the same sale twice', function () {
    [$school, $student, $uid] = mgrSetup(10000);
    $cola = mgrProduct($school, 'Cola', 350);
    $sale = mgrSell($school, $uid, $cola, 2, 'k1');
    $service = app(RefundService::class);
    $manager = mgrUser($school, 'manager');

    $refund = $service->request($sale, $manager, 'Mistake');
    $service->approve($refund, $manager);

    expect(mgrReasonOf(fn() => $service->approve($refund, $manager)))->toBe('already_decided')
        ->and(mgrReasonOf(fn() => $service->request($sale, $manager, 'Again')))->toBe('already_refunded')
        ->and($student->account->refresh()->balance)->toBe(10000);
});

it('refuses a second request while one is waiting', function () {
    [$school, , $uid] = mgrSetup();
    $sale = mgrSell($school, $uid, mgrProduct($school, 'Cola', 350), 1, 'k1');
    $cashier = mgrUser($school, 'cashier');
    $service = app(RefundService::class);

    $service->request($sale, $cashier, 'First');

    expect(mgrReasonOf(fn() => $service->request($sale, $cashier, 'Second')))->toBe('refund_pending');
});

it('keeps money and stock when a refund is rejected, and allows a new request', function () {
    [$school, $student, $uid] = mgrSetup(10000);
    $cola = mgrProduct($school, 'Cola', 350, stock: 10);
    $sale = mgrSell($school, $uid, $cola, 2, 'k1');
    $service = app(RefundService::class);
    $manager = mgrUser($school, 'manager');

    $refund = $service->request($sale, mgrUser($school, 'cashier'), 'Changed mind');
    $service->reject($refund, $manager, 'Not valid');

    expect($refund->refresh()->status)->toBe('rejected')
        ->and($sale->refresh()->voided_at)->toBeNull()
        ->and($student->account->refresh()->balance)->toBe(9300)
        ->and($cola->refresh()->stock)->toBe(8);

    expect($service->request($sale, $manager, 'Second try')->status)->toBe('pending');
});

it('does not touch the stock of products that are not tracked', function () {
    [$school, , $uid] = mgrSetup();
    $meal = mgrProduct($school, 'Fried rice', 500);
    $sale = mgrSell($school, $uid, $meal, 1, 'k1');
    $manager = mgrUser($school, 'manager');

    app(RefundService::class)->refundNow($sale, $manager, 'Cold food');

    expect(StockMovement::count())->toBe(0)
        ->and($sale->refresh()->voided_at)->not->toBeNull();
});

it('does not count a refunded sale against the spending limit', function () {
    [$school, , $uid] = mgrSetup(10000, ['limits' => ['daily' => [[1, 12, 1000]]]]);
    $meal = mgrProduct($school, 'Meal', 1000);

    $sale = mgrSell($school, $uid, $meal, 1, 'k1');

    expect(fn() => mgrSell($school, $uid, $meal, 1, 'k2'))->toThrow(SaleException::class);

    app(RefundService::class)->refundNow($sale, mgrUser($school, 'manager'), 'Mistake');

    mgrSell($school, $uid, $meal, 1, 'k3');   // allowed again: no exception

    expect(Sale::whereNull('voided_at')->count())->toBe(1);
});

// ------------------------------------------------------------- refund screens

it('lets a cashier request and a manager approve through the web pages', function () {
    [$school, $student, $uid] = mgrSetup(10000);
    $sale = mgrSell($school, $uid, mgrProduct($school, 'Cola', 350), 2, 'k1');

    $this->actingAs(mgrUser($school, 'cashier'));
    $this->post("/till/sales/{$sale->id}/refund", ['reason' => 'Wrong item'])->assertRedirect();

    $refund = SaleRefund::first();
    expect($refund->status)->toBe('pending')
        ->and($student->account->refresh()->balance)->toBe(9300);

    $this->post("/manager/refunds/{$refund->id}/approve")->assertForbidden();   // a cashier cannot approve

    $this->actingAs(mgrUser($school, 'manager'));
    $this->get('/manager/refunds')->assertOk()->assertSee('Wrong item');
    $this->post("/manager/refunds/{$refund->id}/approve", ['note' => 'Fine'])->assertRedirect();

    expect($refund->refresh()->status)->toBe('approved')
        ->and($student->account->refresh()->balance)->toBe(10000);
});

it('lets a manager refund a sale directly from the sales list', function () {
    [$school, $student, $uid] = mgrSetup(10000);
    $sale = mgrSell($school, $uid, mgrProduct($school, 'Cola', 350), 2, 'k1');
    $this->actingAs(mgrUser($school, 'manager'));

    $this->post("/till/sales/{$sale->id}/refund", ['reason' => 'Cashier error'])->assertRedirect();

    expect($sale->refresh()->voided_at)->not->toBeNull()
        ->and($student->account->refresh()->balance)->toBe(10000)
        ->and(SaleRefund::first()->status)->toBe('approved');
});

it('requires a reason for a refund', function () {
    [$school, , $uid] = mgrSetup();
    $sale = mgrSell($school, $uid, mgrProduct($school, 'Cola', 350), 1, 'k1');
    $this->actingAs(mgrUser($school, 'cashier'));

    $this->post("/till/sales/{$sale->id}/refund", ['reason' => ''])->assertSessionHasErrors('reason');

    expect(SaleRefund::count())->toBe(0);
});

it("cannot refund another school's sale", function () {
    [$school, , $uid] = mgrSetup();
    $sale = mgrSell($school, $uid, mgrProduct($school, 'Cola', 350), 1, 'k1');
    [$otherSchool] = mgrSetup();

    $this->actingAs(mgrUser($otherSchool, 'manager'));

    $this->post("/till/sales/{$sale->id}/refund", ['reason' => 'Sneaky'])->assertNotFound();

    expect($sale->refresh()->voided_at)->toBeNull();
});

it('shows today\'s sales and a pending refund on the sales list', function () {
    [$school, , $uid] = mgrSetup();
    $sale = mgrSell($school, $uid, mgrProduct($school, 'Cola', 350), 1, 'k1');
    $cashier = mgrUser($school, 'cashier');
    app(RefundService::class)->request($sale, $cashier, 'Oops');

    $this->actingAs($cashier)->get('/till/sales')->assertOk()->assertSee('Cola')->assertSee('Refund pending');
});

it('keeps cashiers out of the manager pages', function () {
    [$school] = mgrSetup();
    $this->actingAs(mgrUser($school, 'cashier'));

    $this->get('/manager/reports/daily')->assertForbidden();
    $this->get('/manager/reports/reconciliation')->assertForbidden();
    $this->get('/manager/refunds')->assertForbidden();
});

// -------------------------------------------------------------- daily report

it('totals the day\'s sales and leaves refunded sales out', function () {
    $this->travelTo(mgrMonday());
    [$school, , $uid] = mgrSetup(100000);
    $cola = mgrProduct($school, 'Cola', 350);
    $rice = mgrProduct($school, 'Fried rice', 500);

    $first = mgrSell($school, $uid, $cola, 2, 'k1');    // $7.00
    mgrSell($school, $uid, $rice, 1, 'k2');             // $5.00

    $this->actingAs(mgrUser($school, 'manager'));
    $this->get('/manager/reports/daily')->assertOk()->assertSee('$12.00')->assertSee('Cola')->assertSee('Fried rice');

    app(RefundService::class)->refundNow($first, mgrUser($school, 'manager'), 'Mistake');

    $this->get('/manager/reports/daily')->assertOk()->assertSee('$5.00')->assertDontSee('Cola')->assertSee('$7.00');
});

it('shows an empty day and rejects a bad date', function () {
    $this->travelTo(mgrMonday());
    [$school, , $uid] = mgrSetup();
    mgrSell($school, $uid, mgrProduct($school, 'Cola', 350), 1, 'k1');
    $this->actingAs(mgrUser($school, 'manager'));

    $this->get('/manager/reports/daily?date=2026-10-11')->assertOk()->assertSee('No sales on this day.');
    $this->get('/manager/reports/daily?date=yesterday')->assertSessionHasErrors('date');
});

// ------------------------------------------------------------ reconciliation

it('shows wallet movements and paid top-ups, and the ledger check passes', function () {
    $this->travelTo(mgrMonday());
    [$school, $student, $uid] = mgrSetup(10000);
    mgrSell($school, $uid, mgrProduct($school, 'Cola', 350), 1, 'k1');

    $fake = app(FakeGateway::class);
    $topUps = app(TopUpService::class);
    $topup = $topUps->start($student, 500, 'USD', $fake);
    $fake->simulate($topup, 'paid');
    $topUps->handleCallback($fake, ['ref' => $topup->gateway_ref, 'sig' => $fake->signature($topup->gateway_ref)]);

    $this->actingAs(mgrUser($school, 'manager'));
    $this->get('/manager/reports/reconciliation')
        ->assertOk()
        ->assertSee('Every balance matches its ledger.')
        ->assertSee('Paid top-ups')
        ->assertSee('$5.00');
});

it('raises the alarm when a balance does not match its ledger', function () {
    [$school, $student] = mgrSetup(10000);
    DB::table('accounts')->where('id', $student->account->id)->update(['balance' => 999]);

    $this->actingAs(mgrUser($school, 'manager'));
    $this->get('/manager/reports/reconciliation')->assertOk()->assertSee('do not match their ledger');
});

it('exports the paid top-ups of a day as CSV', function () {
    $this->travelTo(mgrMonday());
    [$school, $student] = mgrSetup();

    $fake = app(FakeGateway::class);
    $topUps = app(TopUpService::class);
    $topup = $topUps->start($student, 500, 'USD', $fake);
    $fake->simulate($topup, 'paid');
    $topUps->handleCallback($fake, ['ref' => $topup->gateway_ref, 'sig' => $fake->signature($topup->gateway_ref)]);

    $this->actingAs(mgrUser($school, 'manager'));
    $response = $this->get('/manager/reports/reconciliation/topups.csv?date=2026-10-12');

    $response->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toContain($topup->gateway_ref)
        ->and($csv)->toContain('5.00')
        ->and($csv)->toContain('gateway_ref');
});

it('redirects guests away from the manager pages', function () {
    $this->get('/manager/reports/daily')->assertRedirect('/login');
    $this->get('/till/sales')->assertRedirect('/login');
});
