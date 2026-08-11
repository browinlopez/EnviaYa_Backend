<?php

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CategoryBusinessController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

// Public product & category routes
Route::get('categories-business-free', [CategoryBusinessController::class, 'index']);
Route::get('categories-free', [CategoryController::class, 'index']);
// Alias legacy: la app móvil publicada llama a esta ruta (nombre de la rama Devs).
// Debe registrarse antes del grupo protegido para ganarle a categories-business/{id}.
Route::get('categories-business/indexFree', [CategoryBusinessController::class, 'index']);

// Protected product & category routes
Route::middleware('auth:sanctum')->group(function () {
    // Products
    Route::post('products', [ProductController::class, 'store']); // Create: POST /product
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{id}', [ProductController::class, 'show']);
    Route::patch('products/{id}', [ProductController::class, 'update']);
    Route::get('products-top-rated', [ProductController::class, 'topRated']);
    Route::get('products-populars', [ProductController::class, 'mostPopularProducts']);
    Route::delete('products/{id}', [ProductController::class, 'delete']);
    // Categories
    Route::post('categories', [CategoryController::class, 'store']); // Create: POST /categories
    Route::get('categories', [CategoryController::class, 'show']);
    Route::put('categories', [CategoryController::class, 'update']);
    Route::delete('/categories', [CategoryController::class, 'destroy']);

    // Business Categories
    Route::get('categories-business', [CategoryBusinessController::class, 'index']); // List: GET /categories-business
    Route::post('categories-business', [CategoryBusinessController::class, 'store']); // Create: POST /categories-business
    Route::get('categories-business/{id}', [CategoryBusinessController::class, 'show']);
    Route::put('categories-business/{id}', [CategoryBusinessController::class, 'update']);
    Route::delete('categories-business/{id}', [CategoryBusinessController::class, 'destroy']);
});
