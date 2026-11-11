<?php

use App\Modules\Recommendation\Http\Controllers\Customer\RecommendationController;
use Illuminate\Support\Facades\Route;

// 1. Public Graph Recommendations & Stats
Route::prefix('customer')->group(function () {
    Route::get('/dishes/{dishId}/recommendations/graph', [RecommendationController::class, 'pairedDishes'])->whereNumber('dishId');
    Route::get('/recommendations/graph-stats', [RecommendationController::class, 'graphStats']);
});

// 2. Authenticated Customer Personalized Recommendations
Route::prefix('customer')->middleware(['auth:sanctum', 'account.active', 'role:CUSTOMER'])->group(function () {
    Route::get('/recommendations/personalized', [RecommendationController::class, 'personalized']);
});

