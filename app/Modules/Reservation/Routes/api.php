<?php

use App\Modules\Reservation\Http\Controllers\Admin\ReservationController as AdminReservationController;
use App\Modules\Reservation\Http\Controllers\Admin\TableController as AdminTableController;
use App\Modules\Reservation\Http\Controllers\Customer\ReservationController;
use Illuminate\Support\Facades\Route;

// 1. Customer Public Table Lookup (BKG-01)
Route::prefix('customer')->group(function () {
    Route::get('/tables/available', [ReservationController::class, 'availableTables']);
    Route::get('/reservations/available-tables', [ReservationController::class, 'availableTables']);
});

// 2. Customer Online Reservation APIs (BKG-02 -> BKG-04, BKG-13)
Route::prefix('customer/reservations')->middleware(['auth:sanctum', 'account.active', 'role:CUSTOMER'])->group(function () {
    Route::get('/', [ReservationController::class, 'index']);
    Route::post('/', [ReservationController::class, 'store']);
    Route::get('/{reservationId}', [ReservationController::class, 'show'])->whereNumber('reservationId');
    Route::put('/{reservationId}', [ReservationController::class, 'update'])->whereNumber('reservationId');
    Route::match(['post', 'put'], '/{reservationId}/cancel', [ReservationController::class, 'cancel'])->whereNumber('reservationId');
    Route::post('/{reservationId}/mock-deposit', [ReservationController::class, 'mockDeposit'])->whereNumber('reservationId');
});

// 3. Admin Tables & Reservations Management APIs (BKG-05 -> BKG-12, BKG-14)
Route::prefix('admin')->middleware(['auth:sanctum', 'account.active', 'role:ADMIN,STAFF'])->group(function () {
    // Tables Management
    Route::prefix('tables')->group(function () {
        Route::get('/', [AdminTableController::class, 'index']);
        Route::post('/', [AdminTableController::class, 'store']);
        Route::get('/{tableId}', [AdminTableController::class, 'show'])->whereNumber('tableId');
        Route::put('/{tableId}', [AdminTableController::class, 'update'])->whereNumber('tableId');
        Route::patch('/{tableId}/status', [AdminTableController::class, 'updateStatus'])->whereNumber('tableId');
        Route::delete('/{tableId}', [AdminTableController::class, 'destroy'])->whereNumber('tableId');
    });

    // Reservations & Time Slots Management
    Route::prefix('reservations')->group(function () {
        Route::get('/time-slots', [AdminReservationController::class, 'getTimeSlots']);
        Route::put('/time-slots', [AdminReservationController::class, 'updateTimeSlots']);
        Route::get('/', [AdminReservationController::class, 'index']);
        Route::get('/{reservationId}', [AdminReservationController::class, 'show'])->whereNumber('reservationId');
        Route::patch('/{reservationId}/status', [AdminReservationController::class, 'updateStatus'])->whereNumber('reservationId');
        Route::post('/{reservationId}/confirm-deposit', [AdminReservationController::class, 'confirmDeposit'])->whereNumber('reservationId');
    });
});

