<?php

use App\Modules\Report\Http\Controllers\Admin\ReportController as AdminReportController;
use Illuminate\Support\Facades\Route;

// Admin Reports & Analytics APIs (RPT-01 -> RPT-04)
Route::prefix('admin/reports')->middleware(['auth:sanctum', 'account.active', 'role:ADMIN'])->group(function () {
    Route::get('/dashboard', [AdminReportController::class, 'dashboard']);
    Route::get('/revenue', [AdminReportController::class, 'revenue']);
    Route::get('/orders', [AdminReportController::class, 'orders']);
    Route::get('/top-dishes', [AdminReportController::class, 'topDishes']);
    Route::get('/reservations', [AdminReportController::class, 'reservations']);
});

