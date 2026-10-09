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
