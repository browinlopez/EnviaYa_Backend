<?php

use App\Models\Chat\ChatParticipant;
use App\Models\Order\OrdersSales;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

// routes/channels.php
Broadcast::routes([
    'middleware' => ['auth:sanctum'],
]);


/*
 * El canal personal de cada quien.
 *
 * Comparaba `$user->id`, y la tabla `user` NO tiene columna `id` —su clave es
 * `user_id`—, así que la comparación era siempre null contra un número y la
 * autorización fallaba siempre. Nadie lo notó porque todavía no hay nada que
 * emita por este canal.
 */
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->user_id === (int) $id;
});

/*
 * La conversación de un pedido: solo quienes participan en ella.
 *
 * Decía `return true` con un comentario que confesaba "permitir a todos, solo
 * para test". Con eso, cualquiera con una sesión válida podía escuchar el chat
 * de cualquier pedido probando identificadores: los mensajes entre un
 * comprador y su tendero, en vivo.
 *
 * `ChatParticipant` ya estaba importado en este archivo y sin usar, que era
 * justamente la pieza que faltaba.
 */
Broadcast::channel('chat.{chatId}', function ($user, $chatId) {
    return ChatParticipant::where('chat_id', $chatId)
        ->where('user_id', $user->user_id)
        ->exists();
});

/*
 * El canal del pedido: los TRES que intervienen en él.
 *
 * Por acá viajan el cambio de estado, el resultado del cobro y la ubicación
 * del domiciliario. Antes solo se autorizaba al comprador, y eso dejaba fuera
 * a los otros dos protagonistas: el tendero no podía enterarse en vivo de que
 * le entró un pedido, y el domiciliario no podía seguir el suyo.
 *
 * Se comprueba pertenencia, no rol: da igual con qué papel entre alguien a la
 * aplicación, lo que decide es si ESTE pedido es suyo.
 */
Broadcast::channel('order.{orderSalesId}', function ($user, $orderSalesId) {
    $order = OrdersSales::with(['buyer', 'domiciliary', 'business.owners'])
        ->find($orderSalesId);

    if (!$order) {
        return false;
    }

    $suyo = (int) $user->user_id;

    // El comprador que lo pidió.
    if ($order->buyer && (int) $order->buyer->user_id === $suyo) {
        return true;
    }

    // El domiciliario que lo lleva. Cambia al despachar, así que antes de eso
    // no hay ninguno y esta comprobación simplemente no aplica.
    if ($order->domiciliary && (int) $order->domiciliary->user_id === $suyo) {
        return true;
    }

    // El tendero del negocio. Una tienda puede tener varios dueños.
    foreach ($order->business?->owners ?? [] as $duenio) {
        if ((int) $duenio->user_id === $suyo) {
            return true;
        }
    }

    return false;
});


/*
 * EL CANAL DEL NEGOCIO
 *
 * Por acá viaja lo que todavía no tiene pedido al que agarrarse: sobre todo,
 * que ENTRÓ uno nuevo.
 *
 * El canal `order.{id}` no sirve para eso, y no es un detalle: para escucharlo
 * hay que saber el número del pedido, y un pedido que aún no existe no tiene
 * número. Sin este canal, la tienda solo se enteraba de un pedido nuevo al
 * entrar a la pantalla o tirando hacia abajo —hasta minutos de retraso con la
 * comida enfriándose— y ningún domiciliario sabía que había trabajo hasta que
 * alguno refrescaba por su cuenta.
 *
 * Escuchan los dos, y por eso es UN canal y no dos: el domiciliario saca sus
 * pedidos disponibles del mismo negocio (`getOrderStore(business_id)`), así que
 * tienda y repartidores necesitan exactamente la misma información.
 */
Broadcast::channel('business.{businessId}', function ($user, $businessId) {
    $suyo = (int) $user->user_id;

    // Los dueños de la tienda. Una tienda puede tener varios.
    $esDuenio = DB::table('owner_busines as ob')
        ->join('owner as o', 'o.owner_id', '=', 'ob.owner_id')
        ->where('o.user_id', $suyo)
        ->where('ob.busines_id', $businessId)
        ->exists();

    if ($esDuenio) {
        return true;
    }

    // Los domiciliarios asignados a esa tienda. No se comprueba el rol sino la
    // pertenencia: lo que decide es si ESTE negocio es suyo.
    return DB::table('business_domiciliary as bd')
        ->join('domiciliary as d', 'd.domiciliary_id', '=', 'bd.domiciliary_id')
        ->where('d.user_id', $suyo)
        ->where('bd.busines_id', $businessId)
        ->exists();
});
