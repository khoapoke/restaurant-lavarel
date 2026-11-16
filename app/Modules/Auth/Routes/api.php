<?php

use App\Modules\Auth\Http\Controllers\Admin\AccountController as AdminAccountController;
use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\Customer\ProfileController;
use Illuminate\Support\Facades\Route;

// 1. Shared Authentication APIs
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// 2. Customer Profile APIs
Route::prefix('customer')->middleware(['auth:sanctum', 'account.active', 'role:CUSTOMER'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/password', [ProfileController::class, 'changePassword']);
});

// 3. Admin Account Management APIs
Route::prefix('admin/accounts')->middleware(['auth:sanctum', 'account.active', 'role:ADMIN'])->group(function () {
    Route::get('/', [AdminAccountController::class, 'index']);
    Route::post('/staff', [AdminAccountController::class, 'storeStaff']);
    Route::get('/{accountId}', [AdminAccountController::class, 'show'])->whereNumber('accountId');
    Route::put('/{accountId}', [AdminAccountController::class, 'update'])->whereNumber('accountId');
    Route::patch('/{accountId}/status', [AdminAccountController::class, 'updateStatus'])->whereNumber('accountId');
    Route::patch('/{accountId}/lock', [AdminAccountController::class, 'lock'])->whereNumber('accountId');
    Route::patch('/{accountId}/unlock', [AdminAccountController::class, 'unlock'])->whereNumber('accountId');
});

