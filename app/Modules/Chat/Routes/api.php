<?php

use App\Modules\Chat\Http\Controllers\Customer\CustomerChatController;
use App\Modules\Chat\Http\Controllers\Staff\StaffChatController;
use Illuminate\Support\Facades\Route;

// 1. Customer Chat APIs (CHT-01 -> CHT-05)
Route::prefix('customer/chat')->middleware(['auth:sanctum', 'account.active', 'role:CUSTOMER'])->group(function () {
    Route::get('/sessions', [CustomerChatController::class, 'index']);
    Route::post('/sessions', [CustomerChatController::class, 'store']);
    Route::get('/sessions/active', [CustomerChatController::class, 'active']);
    Route::get('/sessions/{id}', [CustomerChatController::class, 'show'])->whereNumber('id');
    Route::post('/sessions/{id}/messages', [CustomerChatController::class, 'sendMessage'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('/sessions/{id}/request-staff', [CustomerChatController::class, 'requestStaff'])->whereNumber('id');
    Route::post('/sessions/{id}/close', [CustomerChatController::class, 'close'])->whereNumber('id');
});

// 2. Staff / Admin Chat Operations APIs (CHT-06 -> CHT-11)
Route::prefix('staff/chat')->middleware(['auth:sanctum', 'account.active', 'role:STAFF,ADMIN'])->group(function () {
    Route::get('/sessions', [StaffChatController::class, 'index']);
    Route::get('/sessions/{id}', [StaffChatController::class, 'show'])->whereNumber('id');
    Route::get('/pending', [StaffChatController::class, 'pending']);
    Route::post('/sessions/{id}/assign', [StaffChatController::class, 'assign'])->whereNumber('id');
    Route::post('/sessions/{id}/messages', [StaffChatController::class, 'sendMessage'])->whereNumber('id');
    Route::post('/sessions/{id}/close', [StaffChatController::class, 'close'])->whereNumber('id');
});

