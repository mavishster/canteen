<?php

use App\Exceptions\TopUpException;
use App\Models\LedgerEntry;
use App\Models\School;
use App\Models\Student;
use App\Models\Topup;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\InvalidCallbackException;
use App\Services\TopUpService;
use Spatie\Permission\Models\Role;

/** @return array{0: School, 1: Student} */
function topupSetup(string $currency = 'USD', array $settings = []): array
{
    static $n = 0;
    $n++;

    $school = School::create([
        'name' => "Top-up School {$n}",
        'code' => "TU{$n}",
        'currency' => $currency,
        'settings' => $settings ?: null,
    ]);

    $student = Student::create([
        'school_id' => $school->id,
        'student_code' => "T{$n}",
        'name' => "Payer {$n}",
    ]);

    return [$school, $student];
}

/** @return array{ref:string, sig:string} what a genuine callback from the fake gateway looks like */
function topupCallback(Topup $topup): array
{
    return [
        'ref' => $topup->gateway_ref,
        'sig' => app(FakeGateway::class)->signature($topup->gateway_ref),
    ];
}

function topupReasonOf(callable $fn): ?string
{
    try {
        $fn();
    } catch (TopUpException $e) {
        return $e->reason;
    }

    return null;
}

// ------------------------------------------------------------------- start

it('starts a pending top-up and credits nothing yet', function () {
    [, $student] = topupSetup();

    $topup = app(TopUpService::class)->start($student, 500, 'USD', app(FakeGateway::class));

    expect($topup->status)->toBe('pending')
        ->and($topup->amount)->toBe(500)
        ->and($topup->checkout_url)->toContain($topup->gateway_ref)
        ->and($student->account->refresh()->balance)->toBe(0);
});

it('rejects an amount below the minimum or not positive', function () {
    [, $student] = topupSetup();
    $service = app(TopUpService::class);
    $fake = app(FakeGateway::class);

    expect(topupReasonOf(fn () => $service->start($student, 50, 'USD', $fake)))->toBe('below_minimum')
        ->and(topupReasonOf(fn () => $service->start($student, 0, 'USD', $fake)))->toBe('invalid_amount')
        ->and(topupReasonOf(fn () => $service->start($student, 500, 'EUR', $fake)))->toBe('unsupported_currency')
        ->and(Topup::count())->toBe(0);
});

it('uses the minimum configured for the school', function () {
    [, $student] = topupSetup('USD', ['topup' => ['min' => 500]]);

    expect(topupReasonOf(fn () => app(TopUpService::class)->start($student, 400, 'USD', app(FakeGateway::class))))
        ->toBe('below_minimum');
});

// ----------------------------------------------------------------- crediting

it('credits the account once, even when the callback is repeated', function () {
    [, $student] = topupSetup();
    $service = app(TopUpService::class);
    $fake = app(FakeGateway::class);

    $topup = $service->start($student, 500, 'USD', $fake);
    $fake->simulate($topup, 'paid');

    $service->handleCallback($fake, topupCallback($topup));
    $service->handleCallback($fake, topupCallback($topup));   // the bank repeats itself
    $service->handleCallback($fake, topupCallback($topup));

    expect($student->account->refresh()->balance)->toBe(500)
        ->and(LedgerEntry::where('account_id', $student->account->id)->count())->toBe(1)
        ->and($topup->refresh()->status)->toBe('paid')
        ->and($topup->ledger_entry_id)->not->toBeNull()
        ->and(app(LedgerService::class)->isConsistent($student->account))->toBeTrue();
});

it('does not credit while the bank says the payment is still pending', function () {
    [, $student] = topupSetup();
    $service = app(TopUpService::class);
    $fake = app(FakeGateway::class);

    $topup = $service->start($student, 500, 'USD', $fake);
    $service->handleCallback($fake, topupCallback($topup));   // callback arrives, but the bank has nothing yet

    expect($topup->refresh()->status)->toBe('pending')
        ->and($student->account->refresh()->balance)->toBe(0);
});

it('does not credit a failed payment', function () {
    [, $student] = topupSetup();
    $service = app(TopUpService::class);
    $fake = app(FakeGateway::class);

    $topup = $service->start($student, 500, 'USD', $fake);
    $fake->simulate($topup, 'failed');
    $service->handleCallback($fake, topupCallback($topup));

    expect($topup->refresh()->status)->toBe('failed')
        ->and($student->account->refresh()->balance)->toBe(0);
});

it('rejects a callback with a bad signature and credits nothing', function () {
    [, $student] = topupSetup();
    $service = app(TopUpService::class);
    $fake = app(FakeGateway::class);

    $topup = $service->start($student, 500, 'USD', $fake);
    $fake->simulate($topup, 'paid');

    expect(fn () => $service->handleCallback($fake, ['ref' => $topup->gateway_ref, 'sig' => 'forged']))
        ->toThrow(InvalidCallbackException::class);

    expect($student->account->refresh()->balance)->toBe(0);
});

it('never trusts the amounts or status written in the callback body', function () {
    [, $student] = topupSetup();
    $service = app(TopUpService::class);
    $fake = app(FakeGateway::class);

    $topup = $service->start($student, 500, 'USD', $fake);
    $lying = topupCallback($topup) + ['status' => 'paid', 'amount' => 999999];

    $service->handleCallback($fake, $lying);   // the bank has not confirmed anything
    expect($student->account->refresh()->balance)->toBe(0);

    $fake->simulate($topup, 'paid');
    $service->handleCallback($fake, $lying);   // now it is paid, but only the real amount counts
    expect($student->account->refresh()->balance)->toBe(500);
});

it('sends a payment to review when the bank reports a different amount', function () {
    [, $student] = topupSetup();
    $service = app(TopUpService::class);
    $fake = app(FakeGateway::class);

    $topup = $service->start($student, 500, 'USD', $fake);
    $fake->simulate($topup, 'paid', amount: 100);
    $service->handleCallback($fake, topupCallback($topup));

    expect($topup->refresh()->status)->toBe('review')
        ->and($topup->failure_reason)->toBe('amount_mismatch')
        ->and($student->account->refresh()->balance)->toBe(0);
});

it('still credits a late payment on an expired top-up', function () {
    [, $student] = topupSetup();
    $service = app(TopUpService::class);
    $fake = app(FakeGateway::class);

    $topup = $service->start($student, 500, 'USD', $fake);
    $topup->update(['status' => 'expired']);
    $fake->simulate($topup, 'paid');
    $service->handleCallback($fake, topupCallback($topup));

    expect($topup->refresh()->status)->toBe('paid')
        ->and($student->account->refresh()->balance)->toBe(500);
});

// ---------------------------------------------------------------- currencies

it('converts a riel payment into dollars and records the original payment', function () {
    [, $student] = topupSetup('USD', ['topup' => ['khr_per_usd' => 4000]]);
    $service = app(TopUpService::class);
    $fake = app(FakeGateway::class);

    $topup = $service->start($student, 41000, 'KHR', $fake);   // 41,000 riel
    expect($topup->amount)->toBe(1025);                          // $10.25

    $fake->simulate($topup, 'paid');
    $service->handleCallback($fake, topupCallback($topup));

    $entry = LedgerEntry::where('account_id', $student->account->id)->first();

    expect($student->account->refresh()->balance)->toBe(1025)
        ->and($entry->source_currency)->toBe('KHR')
        ->and($entry->source_amount)->toBe(41000)
        ->and((float) $entry->exchange_rate)->toBe(4000.0);
});

it('converts a dollar payment into riel for a riel school', function () {
    [, $student] = topupSetup('KHR', ['topup' => ['khr_per_usd' => 4100]]);
    $service = app(TopUpService::class);
    $fake = app(FakeGateway::class);

    $topup = $service->start($student, 250, 'USD', $fake);   // $2.50
    $fake->simulate($topup, 'paid');
    $service->handleCallback($fake, topupCallback($topup));

    expect($topup->amount)->toBe(10250)
        ->and($student->account->refresh()->balance)->toBe(10250);
});

// ------------------------------------------------------- webhook and command

it('accepts a genuine callback over HTTP and credits the account', function () {
    [, $student] = topupSetup();
    $fake = app(FakeGateway::class);

    $topup = app(TopUpService::class)->start($student, 500, 'USD', $fake);
    $fake->simulate($topup, 'paid');

    $this->postJson('/api/webhooks/payments/fake', topupCallback($topup))->assertOk();
    $this->postJson('/api/webhooks/payments/fake', topupCallback($topup))->assertOk();   // repeated

    expect($student->account->refresh()->balance)->toBe(500);
});

it('rejects a forged callback over HTTP', function () {
    [, $student] = topupSetup();
    $fake = app(FakeGateway::class);

    $topup = app(TopUpService::class)->start($student, 500, 'USD', $fake);
    $fake->simulate($topup, 'paid');

    $this->postJson('/api/webhooks/payments/fake', ['ref' => $topup->gateway_ref, 'sig' => 'nope'])->assertStatus(400);

    expect($student->account->refresh()->balance)->toBe(0);
});

it('answers 404 for an unknown gateway or reference', function () {
    $this->postJson('/api/webhooks/payments/nobody', ['ref' => 'X'])->assertNotFound();

    $fake = app(FakeGateway::class);
    $this->postJson('/api/webhooks/payments/fake', ['ref' => 'TUNKNOWN', 'sig' => $fake->signature('TUNKNOWN')])->assertNotFound();
});

it('reconciles a paid top-up whose callback never arrived', function () {
    [, $student] = topupSetup();
    $fake = app(FakeGateway::class);

    $topup = app(TopUpService::class)->start($student, 500, 'USD', $fake);
    $fake->simulate($topup, 'paid');                                    // paid at the bank, but no callback reached us
    $topup->forceFill(['created_at' => now()->subMinutes(5)])->save();

    $this->artisan('topups:reconcile')->assertExitCode(0);

    expect($topup->refresh()->status)->toBe('paid')
        ->and($student->account->refresh()->balance)->toBe(500);
});

// -------------------------------------------------------------- admin screens

it('runs a full test top-up from the admin page', function () {
    [$school, $student] = topupSetup();
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $response = $this->post('/admin/topups', ['student_id' => $student->id, 'amount' => '5', 'currency' => 'USD']);

    $topup = Topup::first();
    $response->assertRedirect(route('dev.pay', ['ref' => $topup->gateway_ref]));

    $this->get(route('dev.pay', ['ref' => $topup->gateway_ref]))->assertOk()->assertSee($topup->gateway_ref);

    $this->post(route('dev.pay.complete', ['ref' => $topup->gateway_ref, 'outcome' => 'paid']))
        ->assertRedirect(route('admin.topups'));

    expect($student->account->refresh()->balance)->toBe(500);

    $this->get('/admin/topups')->assertOk()->assertSee($topup->gateway_ref);
});

it('keeps cashiers out of the top-ups page', function () {
    [$school] = topupSetup();
    Role::findOrCreate('cashier', 'web');
    $cashier = User::factory()->create(['school_id' => $school->id]);
    $cashier->assignRole('cashier');

    $this->actingAs($cashier)->get('/admin/topups')->assertForbidden();
});
