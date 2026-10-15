<?php

// Run by MealConcurrencyTest: one process = one reader tapping the same child for the same meal.
[$script, $schoolId, $studentId, $mealTypeId, $key, $startAt] = $argv;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Wait until the agreed instant so every process hits the database together
while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    $student = App\Models\Student::withoutGlobalScopes()->findOrFail((int) $studentId);
    app(App\Services\MealCollectionService::class)->collect($student, (int) $schoolId, null, (int) $mealTypeId, 'card', $key);
    echo 'OK';
} catch (App\Exceptions\MealException $e) {
    echo $e->reason;
} catch (Throwable $e) {
    echo 'ERROR ' . $e->getMessage();
}
