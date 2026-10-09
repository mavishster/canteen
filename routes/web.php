<?php

use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\StudentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TillController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::view('/', 'dashboard')->name('dashboard');

    Route::middleware('role:cashier|manager|admin|super-admin')->group(function () {
        Route::get('/till', [TillController::class, 'index'])->name('till');
        Route::post('/till/checkout', [TillController::class, 'checkout'])->name('till.checkout');
    });

    Route::view('/manager', 'placeholder', ['title' => 'Manager'])
        ->middleware('role:manager|admin|super-admin')->name('manager');

    Route::middleware('role:admin|super-admin')->group(function () {
        Route::redirect('/admin', '/admin/students')->name('admin');

        Route::get('/admin/students', [StudentController::class, 'index'])->name('admin.students');
        Route::post('/admin/students', [StudentController::class, 'store'])->name('admin.students.store');
        Route::post('/admin/students/{student}/card', [StudentController::class, 'bindCard'])->name('admin.students.card');
        Route::post('/admin/cards/{card}/block', [StudentController::class, 'blockCard'])->name('admin.cards.block');
        Route::post('/admin/cards/{card}/unblock', [StudentController::class, 'unblockCard'])->name('admin.cards.unblock');

        Route::get('/admin/products', [ProductController::class, 'index'])->name('admin.products');
        Route::post('/admin/products', [ProductController::class, 'store'])->name('admin.products.store');
        Route::post('/admin/products/{product}', [ProductController::class, 'update'])->name('admin.products.update');
        Route::post('/admin/products/{product}/toggle', [ProductController::class, 'toggle'])->name('admin.products.toggle');
    });
});
