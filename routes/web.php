<?php

use App\Http\Controllers\Admin\StudentController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::view('/', 'dashboard')->name('dashboard');

    Route::view('/till', 'placeholder', ['title' => 'Cashier till'])
        ->middleware('role:cashier|manager|admin|super-admin')->name('till');

    Route::view('/manager', 'placeholder', ['title' => 'Manager'])
        ->middleware('role:manager|admin|super-admin')->name('manager');

    Route::middleware('role:admin|super-admin')->group(function () {
        Route::redirect('/admin', '/admin/students')->name('admin');

        Route::get('/admin/students', [StudentController::class, 'index'])->name('admin.students');
        Route::post('/admin/students', [StudentController::class, 'store'])->name('admin.students.store');
        Route::post('/admin/students/{student}/card', [StudentController::class, 'bindCard'])->name('admin.students.card');
        Route::post('/admin/cards/{card}/block', [StudentController::class, 'blockCard'])->name('admin.cards.block');
        Route::post('/admin/cards/{card}/unblock', [StudentController::class, 'unblockCard'])->name('admin.cards.unblock');
    });
});
