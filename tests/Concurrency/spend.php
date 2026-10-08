<?php

[$script, $accountId, $amount, $key, $startAt] = $argv;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Wait until the agreed instant so every process hits the database together
while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    $account = App\Models\Account::withoutGlobalScopes()->findOrFail((int) $accountId);
    app(App\Services\LedgerService::class)->purchase($account, (int) $amount, $key);
    echo 'OK';
} catch (App\Exceptions\InsufficientBalanceException) {
    echo 'INSUFFICIENT';
} catch (Throwable $e) {
    echo 'ERROR ' . $e->getMessage();
}