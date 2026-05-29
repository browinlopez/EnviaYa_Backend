<?php

use App\Http\Controllers\Api\ReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Reviews
    Route::prefix('reviews')->group(function () {
        Route::post('/', [ReviewController::class, 'store']); // Create review: POST /reviews

        // Businesses
        Route::get('business/all', [ReviewController::class, 'listBusinessReviews']);
        Route::post('business/by', [ReviewController::class, 'listReviewsByBusiness']);
        Route::post('business/create', [ReviewController::class, 'createBusinessReview']);
        Route::put('business/update', [ReviewController::class, 'updateBusinessReview']);
        Route::delete('business/delete', [ReviewController::class, 'deleteBusinessReview']);

        // Domiciliaries
        Route::get('domiciliaries/all', [ReviewController::class, 'listDomiciliaryReviews']);
        Route::post('domiciliary/by', [ReviewController::class, 'listReviewsByDomiciliary']);
        Route::post('domiciliary/create', [ReviewController::class, 'createDomiciliaryReview']);
        Route::put('domiciliary/update', [ReviewController::class, 'updateDomiciliaryReview']);
        Route::delete('domiciliary/delete', [ReviewController::class, 'deleteDomiciliaryReview']);

        // Users
        Route::get('users/all', [ReviewController::class, 'listAllUserReviews']);
        Route::get('user/by', [ReviewController::class, 'listUserReviewsByUser']);
        Route::post('user/create', [ReviewController::class, 'createUserReview']);
        Route::put('user/update', [ReviewController::class, 'updateUserReview']);
        Route::delete('user/delete', [ReviewController::class, 'deleteUserReview']);
    });
});
