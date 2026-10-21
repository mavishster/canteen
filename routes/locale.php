<?php

use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

// Included from routes/web.php. Open to everyone, so the sign-in page can switch language too.
Route::post('/locale', [LocaleController::class, 'set'])->name('locale.set');
