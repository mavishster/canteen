<?php

use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ProductController;
use Illuminate\Support\Facades\Route;

// Included from routes/web.php

Route::middleware(['auth', 'role:admin|super-admin'])->group(function () {
    Route::get('/admin/categories', [CategoryController::class, 'index'])->name('admin.categories');
    Route::post('/admin/categories', [CategoryController::class, 'store'])->name('admin.categories.store');
    Route::post('/admin/categories/{category}/rename', [CategoryController::class, 'rename'])->name('admin.categories.rename');
    Route::post('/admin/categories/{category}/toggle', [CategoryController::class, 'toggle'])->name('admin.categories.toggle');
    Route::post('/admin/categories/{category}/merge', [CategoryController::class, 'merge'])->name('admin.categories.merge');
    Route::post('/admin/categories/{category}/delete', [CategoryController::class, 'destroy'])->name('admin.categories.delete');

    // Changing the category of an existing product (chosen from the list)
    Route::post('/admin/products/{product}/category', [ProductController::class, 'setCategory'])->name('admin.products.category');
});
