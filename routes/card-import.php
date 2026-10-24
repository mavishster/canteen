<?php

use App\Http\Controllers\Admin\CardImportController;
use Illuminate\Support\Facades\Route;

// Included from routes/web.php

Route::middleware(['auth', 'role:admin|super-admin'])->prefix('admin/cards')->group(function () {
    Route::get('/import', [CardImportController::class, 'form'])->name('admin.cards.import');
    Route::get('/missing.csv', [CardImportController::class, 'template'])->name('admin.cards.missing');
    Route::post('/import/preview', [CardImportController::class, 'preview'])->name('admin.cards.import.preview');
    Route::post('/import/apply', [CardImportController::class, 'apply'])->name('admin.cards.import.apply');
    Route::get('/import/result', [CardImportController::class, 'result'])->name('admin.cards.import.result');
});
