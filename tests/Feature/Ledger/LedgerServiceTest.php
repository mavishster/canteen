<?php

use App\Exceptions\InsufficientBalanceException;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\School;
use App\Models\Student;
use App\Services\LedgerService;

function makeAccount(): Account
{
    static $n = 0;
    $n++;

    $school = School::firstOrCreate(['code' => 'T'], ['name' => 'Test School']);
    $student = Student::create([
        'school_id' => $school->id,
        'student_code' => "S{$n}",
        'name' => "Kid {$n}",
    ]);

    return $student->account;
}

it('credits a top-up and keeps the balance equal to the ledger sum', function () {
    $ledger = app(LedgerService::class);
    $account = makeAccount();

    $entry = $ledger->topUp($account, 5000, 'topup-1');

    expect($entry->balance_after)->toBe(5000)
        ->and($account->balance)->toBe(5000)
        ->and($ledger->isConsistent($account))->toBeTrue();
});

it('deducts a purchase from the balance', function () {
    $ledger = app(LedgerService::class);
    $account = makeAccount();

    $ledger->topUp($account, 5000, 'topup-1');
    $ledger->purchase($account, 1200, 'sale-1');

    expect($account->balance)->toBe(3800)
        ->and($ledger->isConsistent($account))->toBeTrue();
});

it('rejects a purchase above the balance and records nothing', function () {
    $ledger = app(LedgerService::class);
    $account = makeAccount();
    $ledger->topUp($account, 1000, 'topup-1');

    expect(fn() => $ledger->purchase($account, 1500, 'sale-1'))
        ->toThrow(InsufficientBalanceException::class);

    expect($account->refresh()->balance)->toBe(1000)
        ->and(LedgerEntry::where('account_id', $account->id)->count())->toBe(1);
});

it('does not charge twice when the same request is retried', function () {
    $ledger = app(LedgerService::class);
    $account = makeAccount();
    $ledger->topUp($account, 5000, 'topup-1');

    $first = $ledger->purchase($account, 1000, 'sale-1');
    $retry = $ledger->purchase($account, 1000, 'sale-1');

    expect($retry->id)->toBe($first->id)
        ->and($account->refresh()->balance)->toBe(4000)
        ->and(LedgerEntry::where('account_id', $account->id)->count())->toBe(2);
});

it('refuses to reuse an idempotency key for a different amount', function () {
    $ledger = app(LedgerService::class);
    $account = makeAccount();
    $ledger->topUp($account, 5000, 'topup-1');
    $ledger->purchase($account, 1000, 'sale-1');

    expect(fn() => $ledger->purchase($account, 2000, 'sale-1'))
        ->toThrow(InvalidArgumentException::class);
});

it('never lets ledger entries be changed or deleted', function () {
    $ledger = app(LedgerService::class);
    $entry = $ledger->topUp(makeAccount(), 5000, 'topup-1');

    expect(fn() => $entry->update(['amount' => 1]))->toThrow(LogicException::class);
    expect(fn() => $entry->delete())->toThrow(LogicException::class);
});