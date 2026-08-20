<?php

namespace App\Events;

use App\Models\Order\OrdersSales;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
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
class OrderStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public OrdersSales $order)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('order.' . $this->order->orderSales_id);
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
        ];
    }
}
