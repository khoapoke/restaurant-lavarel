<?php

use App\Modules\Payment\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Modules\Payment\Http\Controllers\Customer\PaymentController;
use Illuminate\Support\Facades\Route;

// 1. Customer Payment APIs (PAY-01 -> PAY-03)
Route::prefix('customer/payments')->middleware(['auth:sanctum', 'account.active', 'role:CUSTOMER'])->group(function () {
    Route::post('/{orderId}/qr', [PaymentController::class, 'generateQr'])->whereNumber('orderId');
    Route::get('/{orderId}/status', [PaymentController::class, 'getStatus'])->whereNumber('orderId');
    Route::post('/{orderId}/retry', [PaymentController::class, 'retry'])->whereNumber('orderId');
});

// 2. Webhooks Callback (Public - SePay/PayOS/VietQR)
Route::post('/webhooks/payment-callback', [PaymentController::class, 'handleCallback']);

// 3. Admin Payment & Reconciliation APIs (PAY-04 -> PAY-07)
Route::prefix('admin/payments')->middleware(['auth:sanctum', 'account.active', 'role:ADMIN'])->group(function () {
    Route::get('/', [AdminPaymentController::class, 'index']);
    Route::post('/reconcile', [AdminPaymentController::class, 'reconcile']);
    Route::post('/{paymentId}/refund', [AdminPaymentController::class, 'refund'])->whereNumber('paymentId');
    Route::get('/callback-logs', [AdminPaymentController::class, 'callbackLogs']);
});

