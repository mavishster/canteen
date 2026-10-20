<?php

use App\Http\Controllers\Admin\ConsentController;
use Illuminate\Support\Facades\Route;

// Included from routes/web.php

Route::middleware(['auth', 'role:admin|super-admin'])->group(function () {
    Route::get('/admin/consent', [ConsentController::class, 'index'])->name('admin.consent');
    Route::post('/admin/consent/publish', [ConsentController::class, 'publish'])->name('admin.consent.publish');
    Route::post('/admin/consent/toggle', [ConsentController::class, 'toggle'])->name('admin.consent.toggle');
});
