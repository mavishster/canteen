<?php

use App\Models\AuditLog;
use App\Models\School;
use App\Models\Student;
use App\Models\Topup;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\Payments\FakeGateway;
use App\Services\TopUpService;
use Carbon\Carbon;
use Spatie\Permission\Models\Role;

function wrUser(School $school, string $role = 'manager'): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole($role);

    return $user;
}

function wrSchool(array $settings = []): School
{
    static $n = 0;
    $n++;

    return School::create(['name' => "Wallet School {$n}", 'code' => "WR{$n}", 'settings' => $settings ?: null]);
}

function wrKid(School $school, string $code, ?int $grade, int $balance, bool $active = true): Student
{
    $student = Student::create(['school_id' => $school->id, 'student_code' => $code, 'name' => "Kid {$code}", 'grade' => $grade, 'is_active' => $active]);

    if ($balance > 0) {
        app(LedgerService::class)->topUp($student->account, $balance, "wr-seed-{$code}");
    }

    return $student;
}

/** A top-up started (and paid or failed) at a given UTC time. */
function wrTopup($test, Student $student, int $pay, string $currency, string $state, string $utc): Topup
{
    $test->travelTo(Carbon::parse($utc, 'UTC'));

    $fake = app(FakeGateway::class);
    $service = app(TopUpService::class);
    $topup = $service->start($student, $pay, $currency, $fake);

    if ($state !== 'pending') {
        $fake->simulate($topup, $state);
        $service->handleCallback($fake, ['ref' => $topup->gateway_ref, 'sig' => $fake->signature($topup->gateway_ref)]);
    }

    return $topup->refresh();
}

// -------------------------------------------------------------- top-ups report

it('totals the top-ups of a period per currency, status and school day', function () {
    $school = wrSchool(['topup' => ['khr_per_usd' => 4000]]);
    $kid = wrKid($school, 'T1', 3, 0);

    wrTopup($this, $kid, 500, 'USD', 'paid', '2026-10-12 03:00');       // 10:00 local, 12 Oct
    wrTopup($this, $kid, 41000, 'KHR', 'paid', '2026-10-12 18:30');     // 01:30 local, so 13 Oct
    wrTopup($this, $kid, 1000, 'USD', 'paid', '2026-10-13 02:00');      // 09:00 local, 13 Oct
    wrTopup($this, $kid, 300, 'USD', 'failed', '2026-10-13 03:00');
    wrTopup($this, $kid, 200, 'USD', 'pending', '2026-10-13 04:00');
    wrTopup($this, $kid, 700, 'USD', 'paid', '2026-09-01 03:00');       // outside the period

    $this->travelTo(Carbon::parse('2026-10-20 03:00', 'UTC'));

    $this->actingAs(wrUser($school))
        ->get('/manager/reports/topups?from=2026-10-12&to=2026-10-13')
        ->assertOk()
        ->assertSee('$25.25')                  // 5.00 + 10.25 + 10.00 credited
        ->assertSee('41,000')                  // paid in riel
        ->assertSee('2026-10-12')
        ->assertSee('2026-10-13')
        ->assertSee('$20.25')                  // the 13th: 10.25 + 10.00 (the 01:30 payment belongs to the local day)
        ->assertDontSee('$7.00');              // September's top-up is outside the period
});

it('filters the list by status and rejects bad input', function () {
    $school = wrSchool();
    $kid = wrKid($school, 'T1', 3, 0);
    $paid = wrTopup($this, $kid, 500, 'USD', 'paid', '2026-10-12 03:00');
    $failed = wrTopup($this, $kid, 300, 'USD', 'failed', '2026-10-12 04:00');
    $this->travelTo(Carbon::parse('2026-10-12 08:00', 'UTC'));
    $this->actingAs(wrUser($school));

    $this->get('/manager/reports/topups?from=2026-10-12&to=2026-10-12&status=failed')
        ->assertOk()->assertSee($failed->gateway_ref)->assertDontSee($paid->gateway_ref);

    $this->get('/manager/reports/topups?status=made-up')->assertSessionHasErrors('status');
    $this->get('/manager/reports/topups?from=2026-10-12&to=2026-10-01')->assertSessionHasErrors('from');
    $this->get('/manager/reports/topups?from=2020-01-01&to=2026-10-01')->assertSessionHasErrors('from');
    $this->get('/manager/reports/topups?from=nonsense')->assertSessionHasErrors('from');
});

it('exports the top-ups as CSV without names, and writes the export to the audit log', function () {
    $school = wrSchool();
    $kid = wrKid($school, 'T1', 3, 0);
    $paid = wrTopup($this, $kid, 500, 'USD', 'paid', '2026-10-12 03:00');
    $this->travelTo(Carbon::parse('2026-10-12 08:00', 'UTC'));
    $this->actingAs(wrUser($school));

    $csv = $this->get('/manager/reports/topups.csv?from=2026-10-12&to=2026-10-12')->assertOk()->streamedContent();

    expect($csv)->toContain('gateway_ref')->toContain($paid->gateway_ref)->toContain('5.00')->toContain('T1')
        ->and($csv)->not->toContain('Kid T1')
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'report.exported')->count())->toBe(1);
});

it('keeps one school\'s top-ups out of another school\'s report', function () {
    $mine = wrSchool();
    $theirs = wrSchool();
    wrTopup($this, wrKid($theirs, 'X1', 3, 0), 900, 'USD', 'paid', '2026-10-12 03:00');
    $this->travelTo(Carbon::parse('2026-10-12 08:00', 'UTC'));

    $this->actingAs(wrUser($mine))
        ->get('/manager/reports/topups?from=2026-10-12&to=2026-10-12')
        ->assertOk()
        ->assertDontSee('$9.00');
});

// --------------------------------------------------------------- balances report

it('totals what is held in the wallets, by grade, with leavers apart', function () {
    $school = wrSchool();
    wrKid($school, 'A1', 1, 1000);
    wrKid($school, 'A2', 1, 500);
    wrKid($school, 'B1', 5, 2500);
    wrKid($school, 'N1', null, 0);
    wrKid($school, 'L1', 5, 300, active: false);

    $other = wrSchool();
    wrKid($other, 'Z1', 1, 77700);

    $this->actingAs(wrUser($school))
        ->get('/manager/reports/balances')
        ->assertOk()
        ->assertSee('$43.00')          // 10 + 5 + 25 + 0 + 3 held now
        ->assertSee('$40.00')          // active students only
        ->assertSee('Grade 1')
        ->assertSee('$15.00')          // grade 1: 10 + 5
        ->assertSee('Grade 5')
        ->assertSee('No grade')
        ->assertSee('1 empty')
        ->assertSee('1 wallets: money to settle')
        ->assertDontSee('$777.00');    // another school
});

it('counts active students below the low-balance line', function () {
    $school = wrSchool();                  // default line: $2.00
    wrKid($school, 'LOW1', 3, 0);
    wrKid($school, 'LOW2', 3, 150);
    wrKid($school, 'OK1', 3, 5000);
    wrKid($school, 'GONE', 3, 10, active: false);   // inactive: not counted

    $this->actingAs(wrUser($school))
        ->get('/manager/reports/balances')
        ->assertOk()
        ->assertSee('2 active student(s) are below the school\'s low-balance line of $2.00');
});

it('shows how the total moved over a period, and it adds up', function () {
    $school = wrSchool();
    $kid = Student::create(['school_id' => $school->id, 'student_code' => 'RF1', 'name' => 'Roll Forward', 'grade' => 3]);
    $ledger = app(LedgerService::class);

    $this->travelTo(Carbon::parse('2026-09-30 03:00', 'UTC'));
    $ledger->topUp($kid->account, 1000, 'rf-1');                 // before the period: opening $10.00

    $this->travelTo(Carbon::parse('2026-10-05 03:00', 'UTC'));
    $ledger->topUp($kid->account, 500, 'rf-2');                  // + $5.00
    $ledger->purchase($kid->account, 200, 'rf-3');               // - $2.00
    $ledger->refund($kid->account, 50, 'rf-4');                  // + $0.50

    $this->travelTo(Carbon::parse('2026-10-20 03:00', 'UTC'));
    $ledger->purchase($kid->account, 100, 'rf-5');               // after the period

    $this->actingAs(wrUser($school))
        ->get('/manager/reports/balances?from=2026-10-01&to=2026-10-10')
        ->assertOk()
        ->assertSee('$10.00')      // held at the start
        ->assertSee('$5.00')       // top-ups
        ->assertSee('-$2.00')      // purchases (money left)
        ->assertSee('$0.50')       // refunds
        ->assertSee('$13.50')      // held at the end: 10 + 5 - 2 + 0.50
        ->assertSee('$12.50');     // held now, after the later $1.00 purchase
});

it('shows a school with no wallets without breaking', function () {
    $school = wrSchool();

    $this->actingAs(wrUser($school))->get('/manager/reports/balances')->assertOk()->assertSee('No wallets yet');
});

it('exports the balances per student without names and writes the export to the audit log', function () {
    $school = wrSchool();
    wrKid($school, 'B1', 5, 2500);
    wrKid($school, 'L1', 2, 300, active: false);
    $this->actingAs(wrUser($school));

    $csv = $this->get('/manager/reports/balances.csv')->assertOk()->streamedContent();

    expect($csv)->toContain('student_code,grade,active,currency,balance')
        ->toContain('B1,5,yes,USD,25.00')
        ->toContain('L1,2,no,USD,3.00')
        ->not->toContain('Kid B1')
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'report.exported')->count())->toBe(1);
});

// ------------------------------------------------------------------- access

it('keeps cashiers out of the money reports and lets managers in', function () {
    $school = wrSchool();

    foreach (['/manager/reports/topups', '/manager/reports/balances', '/manager/reports/topups.csv', '/manager/reports/balances.csv'] as $url) {
        $this->actingAs(wrUser($school, 'cashier'))->get($url)->assertForbidden();
        $this->actingAs(wrUser($school, 'manager'))->get($url)->assertOk();
    }
});

it('links the new reports from the manager menu', function () {
    $school = wrSchool();

    $this->actingAs(wrUser($school))->get('/manager/reports/daily')->assertOk()->assertSee('Top-ups')->assertSee('Balances');
});
