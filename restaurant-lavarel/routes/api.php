<?php

use App\Http\Controllers\Api\AuthController;
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
    Route::prefix('customer')->middleware(['auth:sanctum', 'account.active', 'role:CUSTOMER'])->group(function () {
        // A.1. Quản lý Hồ sơ, Địa chỉ & Dị ứng
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
    });

    // Authenticated user test route
    Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
        return $request->user();
    });
});
