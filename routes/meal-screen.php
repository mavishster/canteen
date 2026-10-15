<?php

use App\Http\Controllers\Admin\CalendarController;
use App\Http\Controllers\MealScreenController;
use Illuminate\Support\Facades\Route;

// Included from routes/web.php

Route::middleware(['auth', 'role:cashier|manager|admin|super-admin'])->group(function () {
    Route::get('/till/meals', [MealScreenController::class, 'index'])->name('till.meals');
    Route::post('/till/meals/collect', [MealScreenController::class, 'collect'])->name('till.meals.collect');
    Route::get('/till/meals/counts', [MealScreenController::class, 'counts'])->name('till.meals.counts');
});

Route::middleware(['auth', 'role:admin|super-admin'])->group(function () {
    Route::post('/admin/meals/closed-days', [CalendarController::class, 'update'])->name('admin.meals.closed-days');
});
