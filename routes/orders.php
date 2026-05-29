<?php

use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Orders
    Route::post('user', [OrderController::class, 'ordersUser']);
    Route::post('business', [OrderController::class, 'ordersBusiness']);
    Route::post('IncomeBusiness', [OrderController::class, 'incomeBusiness']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::put('update', [OrderController::class, 'updateStatus']);
    Route::post('geolocation', [OrderController::class, 'storeGeolocation']);
    Route::get('geolocation/latest', [OrderController::class, 'latest']);
    Route::get('/pending-review', [OrderController::class, 'ordersPendingReview']);

    // Payment Info
    Route::get('paymentMethods', [OrderController::class, 'paymentMethods']);
    Route::get('paymentForms', [OrderController::class, 'paymentForms']);
});
