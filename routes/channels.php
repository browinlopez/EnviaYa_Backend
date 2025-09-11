<?php

use App\Models\Chat\ChatParticipant;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;

    Broadcast::channel('chat.{chatId}', function ($user, $chatId) {
        return ChatParticipant::where('chat_id', $chatId)
            ->where('user_id', $user->user_id)
            ->exists();
    });
});
