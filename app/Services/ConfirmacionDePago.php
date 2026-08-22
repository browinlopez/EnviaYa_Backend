<?php

namespace App\Services;

use App\Events\OrderCreated;
use App\Models\Order\OrdersSales;
use Illuminate\Support\Facades\Log;

/**
 * El momento en que un intento de compra se convierte en un pedido.
 *
 * Con pago en línea el pedido se guarda ANTES de cobrar —la pasarela necesita
 * una referencia, y si el dinero llega a moverse tiene que existir dónde
 * apuntarlo: un cobro sin registro no se puede devolver ni reconciliar—. Pero
 * hasta acá no existe para nadie: no sale en la lista de la tienda, ni en la
 * del comprador, ni en la del domiciliario, ni suena el aviso.
 *
 * Vive en un servicio y no en el controlador porque la confirmación llega por
 * DOS caminos y los dos tienen que hacer exactamente lo mismo:
 *
 *  · la respuesta de la pasarela, mientras la persona mira la pantalla;
 *  · el webhook, cuando cerró la app y el cobro se resolvió después.
 *
 * El segundo es el que importa de verdad: es el que evita que alguien pague y
 * se quede sin pedido.
 */
class ConfirmacionDePago
{
    /**
     * Da el pago por bueno y, si el pedido estaba escondido, lo saca a la luz.
     *
     * @return bool `true` si este fue el momento en que pasó a existir.
     */
    public static function confirmar(OrdersSales $order): bool
    {
        $estabaEsperando = $order->esperandoPago();

        $order->payment_state = 'paid';
        $order->save();

        if (!$estabaEsperando) {
            /*
             * Ya era un pedido visible —típicamente uno contra entrega, que se
             * anunció al crearse—. Volver a emitir lo pintaría dos veces en la
             * pantalla de la tienda.
             */
            return false;
        }

        try {
            broadcast(new OrderCreated($order->load('buyer.user')));
        } catch (\Throwable $e) {
            // El pedido ya está pagado y guardado: la tienda lo verá al
            // refrescar. Perder el aviso es molesto; perder el pedido, no.
            Log::warning('No se pudo anunciar el pedido ya pagado', [
                'order_id' => $order->orderSales_id,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }
}
