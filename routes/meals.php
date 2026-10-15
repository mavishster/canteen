<?php

use App\Http\Controllers\Admin\MealController;
use Illuminate\Support\Facades\Route;

// Included from routes/web.php

Route::middleware(['auth', 'role:admin|super-admin'])->group(function () {
    Route::get('/admin/meals', [MealController::class, 'index'])->name('admin.meals');
    Route::post('/admin/meals/types', [MealController::class, 'storeType'])->name('admin.meals.types.store');
    Route::post('/admin/meals/types/{mealType}/toggle', [MealController::class, 'toggleType'])->name('admin.meals.types.toggle');
    Route::post('/admin/meals/plans', [MealController::class, 'storePlan'])->name('admin.meals.plans.store');
    Route::post('/admin/meals/plans/{mealPlan}/toggle', [MealController::class, 'togglePlan'])->name('admin.meals.plans.toggle');
    Route::post('/admin/meals/sell', [MealController::class, 'sell'])->name('admin.meals.sell');
});
