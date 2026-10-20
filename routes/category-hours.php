<?php

use App\Http\Controllers\Admin\CategoryHoursController;
use Illuminate\Support\Facades\Route;

// Included from routes/web.php

Route::middleware(['auth', 'role:admin|super-admin'])->group(function () {
    Route::get('/admin/category-hours', [CategoryHoursController::class, 'index'])->name('admin.category-hours');
    Route::post('/admin/category-hours/{category}', [CategoryHoursController::class, 'update'])->name('admin.category-hours.update');
    Route::post('/admin/category-hours/{category}/clear', [CategoryHoursController::class, 'clear'])->name('admin.category-hours.clear');
});
