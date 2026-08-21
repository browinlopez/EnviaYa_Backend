<?php

namespace App\Events;

use App\Models\Order\OrdersSales;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ENTRÓ UN PEDIDO NUEVO
 *
 * Es el aviso más urgente de toda la operación y era el único que no existía.
 *
 * La tienda se enteraba de un pedido nuevo solo al entrar a la pantalla o
 * tirando hacia abajo, así que podían pasar minutos con la comida esperando y
 * el cliente preguntando por chat. No había forma de arreglarlo con el canal
 * `order.{id}`: para escucharlo hay que saber el número del pedido, y un pedido
 * que aún no existe no tiene número. Por eso va al canal del negocio.
 *
 * Se manda lo justo para pintar la fila de la lista y decidir si sonar. Quien
 * quiera el detalle completo lo pide; mandar el pedido entero por el socket
 * multiplicaría el tamaño del mensaje para datos que la mayoría de las veces
 * nadie va a mirar.
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
class OrderCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public OrdersSales $order)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('business.' . $this->order->busines_id);
    }

    public function broadcastAs(): string
    {
        return 'order.created';
    }

    public function broadcastWith(): array
    {
        $comprador = $this->order->buyer?->user;

        return [
            'order_id'    => $this->order->orderSales_id,
            'business_id' => $this->order->busines_id,
            'state'       => (int) $this->order->state,
            'total'       => (float) $this->order->total,
            // Si `sale_date` viniera vacío se cae a `created_at`: la tarjeta del
            // tendero pone la hora del pedido y con una cadena vacía enseñaría
            // "Invalid Date", que es exactamente lo que ya pasó en el chat.
            'sale_date'   => (string) ($this->order->sale_date ?: $this->order->created_at),
            // El nombre del cliente es lo primero que mira el tendero para
            // saber si es un pedido que esperaba.
            'buyer_name'  => $comprador?->name,
        ];
    }
}
