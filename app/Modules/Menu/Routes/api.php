<?php

use App\Modules\Menu\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Modules\Menu\Http\Controllers\Admin\DishController as AdminDishController;
use App\Modules\Menu\Http\Controllers\Customer\MenuController;
use Illuminate\Support\Facades\Route;

// 1. Customer Public Menu APIs
Route::prefix('customer')->group(function () {
    Route::get('/categories', [MenuController::class, 'categories']);
    Route::get('/dishes', [MenuController::class, 'dishes']);
    Route::get('/dishes/search', [MenuController::class, 'dishes']);
    Route::get('/dishes/recommendations/weather', [MenuController::class, 'weatherRecommendations']);
    Route::get('/recommendations/weather', [MenuController::class, 'weatherRecommendations']);
    Route::get('/dishes/{dishId}', [MenuController::class, 'dishDetail'])->whereNumber('dishId');
});

// 2. Admin Menu APIs
Route::prefix('admin')->middleware(['auth:sanctum', 'account.active', 'role:ADMIN'])->group(function () {
    // Categories Management (MNU-09 -> MNU-12)
    Route::prefix('categories')->group(function () {
        Route::get('/', [AdminCategoryController::class, 'index']);
        Route::get('/{categoryId}', [AdminCategoryController::class, 'show'])->whereNumber('categoryId');
        Route::post('/', [AdminCategoryController::class, 'store']);
        Route::put('/{categoryId}', [AdminCategoryController::class, 'update'])->whereNumber('categoryId');
        Route::patch('/{categoryId}/visibility', [AdminCategoryController::class, 'updateVisibility'])->whereNumber('categoryId');
        Route::delete('/{categoryId}', [AdminCategoryController::class, 'destroy'])->whereNumber('categoryId');
    });

    // Dishes Management (MNU-13 -> MNU-17)
    Route::prefix('dishes')->group(function () {
        Route::get('/', [AdminDishController::class, 'index']);
        Route::get('/{dishId}', [AdminDishController::class, 'show'])->whereNumber('dishId');
        Route::post('/', [AdminDishController::class, 'store']);
        Route::put('/{dishId}', [AdminDishController::class, 'update'])->whereNumber('dishId');
        Route::patch('/{dishId}/availability', [AdminDishController::class, 'updateAvailability'])->whereNumber('dishId');
        Route::patch('/{dishId}/visibility', [AdminDishController::class, 'updateVisibility'])->whereNumber('dishId');
        Route::delete('/{dishId}', [AdminDishController::class, 'destroy'])->whereNumber('dishId');
    });
});

