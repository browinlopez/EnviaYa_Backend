<?php

namespace App\Services;

use App\Events\OrderCreated;
use App\Models\Order\OrdersSales;
use Illuminate\Support\Facades\Log;

/**
 * QUE LA TIENDA SE ENTERE DE QUE LE ENTRÓ UN PEDIDO.
 *
 * EL FALLO QUE ESTO CIERRA. Un pedido nuevo solo emitía `OrderCreated` por
 * websocket, al canal privado del negocio. El websocket únicamente entrega si
 * la app está ABIERTA y conectada, así que con el teléfono en el bolsillo el
 * tendero no se enteraba de nada.
 *
 * Y es el aviso más grave de todos los que faltaban. Un comprador sin aviso se
 * impacienta, pero su pedido se está preparando igual; un TENDERO sin aviso no
 * prepara nada, y el pedido se queda quieto hasta que a alguien se le ocurre
 * abrir la aplicación. Es el único aviso del sistema del que depende que el
 * pedido llegue a existir de verdad.
 *
 * POR QUÉ ES UN SERVICIO Y NO DOS LÍNEAS EN CADA SITIO. Un pedido pasa a
 * existir para la tienda por DOS caminos, y los dos tienen que avisar igual:
 *
 *   · contra entrega, al crearlo (`OrderController::store`);
 *   · con pago en línea, cuando la pasarela confirma (`ConfirmacionDePago`),
 *     que puede ser minutos después y con la app del comprador ya cerrada.
 *
 * Repetido en los dos, tarde o temprano uno se queda sin el push y el fallo
 * solo aparece en la mitad de los pedidos —los pagados con tarjeta—, que es la
 * clase de avería que tarda semanas en atribuirse a algo.
 */
class AvisoDePedidoNuevo
{
    public static function anunciar(OrdersSales $order): void
    {
        $order->loadMissing('buyer.user', 'business.owners');

        /*
         * El websocket primero: es lo que pinta el pedido en la lista de quien
         * ya tiene la pantalla abierta, y no debe esperar al push.
         */
        try {
            broadcast(new OrderCreated($order));
        } catch (\Throwable $e) {
            // El pedido ya está guardado: la tienda lo verá al refrescar.
            // Perder el aviso es molesto; perder el pedido, no.
            Log::warning('No se pudo anunciar el pedido nuevo', [
                'order_id' => $order->orderSales_id,
                'error'    => $e->getMessage(),
            ]);
        }

        $mensaje = 'Pedido nuevo #' . $order->orderSales_id
            . ' por $' . number_format((float) $order->total, 0, ',', '.') . '.';

        /*
         * A CADA DUEÑO, y no solo al primero: un negocio puede tener varios, y
         * dejar fuera a los demás significa que quien esté de turno no recibe
         * nada. `Avisos::para` se encarga de las tres vías —la fila de la
         * campana, el aviso personal y el push—, así que aquí no se decide
         * cómo llega, solo a quién.
         */
        foreach ($order->business?->owners ?? [] as $duenio) {
            if (!$duenio->user_id) {
                continue;
            }

            Avisos::para(
                $duenio->user_id,
                'pedido_nuevo',
                $mensaje,
                ['order_id' => $order->orderSales_id],
            );
        }
    }
}
