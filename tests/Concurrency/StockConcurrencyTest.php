<?php

use App\Models\Product;
use App\Models\Sale;
use App\Models\School;
use App\Models\StockMovement;
use App\Models\Student;
use App\Services\CardService;
use App\Services\LedgerService;
use Symfony\Component\Process\Process;

/** @param array<int, array{0:string, 1:string}> $jobs  [uid, key] per till */
function sellInParallel(int $schoolId, int $productId, array $jobs): array
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

    $startAt = microtime(true) + 5;   // time for every process to boot first
    $processes = [];

    foreach ($jobs as [$uid, $key]) {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Concurrency/sell.php'), $schoolId, $uid, $productId, $key, $startAt],
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
});

it('never sells more than the stock when several tills sell the last items at once', function () {
    $school = School::create(['name' => 'Stock Race', 'code' => 'STKRACE']);
    $product = Product::create([
        'school_id' => $school->id,
        'name' => 'Last cakes',
        'price' => 100,
        'track_stock' => true,
        'stock' => 3,
    ]);

    $jobs = [];
    foreach (range(1, 8) as $i) {
        $student = Student::create(['school_id' => $school->id, 'student_code' => "SR{$i}", 'name' => "Racer {$i}"]);
        $uid = strtoupper(dechex(0x40000000 + $i));
        app(CardService::class)->bind($student, $uid);
        app(LedgerService::class)->topUp($student->account, 1000, "sr-seed-{$i}");
        $jobs[] = [$uid, "sr-key-{$i}"];
    }

    $results = sellInParallel($school->id, $product->id, $jobs);
    $counts = array_count_values($results);

    expect(array_diff($results, ['OK', 'out_of_stock']))->toBe([])   // no unexpected errors
        ->and($counts['OK'] ?? 0)->toBe(3)
        ->and($counts['out_of_stock'] ?? 0)->toBe(5);

    expect($product->refresh()->stock)->toBe(0)
        ->and(Sale::count())->toBe(3)
        ->and((int) StockMovement::withoutGlobalScopes()->where('product_id', $product->id)->sum('quantity'))->toBe(-3);
});
