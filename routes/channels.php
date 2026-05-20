<?php

use App\Models\ChatParticipant;
use Illuminate\Support\Facades\Broadcast;

// routes/channels.php
Broadcast::routes([
    'middleware' => ['auth:sanctum'],
]);


Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('chat.{chatId}', function ($user, $chatId) {
    return true; // permitir a todos, solo para test
});

