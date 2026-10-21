<?php

namespace App\Jobs;

use App\Models\Order\OrdersSales;
use App\Services\TrazoDeLaRuta;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * TRAZAR LA RUTA DEL PEDIDO AL DESPACHARLO.
 *
 * POR QUÉ EN LA COLA Y NO EN EL DESPACHO. Trazar es una llamada a Google con
 * ocho segundos de espera. Metida en el camino del botón «Despachar», el
 * tendero se queda mirando la pantalla con una moto esperando en la puerta —y
 * si Google tarda, el despacho parece roto aunque el pedido ya salió—.
 *
 * Acá el despacho termina de inmediato y la ruta llega un segundo después, que
 * es antes de que el comprador alcance a abrir su mapa. Y si no llega —no hay
 * red, falta la clave, la cola está parada— no pasa nada: la primera vez que
 * alguien pida la ruta se traza y se guarda ahí mismo.
 */
class TrazarLaRutaDelPedido implements ShouldQueue
{
    use Queueable;

    /**
     * Un reintento y para.
     *
     * Insistir no arregla lo que falla aquí —una clave que no está no se pone
     * sola— y la ruta tiene una segunda oportunidad gratis: quien la pida.
     */
    public int $tries = 2;

    public function __construct(public int $orderId)
    {
    }

    public function handle(TrazoDeLaRuta $rutas): void
    {
        $pedido = OrdersSales::with(['business', 'address'])->find($this->orderId);

        if (!$pedido) {
            return;
        }

        $rutas->paraElPedido($pedido);
    }
}
