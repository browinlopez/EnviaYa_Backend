<?php

use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\FavoriteController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // User profile management & addresses
    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']); // List: GET /users
        Route::post('/show', [UserController::class, 'show']);
        Route::put('/update', [UserController::class, 'update']);
        Route::delete('/delete', [UserController::class, 'desactivate']);
        Route::post('/addresses', [UserController::class, 'getAddresses']);
        Route::post('/addresses/add', [UserController::class, 'addAddress']);
        Route::post('/buyer', [UserController::class, 'getBuyerProfile']);
    });

    // Favorites
    Route::prefix('favorites')->group(function () {
        Route::post('/', [FavoriteController::class, 'myFavorites']); // List: POST /favorites
        Route::post('toggle', [FavoriteController::class, 'toggleFavorite']);
    });
});
