<?php

use App\Http\Controllers\Api\ChatController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Chats
    Route::prefix('chats')->group(function () {
        Route::post('/', [ChatController::class, 'createChat']); // Create chat: POST /chats
        Route::post('/user-chats', [ChatController::class, 'getUserChats']);
        Route::post('/messages', [ChatController::class, 'getMessages']);
        Route::post('/updateMessage', [ChatController::class, 'updateMessage']);
        Route::post('/send-message', [ChatController::class, 'sendMessage']);
    });
});
