<?php

// Run by StockConcurrencyTest: one process = one till trying to sell one item.
[$script, $schoolId, $uid, $productId, $key, $startAt] = $argv;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Wait until the agreed instant so every process hits the database together
while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    app(App\Services\SaleService::class)->checkout((int) $schoolId, null, $uid, [
        ['product_id' => (int) $productId, 'quantity' => 1],
    ], $key);
    echo 'OK';
} catch (App\Exceptions\SaleException $e) {
    echo $e->reason;
} catch (Throwable $e) {
    echo 'ERROR ' . $e->getMessage();
}
