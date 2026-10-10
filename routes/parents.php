<?php

use App\Http\Controllers\Admin\ParentAccountController;
use Illuminate\Support\Facades\Route;

// Included from routes/web.php

Route::middleware(['auth', 'role:admin|super-admin'])->group(function () {
    Route::get('/admin/parents', [ParentAccountController::class, 'index'])->name('admin.parents');
    Route::post('/admin/parents', [ParentAccountController::class, 'store'])->name('admin.parents.store');
    Route::post('/admin/parents/{parentUser}/students', [ParentAccountController::class, 'link'])->name('admin.parents.link');
    Route::post('/admin/parents/{parentUser}/password', [ParentAccountController::class, 'password'])->name('admin.parents.password');
    Route::post('/admin/parent-links/{link}/delete', [ParentAccountController::class, 'unlink'])->name('admin.parents.unlink');
});
