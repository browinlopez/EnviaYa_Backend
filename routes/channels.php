<?php

use App\Models\Chat\ChatParticipant;
use App\Models\Order\OrdersSales;
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

// Solo el comprador dueño de la orden puede escuchar sus actualizaciones de pago
Broadcast::channel('order.{orderSalesId}', function ($user, $orderSalesId) {
    $order = OrdersSales::with('buyer')->find($orderSalesId);

    return $order && $order->buyer && (int) $order->buyer->user_id === (int) $user->user_id;
});

