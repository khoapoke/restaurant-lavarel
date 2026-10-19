<?php

use App\Http\Controllers\Api\Admin\AccountController as AdminAccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Customer\CartController;
use App\Http\Controllers\Api\Customer\MenuController;
use App\Http\Controllers\Api\Customer\ProfileController;
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
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
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
        });
    });

    // PHẦN B: ADMIN APIS (PHÂN HỆ QUẢN TRỊ VIÊN)
    Route::prefix('admin')->middleware(['auth:sanctum', 'account.active', 'role:ADMIN'])->group(function () {
        // B.1. Quản lý Người dùng
        Route::get('/accounts', [AdminAccountController::class, 'index']);
        Route::patch('/accounts/{accountId}/lock', [AdminAccountController::class, 'lock']);
        Route::patch('/accounts/{accountId}/unlock', [AdminAccountController::class, 'unlock']);
    });

    // Authenticated user test route
    Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
        return $request->user();
    });
});
