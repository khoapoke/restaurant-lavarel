<?php

use App\Http\Controllers\Api\Admin\AccountController as AdminAccountController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\DishController as AdminDishController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\Admin\ReservationController as AdminReservationController;
use App\Http\Controllers\Api\Admin\TableController as AdminTableController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Customer\CartController;
use App\Http\Controllers\Api\Customer\MenuController;
use App\Http\Controllers\Api\Customer\OrderController;
use App\Http\Controllers\Api\Customer\PaymentController;
use App\Http\Controllers\Api\Customer\ProfileController;
use App\Http\Controllers\Api\Customer\ReservationController as CustomerReservationController;
use App\Http\Controllers\Api\Staff\BillingController;
use App\Http\Controllers\Api\Staff\KitchenController;
use App\Http\Controllers\Api\Staff\PosOrderController;
use App\Http\Controllers\Api\Staff\PosSessionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Restaurant System (v1)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    // 0. Shared Authentication APIs
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
        Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
    });

    // PHẦN A: CUSTOMER APIS (PHÂN HỆ KHÁCH HÀNG)
    Route::prefix('customer')->group(function () {
        // A.2. Thực đơn, Tìm kiếm & Lọc món ăn (Public - Guest & Customer)
        Route::get('/categories', [MenuController::class, 'categories']);
        Route::get('/dishes', [MenuController::class, 'dishes']);
        Route::get('/dishes/{dishId}', [MenuController::class, 'dishDetail'])->whereNumber('dishId');
        Route::get('/recommendations/weather', [MenuController::class, 'weatherRecommendations']);

        // Protected customer endpoints (Profile, Cart, Orders, Reservations)
        Route::middleware(['auth:sanctum', 'account.active', 'role:CUSTOMER'])->group(function () {
            // A.1. Quản lý Hồ sơ, Địa chỉ & Dị ứng
            Route::get('/profile', [ProfileController::class, 'show']);
            Route::put('/profile', [ProfileController::class, 'update']);

            // A.3. Quản lý Giỏ hàng (CRT-01 -> CRT-04, MNU-08)
            Route::prefix('cart')->group(function () {
                Route::get('/', [CartController::class, 'show']);
                Route::post('/items', [CartController::class, 'addItem']);
                Route::put('/items/{dishId}', [CartController::class, 'updateQuantity'])->whereNumber('dishId');
                Route::delete('/items/{dishId}', [CartController::class, 'removeItem'])->whereNumber('dishId');
            });

            // A.4. Checkout, Lịch sử Đơn hàng (ORD-01 -> ORD-06)
            Route::prefix('orders')->group(function () {
                Route::get('/', [OrderController::class, 'index']);
                Route::post('/checkout', [OrderController::class, 'checkout']);
                Route::get('/{orderId}', [OrderController::class, 'show'])->whereNumber('orderId');
                Route::post('/{orderId}/cancel', [OrderController::class, 'cancel'])->whereNumber('orderId');
            });

            // A.4. Thanh toán QR (PAY-01 -> PAY-03)
            Route::prefix('payments')->group(function () {
                Route::post('/{orderId}/qr', [PaymentController::class, 'generateQr'])->whereNumber('orderId');
                Route::get('/{orderId}/status', [PaymentController::class, 'getStatus'])->whereNumber('orderId');
                Route::post('/{orderId}/retry', [PaymentController::class, 'retry'])->whereNumber('orderId');
            });

            // A.5. Đặt bàn trực tuyến (BKG-01 -> BKG-04)
            Route::get('/tables/available', [CustomerReservationController::class, 'availableTables']);
            Route::get('/reservations', [CustomerReservationController::class, 'index']);
            Route::post('/reservations', [CustomerReservationController::class, 'store']);
            Route::put('/reservations/{reservationId}', [CustomerReservationController::class, 'update'])->whereNumber('reservationId');
            Route::post('/reservations/{reservationId}/cancel', [CustomerReservationController::class, 'cancel'])->whereNumber('reservationId');
        });
    });

    // Webhooks nhận kết quả từ Cổng thanh toán (VietQR/SePay/PayOS)
    Route::post('/webhooks/payment-callback', [PaymentController::class, 'handleCallback'])->middleware('throttle:60,1');

    // PHẦN B: STAFF POS APIS
    Route::prefix('staff/pos')->middleware(['auth:sanctum', 'account.active', 'role:STAFF,ADMIN'])->group(function () {
        Route::get('/tables', [PosSessionController::class, 'floor']);
        Route::post('/tables/{table}/complete-cleaning', [PosSessionController::class, 'completeCleaning']);
        Route::post('/sessions', [PosSessionController::class, 'store']);
        Route::get('/sessions/{sessionId}', [PosSessionController::class, 'show'])->whereNumber('sessionId');
        Route::post('/sessions/{session}/orders', [PosOrderController::class, 'store']);
        Route::post('/orders/{order}/items', [PosOrderController::class, 'addItem']);
        Route::patch('/order-items/{item}', [PosOrderController::class, 'updateItem']);
        Route::delete('/order-items/{item}', [PosOrderController::class, 'destroyItem']);
        Route::post('/orders/{order}/send-kitchen', [PosOrderController::class, 'sendKitchen']);
        Route::post('/sessions/{session}/invoices', [BillingController::class, 'createInvoice']);
        Route::post('/sessions/{session}/reopen', [BillingController::class, 'reopen']);
        Route::post('/sessions/{session}/close', [BillingController::class, 'close']);
        Route::post('/sessions/{session}/cancel', [PosSessionController::class, 'cancel']);
    });

    Route::prefix('staff')->middleware(['auth:sanctum', 'account.active', 'role:STAFF,ADMIN'])->group(function () {
        Route::get('/kitchen/items', [KitchenController::class, 'index']);
        Route::patch('/kitchen/items/{item}/status', [KitchenController::class, 'updateStatus']);
        Route::post('/invoices/{invoice}/payments', [BillingController::class, 'pay']);
    });

    // PHẦN C: ADMIN APIS (PHÂN HỆ QUẢN TRỊ VIÊN)
    Route::prefix('admin')->middleware(['auth:sanctum', 'account.active', 'role:ADMIN'])->group(function () {
        // B.1. Quản lý Người dùng
        Route::get('/accounts', [AdminAccountController::class, 'index']);
        Route::patch('/accounts/{accountId}/lock', [AdminAccountController::class, 'lock'])->whereNumber('accountId');
        Route::patch('/accounts/{accountId}/unlock', [AdminAccountController::class, 'unlock'])->whereNumber('accountId');

        // B.2. Quản lý Danh mục (MNU-09 -> MNU-12)
        Route::prefix('categories')->group(function () {
            Route::get('/', [AdminCategoryController::class, 'index']);
            Route::get('/{categoryId}', [AdminCategoryController::class, 'show'])->whereNumber('categoryId');
            Route::post('/', [AdminCategoryController::class, 'store']);
            Route::put('/{categoryId}', [AdminCategoryController::class, 'update'])->whereNumber('categoryId');
            Route::patch('/{categoryId}/visibility', [AdminCategoryController::class, 'updateVisibility'])->whereNumber('categoryId');
            Route::delete('/{categoryId}', [AdminCategoryController::class, 'destroy'])->whereNumber('categoryId');
        });

        // B.2. Quản lý Món ăn (MNU-13 -> MNU-17)
        Route::prefix('dishes')->group(function () {
            Route::get('/', [AdminDishController::class, 'index']);
            Route::get('/{dishId}', [AdminDishController::class, 'show'])->whereNumber('dishId');
            Route::post('/', [AdminDishController::class, 'store']);
            Route::put('/{dishId}', [AdminDishController::class, 'update'])->whereNumber('dishId');
            Route::patch('/{dishId}/availability', [AdminDishController::class, 'updateAvailability'])->whereNumber('dishId');
            Route::patch('/{dishId}/visibility', [AdminDishController::class, 'updateVisibility'])->whereNumber('dishId');
            Route::delete('/{dishId}', [AdminDishController::class, 'destroy'])->whereNumber('dishId');
        });

        // B.3. Điều phối đơn hàng và thanh toán
        Route::get('/orders', [AdminOrderController::class, 'index']);
        Route::get('/orders/{orderId}', [AdminOrderController::class, 'show'])->whereNumber('orderId');
        Route::patch('/orders/{orderId}/status', [AdminOrderController::class, 'updateStatus'])->whereNumber('orderId');
        Route::patch('/orders/{orderId}/fulfillment-status', [AdminOrderController::class, 'updateFulfillmentStatus'])->whereNumber('orderId');
        Route::post('/orders/{orderId}/cancel', [AdminOrderController::class, 'cancel'])->whereNumber('orderId');
        Route::get('/payments', [AdminPaymentController::class, 'index']);
        Route::post('/payments/reconcile', [AdminPaymentController::class, 'reconcile']);
        Route::get('/payments/callback-logs', [AdminPaymentController::class, 'callbackLogs']);
        Route::post('/payments/{paymentId}/refund', [AdminPaymentController::class, 'refund'])->whereNumber('paymentId');
        Route::post('/payments/{paymentId}/confirm', [AdminPaymentController::class, 'confirm'])->whereNumber('paymentId');

        // B.4. Bàn ăn, đặt bàn và báo cáo
        Route::get('/tables', [AdminTableController::class, 'index']);
        Route::post('/tables', [AdminTableController::class, 'store']);
        Route::put('/tables/{tableId}', [AdminTableController::class, 'update'])->whereNumber('tableId');
        Route::get('/reservations', [AdminReservationController::class, 'index']);
        Route::patch('/reservations/{reservationId}/status', [AdminReservationController::class, 'updateStatus'])->whereNumber('reservationId');
        Route::get('/reports/dashboard', [AdminReportController::class, 'dashboard']);
    });

    // Authenticated user test route
    Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
        return $request->user();
    });
});
