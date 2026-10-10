<?php

use App\Http\Controllers\Api\ParentMealController;
use App\Http\Controllers\PaymentWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Payment gateway callbacks (no login, no CSRF: authenticated by the gateway's signature)
Route::post('/webhooks/payments/{gateway}', [PaymentWebhookController::class, 'handle'])
    ->middleware('throttle:60,1')
    ->name('webhooks.payments');

Route::prefix('parent')
    ->middleware(['sis.parent', 'throttle:60,1'])
    ->name('api.parent.')
    ->group(function () {
        Route::get('/students', [ParentMealController::class, 'students'])->name('students');
        Route::get('/students/{student}/meal-plans', [ParentMealController::class, 'plans'])->name('students.plans');
        Route::get('/students/{student}/subscriptions', [ParentMealController::class, 'subscriptions'])->name('students.subscriptions');
        Route::post('/students/{student}/subscriptions', [ParentMealController::class, 'purchase'])->name('students.subscriptions.purchase');
        Route::get('/students/{student}/wallet', [ParentMealController::class, 'wallet'])->name('students.wallet');
    });
