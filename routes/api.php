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
use App\Http\Controllers\Api\CommunicationController;
use App\Http\Controllers\Api\Customer\CartController;
use App\Http\Controllers\Api\Customer\MenuController;
use App\Http\Controllers\Api\Customer\OrderController;
use App\Http\Controllers\Api\Customer\PaymentController;
use App\Http\Controllers\Api\Customer\ProfileController;
use App\Http\Controllers\Api\Customer\ReservationController as CustomerReservationController;
use App\Http\Controllers\Api\Dining\GuestOrderController;
use App\Http\Controllers\Api\Dining\GuestServiceRequestController;
use App\Http\Controllers\Api\Dining\GuestTableController;
use App\Http\Controllers\Api\Staff\ApprovalController;
use App\Http\Controllers\Api\Staff\BillingController;
use App\Http\Controllers\Api\Staff\ItemCorrectionController;
use App\Http\Controllers\Api\Staff\KitchenController;
use App\Http\Controllers\Api\Staff\LiveFloorController;
use App\Http\Controllers\Api\Staff\PosOrderController;
use App\Http\Controllers\Api\Staff\PosSessionController;
use App\Http\Controllers\Api\Staff\ReservationDeskController;
use App\Http\Controllers\Api\Staff\SupportController;
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
            Route::get('/notifications', [CommunicationController::class, 'notices']);
            Route::post('/notifications/read-all', [CommunicationController::class, 'readAll']);
            Route::post('/notifications/{noticeId}/read', [CommunicationController::class, 'read'])->whereNumber('noticeId');
            Route::post('/notifications/{noticeId}/feedback', [CommunicationController::class, 'feedback'])->whereNumber('noticeId')->middleware('throttle:notice-feedback');
            Route::get('/support/current', [CommunicationController::class, 'current']);
            Route::post('/support', [CommunicationController::class, 'open'])->middleware('throttle:support-customer');
            Route::get('/support/{conversationId}', [CommunicationController::class, 'show'])->whereNumber('conversationId');
            Route::post('/support/{conversationId}/messages', [CommunicationController::class, 'message'])->whereNumber('conversationId')->middleware('throttle:support-customer');
            Route::post('/support/{conversationId}/actions', [CommunicationController::class, 'action'])->whereNumber('conversationId')->middleware('throttle:support-customer');
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
                Route::patch('/{orderId}/recipient', [OrderController::class, 'updateRecipient'])->whereNumber('orderId');
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
            Route::get('/reservations/{reservationId}', [CustomerReservationController::class, 'show'])->whereNumber('reservationId');
            Route::post('/reservations', [CustomerReservationController::class, 'store']);
            Route::put('/reservations/{reservationId}', [CustomerReservationController::class, 'update'])->whereNumber('reservationId');
            Route::post('/reservations/{reservationId}/cancel', [CustomerReservationController::class, 'cancel'])->whereNumber('reservationId');
        });
    });

    // Webhooks nhận kết quả từ Cổng thanh toán (VietQR/SePay/PayOS)
    Route::post('/webhooks/payment-callback', [PaymentController::class, 'handleCallback'])->middleware('throttle:60,1');

    // PHẦN D: MỘC LIVE DINING — public QR ordering at the table (ADR-020)
    Route::prefix('public/dine/{tableToken}')
        ->where(['tableToken' => '[A-Za-z0-9]{32,64}'])
        ->group(function () {
            Route::get('/', [GuestTableController::class, 'show'])->middleware('throttle:dine-read');
            Route::post('/check-in', [GuestTableController::class, 'checkIn'])->middleware('throttle:dine-entry');
            Route::post('/check-in/cancel', [GuestTableController::class, 'cancelCheckIn'])->middleware('throttle:dine-entry');
            Route::post('/join', [GuestTableController::class, 'join'])->middleware('throttle:dine-entry');
            Route::post('/orders', [GuestOrderController::class, 'store'])->middleware('throttle:dine-order');
            Route::post('/service-requests', [GuestServiceRequestController::class, 'store'])->middleware('throttle:dine-service');
            Route::post('/service-requests/{requestId}/cancel', [GuestServiceRequestController::class, 'cancel'])
                ->whereNumber('requestId')
                ->middleware('throttle:dine-service');
        });

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
        Route::post('/sessions/{session}/write-off', [BillingController::class, 'writeOff'])->middleware('role:ADMIN');
        Route::post('/sessions/{session}/finish-without-charge', [BillingController::class, 'finishWithoutCharge']);
        Route::post('/reservations/{reservation}/extend', [ReservationDeskController::class, 'extend']);
        Route::post('/reservations/{reservation}/no-show', [ReservationDeskController::class, 'noShow']);
        Route::post('/sessions/{session}/cancel', [PosSessionController::class, 'cancel']);
        Route::get('/sessions/{session}/history', [PosSessionController::class, 'history']);
        Route::patch('/sessions/{session}/guest-count', [PosSessionController::class, 'changeGuestCount']);
        Route::post('/sessions/{session}/tables', [PosSessionController::class, 'changeTables']);

        // Điều chỉnh món sau khi gửi bếp (ADR-024)
        Route::post('/order-items/{item}/void', [ItemCorrectionController::class, 'void']);
        Route::post('/order-items/{item}/waive', [ItemCorrectionController::class, 'waive']);
        Route::post('/order-items/{item}/remake', [ItemCorrectionController::class, 'remake']);
        Route::post('/order-items/{item}/exchange', [ItemCorrectionController::class, 'exchange']);
        Route::post('/order-items/{item}/split', [ItemCorrectionController::class, 'split']);
    });

    Route::prefix('staff')->middleware(['auth:sanctum', 'account.active', 'role:STAFF,ADMIN'])->group(function () {
        Route::get('/support', [SupportController::class, 'index']);
        Route::get('/support/{conversation}', [SupportController::class, 'show'])->whereNumber('conversation');
        Route::post('/support/{conversation}/messages', [SupportController::class, 'message'])->whereNumber('conversation')->middleware('throttle:support-staff');
        Route::post('/support/{conversation}/actions', [SupportController::class, 'action'])->whereNumber('conversation')->middleware('throttle:support-staff');
        Route::get('/kitchen/items', [KitchenController::class, 'index']);
        Route::patch('/kitchen/items/{item}/status', [KitchenController::class, 'updateStatus']);
        Route::post('/kitchen/items/{item}/reject', [ItemCorrectionController::class, 'reject']);
        Route::post('/kitchen/items/{item}/undo', [ItemCorrectionController::class, 'undo']);

        // Yêu cầu duyệt: STAFF gửi, ADMIN duyệt trên phiên của mình
        Route::get('/approvals', [ApprovalController::class, 'index']);
        Route::post('/approvals', [ApprovalController::class, 'store']);
        Route::post('/approvals/{approval}/cancel', [ApprovalController::class, 'cancel']);
        Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->middleware('role:ADMIN');
        Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject'])->middleware('role:ADMIN');
        Route::post('/invoices/{invoice}/payments', [BillingController::class, 'pay']);

        // Live Dining queue: QR check-ins and service requests
        Route::get('/live/queue', [LiveFloorController::class, 'queue']);
        Route::post('/live/check-ins/{visit}/accept', [LiveFloorController::class, 'acceptCheckIn'])->whereNumber('visit');
        Route::post('/live/check-ins/{visit}/reject', [LiveFloorController::class, 'rejectCheckIn'])->whereNumber('visit');
        Route::post('/live/service-requests/{serviceRequest}/acknowledge', [LiveFloorController::class, 'acknowledge'])->whereNumber('serviceRequest');
        Route::post('/live/service-requests/{serviceRequest}/complete', [LiveFloorController::class, 'complete'])->whereNumber('serviceRequest');
        Route::get('/live/tables/{table}/qr', [LiveFloorController::class, 'tableQr'])->whereNumber('table');
        Route::post('/live/sessions/{session}/join-code', [LiveFloorController::class, 'rotateJoinCode'])->whereNumber('session');
    });

    // PHẦN C: ADMIN APIS (PHÂN HỆ QUẢN TRỊ VIÊN)
    Route::prefix('admin')->middleware(['auth:sanctum', 'account.active', 'role:ADMIN'])->group(function () {
        Route::get('/support/assignees', [SupportController::class, 'assignees']);
        Route::get('/communications/deliveries', [SupportController::class, 'deliveries']);
        Route::post('/communications/deliveries/{delivery}/retry', [SupportController::class, 'retryDelivery'])->whereNumber('delivery')->middleware('throttle:email-retry');
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
        Route::patch('/orders/{orderId}/recipient', [AdminOrderController::class, 'updateRecipient'])->whereNumber('orderId');
        Route::get('/orders/{orderId}/history', [AdminOrderController::class, 'history'])->whereNumber('orderId');
        Route::get('/payments', [AdminPaymentController::class, 'index']);
        Route::post('/payments/reconcile', [AdminPaymentController::class, 'reconcile']);
        Route::get('/payments/callback-logs', [AdminPaymentController::class, 'callbackLogs']);
        Route::post('/payments/{paymentId}/refund', [AdminPaymentController::class, 'refund'])->whereNumber('paymentId');
        Route::post('/payments/{paymentId}/confirm', [AdminPaymentController::class, 'confirm'])->whereNumber('paymentId');
        Route::get('/payments/transactions', [AdminPaymentController::class, 'transactions']);
        Route::post('/payments/transactions/{transactionId}/refunds', [AdminPaymentController::class, 'refundTransaction'])->whereNumber('transactionId');
        Route::post('/payments/transactions/{transactionId}/allocate', [AdminPaymentController::class, 'allocateTransaction'])->whereNumber('transactionId');
        Route::post('/payments/transactions/{transactionId}/resolve', [AdminPaymentController::class, 'resolveTransaction'])->whereNumber('transactionId');

        // B.4. Bàn ăn, đặt bàn và báo cáo
        Route::get('/tables', [AdminTableController::class, 'index']);
        Route::post('/tables', [AdminTableController::class, 'store']);
        Route::put('/tables/{tableId}', [AdminTableController::class, 'update'])->whereNumber('tableId');
        Route::post('/tables/{tableId}/qr-token', [AdminTableController::class, 'rotateQrToken'])->whereNumber('tableId');
        Route::get('/reservations', [AdminReservationController::class, 'index']);
        Route::patch('/reservations/{reservationId}/status', [AdminReservationController::class, 'updateStatus'])->whereNumber('reservationId');
        Route::get('/reservations/{reservationId}', [AdminReservationController::class, 'show'])->whereNumber('reservationId');
        Route::put('/reservations/{reservationId}', [AdminReservationController::class, 'update'])->whereNumber('reservationId');
        Route::get('/reservations/{reservationId}/history', [AdminReservationController::class, 'history'])->whereNumber('reservationId');
        Route::get('/reports/dashboard', [AdminReportController::class, 'dashboard']);
    });

    // Authenticated user test route
    Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
        return $request->user();
    });
});
