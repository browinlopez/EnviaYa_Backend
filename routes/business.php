<?php

use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\AffiliationController;
use App\Http\Controllers\Api\AdminOwnerController as OwnerController;
use Illuminate\Support\Facades\Route;

// Public business routes
Route::get('top-businesses-free', [BusinessController::class, 'indexByQualification']);
Route::get('type-organizations', [\App\Http\Controllers\Api\TypeOrganizationController::class, 'index']);

// Protected business routes
Route::middleware('auth:sanctum')->group(function () {
    // Businesses
    Route::get('business', [BusinessController::class, 'index']); // List: GET /businesses
    Route::post('business', [BusinessController::class, 'store']); // Create: POST /businesses
    Route::get('top-businesses', [BusinessController::class, 'indexByQualification']);
    Route::post('business/{id}', [BusinessController::class, 'show']);
    Route::put('business/{id}', [BusinessController::class, 'update']);

    // Affiliations
    Route::prefix('affiliations')->group(function () {
        Route::post('/AfiliationUser', [AffiliationController::class, 'AfiliationUser']);
        Route::post('/getAffiliatedUsers', [AffiliationController::class, 'getAffiliatedUsers']);
        Route::post('/DesafiliationUser', [AffiliationController::class, 'DesafiliationUser']);
        Route::post('toggleBusinesses', [AffiliationController::class, 'toggle']);
        Route::get('usersBusinesses', [AffiliationController::class, 'listUsers']);
        Route::get('searchBusinesses', [AffiliationController::class, 'searchBuyerByPhone']);
    });

    // Owners
    Route::get('owners', [OwnerController::class, 'index']); // List: GET /owner
    Route::post('owners', [OwnerController::class, 'store']); // Create: POST /owner
    Route::get('owners/{id}', [OwnerController::class, 'show']);
    Route::put('owners/{id}', [OwnerController::class, 'update']);
});
