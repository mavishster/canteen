<?php

use App\Http\Controllers\Admin\StaffController;
use Illuminate\Support\Facades\Route;

// Included from routes/web.php

Route::middleware(['auth', 'role:admin|super-admin'])->group(function () {
    Route::get('/admin/staff', [StaffController::class, 'index'])->name('admin.staff');
    Route::post('/admin/staff', [StaffController::class, 'store'])->name('admin.staff.store');
    Route::post('/admin/staff/{staffUser}/role', [StaffController::class, 'role'])->name('admin.staff.role');
    Route::post('/admin/staff/{staffUser}/password', [StaffController::class, 'password'])->name('admin.staff.password');
    Route::post('/admin/staff/{staffUser}/disable', [StaffController::class, 'disable'])->name('admin.staff.disable');
    Route::post('/admin/staff/{staffUser}/enable', [StaffController::class, 'enable'])->name('admin.staff.enable');
});
