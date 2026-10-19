<?php

use App\Http\Controllers\Api\AuthController;
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
    });

    // Authenticated user test route
    Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
        return $request->user();
    });
});
