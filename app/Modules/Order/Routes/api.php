<?php

use App\Modules\Order\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Modules\Order\Http\Controllers\Customer\OrderController;
use Illuminate\Support\Facades\Route;

// Customer Order APIs (ORD-01 -> ORD-06)
Route::prefix('customer/orders')->middleware(['auth:sanctum', 'account.active', 'role:CUSTOMER'])->group(function () {
    Route::get('/', [OrderController::class, 'index']);
    Route::post('/checkout', [OrderController::class, 'checkout']);
    Route::get('/{orderId}', [OrderController::class, 'show'])->whereNumber('orderId');
    Route::match(['post', 'put'], '/{orderId}/cancel', [OrderController::class, 'cancel'])->whereNumber('orderId');
});

// Admin & Staff Order APIs (ORD-07 -> ORD-13, Manual Payment Confirmation)
Route::prefix('admin/orders')->middleware(['auth:sanctum', 'account.active', 'role:ADMIN,STAFF'])->group(function () {
    Route::get('/', [AdminOrderController::class, 'index']);
    Route::get('/{orderId}', [AdminOrderController::class, 'show'])->whereNumber('orderId');
    Route::patch('/{orderId}/status', [AdminOrderController::class, 'updateStatus'])->whereNumber('orderId');
    Route::match(['post', 'put'], '/{orderId}/cancel', [AdminOrderController::class, 'cancel'])->whereNumber('orderId');
    Route::post('/{orderId}/confirm-payment', [AdminOrderController::class, 'confirmPayment'])->whereNumber('orderId');
});

