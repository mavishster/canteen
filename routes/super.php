<?php

use App\Http\Controllers\Super\SchoolController;
use Illuminate\Support\Facades\Route;

// Included from routes/web.php

Route::middleware(['auth', 'role:super-admin'])->prefix('super')->group(function () {
    Route::get('/schools', [SchoolController::class, 'index'])->name('super.schools');
    Route::get('/schools/create', [SchoolController::class, 'create'])->name('super.schools.create');
    Route::post('/schools', [SchoolController::class, 'store'])->name('super.schools.store');
    Route::get('/schools/{school}', [SchoolController::class, 'show'])->name('super.schools.show');
    Route::post('/schools/{school}', [SchoolController::class, 'update'])->name('super.schools.update');
    Route::post('/schools/{school}/admins', [SchoolController::class, 'addAdmin'])->name('super.schools.admins');
    Route::post('/schools/{school}/users/{user}/password', [SchoolController::class, 'resetPassword'])->name('super.schools.password');
});
