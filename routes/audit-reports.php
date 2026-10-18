<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Manager\WalletReportController;
use Illuminate\Support\Facades\Route;

// Included from routes/web.php

Route::middleware(['auth', 'role:manager|admin|super-admin'])->group(function () {
    Route::get('/manager/reports/topups', [WalletReportController::class, 'topups'])->name('manager.reports.topups');
    Route::get('/manager/reports/topups.csv', [WalletReportController::class, 'topupsCsv'])->name('manager.reports.topups.csv');
    Route::get('/manager/reports/balances', [WalletReportController::class, 'balances'])->name('manager.reports.balances');
    Route::get('/manager/reports/balances.csv', [WalletReportController::class, 'balancesCsv'])->name('manager.reports.balances.csv');
});

Route::middleware(['auth', 'role:admin|super-admin'])->group(function () {
    Route::get('/admin/audit', [AuditLogController::class, 'index'])->name('admin.audit');
    Route::get('/admin/audit.csv', [AuditLogController::class, 'csv'])->name('admin.audit.csv');
});
