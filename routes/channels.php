<?php

use App\Models\ChatParticipant;
use App\Models\OrderSale;
use Illuminate\Support\Facades\Broadcast;

// routes/channels.php
Broadcast::routes([
    'middleware' => ['auth:sanctum'],
]);


Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('chat.{chatId}', function ($user, $chatId) {
    // Aquí puedes agregar validación para comprobar si el usuario es participante del chat
    // Ejemplo: return ChatParticipant::where('chat_id', $chatId)->where('user_id', $user->id)->exists();
    return true; // Permitido por defecto para pruebas, ajusta a tus necesidades de negocio
});

Broadcast::channel('gps.order.{orderId}', function ($user, $orderId) {
    // Ejemplo de validación: Sólo el comprador, el repartidor o un admin pueden escuchar
    // $order = OrderSale::find($orderId);
    // return $order && ($user->id === $order->buyer_id || $user->id === $order->domiciliary_id);
    return true; // Permitido por defecto para pruebas, ajusta la lógica a tus roles
});
