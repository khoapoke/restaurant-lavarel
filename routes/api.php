<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modular API Routes - Restaurant System (v1)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    // 1. Auth Module (Shared Auth, Customer Profile, Admin Account Management)
    require app_path('Modules/Auth/Routes/api.php');

    // 2. Menu Module (Customer Categories/Dishes/Weather, Admin Categories/Dishes)
    require app_path('Modules/Menu/Routes/api.php');

    // 3. Cart Module (Customer Cart Management)
    require app_path('Modules/Cart/Routes/api.php');

    // 4. Order Module (Customer Orders/Checkout, Admin Orders)
    require app_path('Modules/Order/Routes/api.php');

    // 5. Payment Module (Customer QR/Status/Retry, Webhooks, Admin Payments/Refunds)
    require app_path('Modules/Payment/Routes/api.php');

    // 6. Reservation Module (Customer Available Tables/Booking, Admin Tables/Reservations)
    require app_path('Modules/Reservation/Routes/api.php');

    // 7. Report Module (Admin Dashboard & Analytics)
    require app_path('Modules/Report/Routes/api.php');

    // Authenticated user test route
    Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
        return $request->user();
    });
});
