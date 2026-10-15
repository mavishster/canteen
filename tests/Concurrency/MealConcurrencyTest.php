<?php

use App\Models\MealConsumption;
use App\Models\MealPlan;
use App\Models\MealType;
use App\Models\School;
use App\Models\Student;
use App\Services\LedgerService;
use App\Services\MealSubscriptionService;
use Symfony\Component\Process\Process;

/** @param array<int, string> $keys  one key per reader */
function mealRaceRun(int $schoolId, int $studentId, int $mealTypeId, array $keys): array
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

    $startAt = microtime(true) + 5;
    $processes = [];

    foreach ($keys as $key) {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Concurrency/meal-collect.php'), $schoolId, $studentId, $mealTypeId, $key, $startAt],
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

it('serves a child one lunch even when six readers tap at the same moment', function () {
    $school = School::create(['name' => 'Meal Race', 'code' => 'MRACE']);
    $student = Student::create(['school_id' => $school->id, 'student_code' => 'MR1', 'name' => 'Racer Kid']);
    app(LedgerService::class)->topUp($student->account, 50000, 'mr-seed');

    $lunch = MealType::create(['school_id' => $school->id, 'code' => 'lunch', 'name' => 'Lunch', 'is_active' => true]);
    $plan = MealPlan::create(['school_id' => $school->id, 'meal_type_id' => $lunch->id, 'name' => 'Lunch 1 month', 'duration_months' => 1, 'price' => 3000, 'is_active' => true]);
    app(MealSubscriptionService::class)->purchase($student, $plan->id, 'mr-sub');

    $keys = array_map(fn ($i) => "mr-tap-{$i}", range(1, 6));

    $results = mealRaceRun($school->id, $student->id, $lunch->id, $keys);
    $counts = array_count_values($results);

    expect(array_diff($results, ['OK', 'already_collected']))->toBe([])
        ->and($counts['OK'] ?? 0)->toBe(1)
        ->and($counts['already_collected'] ?? 0)->toBe(5);

    expect(MealConsumption::withoutGlobalScopes()->where('student_id', $student->id)->count())->toBe(1);
});
