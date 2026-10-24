<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Included from routes/web.php
Route::get('/health', HealthController::class)->name('health');
