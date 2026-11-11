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
});

// 2. Customer Profile APIs
Route::prefix('customer')->middleware(['auth:sanctum', 'account.active', 'role:CUSTOMER'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
});

// 3. Admin Account Management APIs
Route::prefix('admin/accounts')->middleware(['auth:sanctum', 'account.active', 'role:ADMIN'])->group(function () {
    Route::get('/', [AdminAccountController::class, 'index']);
    Route::patch('/{accountId}/lock', [AdminAccountController::class, 'lock'])->whereNumber('accountId');
    Route::patch('/{accountId}/unlock', [AdminAccountController::class, 'unlock'])->whereNumber('accountId');
});

