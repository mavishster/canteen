<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Payment gateway callbacks (no login, no CSRF: authenticated by the gateway's signature)
Route::post('/webhooks/payments/{gateway}', [\App\Http\Controllers\PaymentWebhookController::class, 'handle'])
    ->middleware('throttle:60,1')
    ->name('webhooks.payments');

// Parent mobile app API (see docs/parent-api.md)
Route::prefix('parent')->middleware([\App\Http\Middleware\ForceJsonResponse::class])->group(function () {
    Route::post('/login', [\App\Http\Controllers\ParentApi\AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('parent.login');

    Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureParent::class, 'throttle:60,1'])->group(function () {
        Route::post('/logout', [\App\Http\Controllers\ParentApi\AuthController::class, 'logout'])->name('parent.logout');

        Route::get('/children', [\App\Http\Controllers\ParentApi\ChildController::class, 'index'])->name('parent.children');
        Route::get('/children/{student}/transactions', [\App\Http\Controllers\ParentApi\ChildController::class, 'transactions'])->name('parent.transactions');
        Route::get('/children/{student}/rules', [\App\Http\Controllers\ParentApi\ChildController::class, 'rules'])->name('parent.rules');
        Route::put('/children/{student}/limits', [\App\Http\Controllers\ParentApi\ChildController::class, 'setLimits'])->name('parent.limits');
        Route::post('/children/{student}/bans', [\App\Http\Controllers\ParentApi\ChildController::class, 'addBan'])->name('parent.bans.add');
        Route::delete('/children/{student}/bans/{ban}', [\App\Http\Controllers\ParentApi\ChildController::class, 'removeBan'])->name('parent.bans.remove');
        Route::post('/children/{student}/card/block', [\App\Http\Controllers\ParentApi\ChildController::class, 'blockCard'])->name('parent.card.block');
        Route::post('/children/{student}/card/unblock', [\App\Http\Controllers\ParentApi\ChildController::class, 'unblockCard'])->name('parent.card.unblock');

        Route::post('/children/{student}/topups', [\App\Http\Controllers\ParentApi\TopUpController::class, 'start'])->name('parent.topups.start');
        Route::get('/topups/{ref}', [\App\Http\Controllers\ParentApi\TopUpController::class, 'show'])->name('parent.topups.show');
    });
});
