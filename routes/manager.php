<?php

use App\Http\Controllers\Manager\RefundController;
use App\Http\Controllers\Manager\ReportController;
use App\Http\Controllers\SalesLogController;
use Illuminate\Support\Facades\Route;

// Included from routes/web.php

Route::middleware('auth')->group(function () {
    Route::middleware('role:cashier|manager|admin|super-admin')->group(function () {
        Route::get('/till/sales', [SalesLogController::class, 'index'])->name('till.sales');
        Route::post('/till/sales/{sale}/refund', [SalesLogController::class, 'refund'])->name('till.sales.refund');
    });

    Route::middleware('role:manager|admin|super-admin')->group(function () {
        Route::redirect('/manager', '/manager/reports/daily')->name('manager');

        Route::get('/manager/reports/daily', [ReportController::class, 'daily'])->name('manager.reports.daily');
        Route::get('/manager/reports/reconciliation', [ReportController::class, 'reconciliation'])->name('manager.reports.reconciliation');
        Route::get('/manager/reports/reconciliation/topups.csv', [ReportController::class, 'topupsCsv'])->name('manager.reports.topups-csv');

        Route::get('/manager/refunds', [RefundController::class, 'index'])->name('manager.refunds');
        Route::post('/manager/refunds/{refund}/approve', [RefundController::class, 'approve'])->name('manager.refunds.approve');
        Route::post('/manager/refunds/{refund}/reject', [RefundController::class, 'reject'])->name('manager.refunds.reject');
    });
});
