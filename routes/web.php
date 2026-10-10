<?php

use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\SchoolSettingsController;
use App\Http\Controllers\Admin\StudentController;
use App\Http\Controllers\Admin\StudentRulesController;
use App\Http\Controllers\Admin\TopUpController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Dev\PaymentPageController;
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

    Route::middleware('role:admin|super-admin')->group(function () {
        Route::redirect('/admin', '/admin/students')->name('admin');

        Route::get('/admin/students', [StudentController::class, 'index'])->name('admin.students');
        Route::post('/admin/students', [StudentController::class, 'store'])->name('admin.students.store');
        Route::post('/admin/students/{student}/card', [StudentController::class, 'bindCard'])->name('admin.students.card');
        Route::post('/admin/cards/{card}/block', [StudentController::class, 'blockCard'])->name('admin.cards.block');
        Route::post('/admin/cards/{card}/unblock', [StudentController::class, 'unblockCard'])->name('admin.cards.unblock');

        Route::get('/admin/students/{student}/rules', [StudentRulesController::class, 'show'])->name('admin.students.rules');
        Route::post('/admin/students/{student}/limits', [StudentRulesController::class, 'limits'])->name('admin.students.limits');
        Route::post('/admin/students/{student}/bans', [StudentRulesController::class, 'addBan'])->name('admin.students.bans');
        Route::post('/admin/bans/{ban}/delete', [StudentRulesController::class, 'removeBan'])->name('admin.bans.delete');

        Route::get('/admin/products', [ProductController::class, 'index'])->name('admin.products');
        Route::post('/admin/products', [ProductController::class, 'store'])->name('admin.products.store');
        Route::post('/admin/products/{product}', [ProductController::class, 'update'])->name('admin.products.update');
        Route::post('/admin/products/{product}/toggle', [ProductController::class, 'toggle'])->name('admin.products.toggle');
        Route::post('/admin/products/{product}/tracking', [ProductController::class, 'tracking'])->name('admin.products.tracking');
        Route::post('/admin/products/{product}/stock/receive', [ProductController::class, 'receive'])->name('admin.products.receive');
        Route::post('/admin/products/{product}/stock/count', [ProductController::class, 'count'])->name('admin.products.count');
        Route::get('/admin/stock', [ProductController::class, 'movements'])->name('admin.stock');

        Route::get('/admin/settings', [SchoolSettingsController::class, 'edit'])->name('admin.settings');
        Route::post('/admin/settings', [SchoolSettingsController::class, 'update'])->name('admin.settings.update');

        Route::get('/admin/topups', [TopUpController::class, 'index'])->name('admin.topups');
        Route::post('/admin/topups', [TopUpController::class, 'store'])->name('admin.topups.store');

        // Pretend bank page for the fake gateway: these routes do not exist in production
        if (! app()->isProduction()) {
            Route::get('/dev/pay/{ref}', [PaymentPageController::class, 'show'])->name('dev.pay');
            Route::post('/dev/pay/{ref}/{outcome}', [PaymentPageController::class, 'complete'])->name('dev.pay.complete');
        }
    });
});

require __DIR__ . '/manager.php';
