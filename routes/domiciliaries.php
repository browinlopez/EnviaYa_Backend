<?php

use App\Http\Controllers\Api\DomiciliaryController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Domiciliaries
    Route::prefix('domiciliaries')->group(function () {
        Route::get('/', [DomiciliaryController::class, 'listDomiciliary']); // List: GET /domiciliaries
        Route::post('/', [DomiciliaryController::class, 'createDomiciliary']); // Create: POST /domiciliaries
        Route::get('/listDomiciliariesByBusiness', [DomiciliaryController::class, 'listDomiciliariesByBusiness']);
        Route::post('/show', [DomiciliaryController::class, 'showDomiciliary']);
        Route::post('/update', [DomiciliaryController::class, 'updateDomiciliary']);
        Route::post('/delete', [DomiciliaryController::class, 'deleteDomiciliary']);
        Route::post('/assignToBusiness', [DomiciliaryController::class, 'assignToBusiness']);
        Route::post('/listbussiness', [DomiciliaryController::class, 'listBusinessesByDomiciliary']);
        Route::post('/incomeDomiciliary', [DomiciliaryController::class, 'incomeDomiciliary']);
    });
});
