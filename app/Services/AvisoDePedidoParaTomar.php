<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order\OrdersSales;
use Illuminate\Support\Facades\Log;

/**
 * «HAY UN PEDIDO PARA TOMAR», A LOS DOMICILIARIOS DEL NEGOCIO.
 *
 * Cuando el negocio acepta un pedido (1 → 2) ese pedido aparece en «Para
 * tomar» de sus domiciliarios… si alguno abre la app y mira. El aviso de
 * pedido nuevo (`pedido_nuevo`) sólo les llega a los dueños, y en el estado 1,
 * cuando todavía no hay nada que llevar. Quien reparte se enteraba tarde o no
 * se enteraba.
 *
 * Ahora les llega a TODOS los domiciliarios vinculados y disponibles: un push
 * si tienen la app cerrada y, con la app abierta, el aviso de arriba con su
 * botón «Tomar». No es una oferta con cuenta atrás --eso se descartó: en
 * VeciPa'Ya cada negocio tiene su gente y la elige--: es un aviso. El primero
 * que toca «Tomar» se lo queda; el servidor ya bloquea la fila al despachar
 * (`OrderController::updateStatus`), así que dos a la vez no lo pueden tomar.
 *
 * A quien no está disponible --«Terminar por hoy»-- no se le avisa: es lo que
 * pidió al apagar.
 */
class AvisoDePedidoParaTomar
{
    /**
     * @param  int|null  $excepto  El user_id que NO recibe el aviso: quien
     *                             acaba de soltar el pedido no tiene por qué
     *                             ver que «hay uno para tomar» --es el suyo--.
     */
    public static function anunciar(OrdersSales $order, ?int $excepto = null): void
    {
        try {
            $order->loadMissing('business.domiciliaries');

            $negocio = $order->business;
            if (! $negocio) {
                return;
            }

            $mensaje = "Pedido #{$order->orderSales_id} listo para tomar en {$negocio->name}"
                .' · $'.number_format((float) $order->total, 0, ',', '.');

            foreach ($negocio->domiciliaries as $domiciliario) {
                if (! $domiciliario->user_id
                    || (int) $domiciliario->user_id === $excepto
                    || (int) $domiciliario->available !== 1
                    || (int) $domiciliario->state !== 1) {
                    continue;
                }

                Avisos::para(
                    $domiciliario->user_id,
                    'pedido_para_tomar',
                    $mensaje,
                    [
                        'order_id' => $order->orderSales_id,
                        'business_id' => $negocio->busines_id,
                        'negocio' => $negocio->name,
                        'total' => (float) $order->total,
                    ],
                    titulo: 'Pedido para tomar',
                );
            }
        } catch (\Throwable $e) {
            /* Un aviso que no sale no puede tumbar la aceptación del pedido:
               el pedido sigue en «Para tomar» y se ve al abrir la app. */
            Log::warning('No se pudo avisar a los domiciliarios del pedido para tomar', [
                'order_id' => $order->orderSales_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
