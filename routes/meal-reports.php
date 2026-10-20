<?php

use App\Http\Controllers\Manager\MealReportController;
use Illuminate\Support\Facades\Route;

// Included from routes/web.php

Route::middleware(['auth', 'role:manager|admin|super-admin'])->group(function () {
    Route::get('/manager/reports/meals', [MealReportController::class, 'index'])->name('manager.reports.meals');
    Route::get('/manager/reports/meals.csv', [MealReportController::class, 'csv'])->name('manager.reports.meals.csv');
});
