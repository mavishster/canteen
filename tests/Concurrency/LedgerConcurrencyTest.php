<?php

use App\Models\LedgerEntry;
use App\Models\School;
use App\Models\Student;
use App\Services\LedgerService;
use Symfony\Component\Process\Process;

function spendInParallel(int $accountId, int $amount, array $keys): array
{
    $db = config('database.connections.mysql');
    $env = [
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => $db['host'],
        'DB_PORT' => $db['port'],
        'DB_DATABASE' => $db['database'],
        'DB_USERNAME' => $db['username'],
        'DB_PASSWORD' => $db['password'],
    ];

    $startAt = microtime(true) + 4;   // time for every process to boot first
    $processes = [];

    foreach ($keys as $key) {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Concurrency/spend.php'), $accountId, $amount, $key, $startAt],
            base_path(),
            $env
        );
        $process->start();
        $processes[] = $process;
    }

    return array_map(function (Process $p) {
        $p->wait();

        return trim($p->getOutput());
    }, $processes);
}

beforeEach(function () {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Concurrency tests need MySQL.');
    }

    $school = School::create(['name' => 'Race School', 'code' => 'RACE']);
    $student = Student::create(['school_id' => $school->id, 'student_code' => 'R1', 'name' => 'Racer']);
    $this->account = $student->account;

    app(LedgerService::class)->topUp($this->account, 5000, 'seed');
});

it('allows only as many parallel purchases as the balance covers', function () {
    $keys = array_map(fn($i) => "race-{$i}", range(1, 10));

    $results = spendInParallel($this->account->id, 1000, $keys);
    $counts = array_count_values($results);

    expect(array_diff($results, ['OK', 'INSUFFICIENT']))->toBe([])   // no errors
        ->and($counts['OK'] ?? 0)->toBe(5)
        ->and($counts['INSUFFICIENT'] ?? 0)->toBe(5);

    expect($this->account->refresh()->balance)->toBe(0)
        ->and(app(LedgerService::class)->isConsistent($this->account))->toBeTrue();
});

it('charges only once when the same tap is sent by several processes at once', function () {
    $results = spendInParallel($this->account->id, 1000, array_fill(0, 6, 'same-tap'));

    expect($results)->each->toBe('OK');

    expect($this->account->refresh()->balance)->toBe(4000)
        ->and(LedgerEntry::withoutGlobalScopes()->where('idempotency_key', 'same-tap')->count())->toBe(1)
        ->and(app(LedgerService::class)->isConsistent($this->account))->toBeTrue();
});