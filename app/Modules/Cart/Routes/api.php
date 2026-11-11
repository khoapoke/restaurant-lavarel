<?php

use App\Modules\Cart\Http\Controllers\Customer\CartController;
use Illuminate\Support\Facades\Route;

// Customer Cart APIs (CRT-01 -> CRT-04, MNU-08)
Route::prefix('customer/cart')->middleware(['auth:sanctum', 'account.active', 'role:CUSTOMER'])->group(function () {
    Route::get('/', [CartController::class, 'show']);
    Route::post('/items', [CartController::class, 'addItem']);
    Route::put('/items/{dishId}', [CartController::class, 'updateQuantity'])->whereNumber('dishId');
    Route::delete('/items/{dishId}', [CartController::class, 'removeItem'])->whereNumber('dishId');
});

