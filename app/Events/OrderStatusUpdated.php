<?php

namespace App\Events;

use App\Models\Order\OrdersSales;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * EL PEDIDO CAMBIÓ DE ESTADO
 *
 * Sin esto, la app del comprador no tenía forma de enterarse: no había evento,
 * ni sondeo, ni recarga al volver a la pantalla. La tienda marcaba "listo para
 * recoger" y el domiciliario "en camino", y el seguimiento seguía diciendo
 * "pedido recibido" hasta que la persona cerraba la app por completo y volvía
 * a abrirla. El domiciliario llegaba a la puerta mientras la pantalla aseguraba
 * que la tienda estaba preparando el pedido.
 *
 * Reutiliza el canal privado `order.{id}` que ya existía para los avisos de
 * pago, y que solo autoriza al comprador dueño de la orden.
 */
/*
 * SE EMITE EN EL ACTO, NO POR LA COLA.
 *
 * Con `ShouldBroadcast` el evento se guarda como job y espera a que un worker
 * lo saque. En este proyecto `QUEUE_CONNECTION=database` y no hay worker
 * corriendo de forma fiable: al probarlo había 25 jobs sin tocar en la tabla,
 * o sea que estos avisos se anunciaban a nadie y las pantallas se quedaban
 * quietas igual que antes de existir el canal.
 *
 * Es la razón de que el chat sí funcionara: `MessageSent` ya era
 * `ShouldBroadcastNow`.
 *
 * La carga es un puñado de campos contra Reverb en la misma máquina; lo que
 * costaría de más en la petición no se acerca al retraso de depender de una
 * cola que puede estar parada.
 */
class OrderStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public OrdersSales $order)
    {
    }

    /**
     * Dos canales, no uno.
     *
     * `order.{id}` es el del pedido concreto: lo escucha quien lo está
     * siguiendo con la pantalla abierta. Pero el cambio de estado también le
     * importa a quien NO tiene ese pedido en pantalla —la tienda mirando su
     * lista, el domiciliario esperando trabajo— y esos no pueden suscribirse a
     * un pedido por pedido. Por eso va también al canal del negocio.
     *
     * El caso que lo hace evidente: la tienda acepta un pedido y pasa a "listo
     * para recoger". Ningún domiciliario tiene ese pedido abierto todavía
     * —acaba de aparecer para ellos— así que sin el canal del negocio no se
     * entera nadie hasta que alguno refresque a mano.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('order.' . $this->order->orderSales_id),
            new PrivateChannel('business.' . $this->order->busines_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->orderSales_id,
            'state'    => (int) $this->order->state,
            // El sello que la pantalla de seguimiento necesita para el
            // cronómetro: sin él tendría que volver a pedir el pedido entero
            // solo para saber desde cuándo cuenta.
            'dispatched_at' => $this->order->dispatched_at,
            // Quién lo lleva. Cambia justo en la transición a "en camino", que
            // es cuando la app quiere pintar el nombre y el mapa.
            'domiciliary_id' => $this->order->domiciliary_id,
            // Para quien escucha por el canal del negocio y no por el del
            // pedido: sin esto no sabría de qué tienda viene el aviso.
            'business_id' => $this->order->busines_id,
        ];
    }
}
