<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * UN AVISO DIRIGIDO A UNA PERSONA
 *
 * El canal `App.Models.User.{id}` estaba declarado y autorizado desde el
 * principio, y no emitía nada. La pantalla de notificaciones de la app tenía la
 * lista escrita a mano vacía.
 *
 * Existe porque hay avisos que los otros canales no pueden llevar:
 *
 *  · `order.{id}` es del pedido, y lo escuchan sus tres protagonistas.
 *  · `business.{id}` es de la tienda, y lo escuchan el tendero y TODOS sus
 *    domiciliarios.
 *
 * "Te asignaron esta entrega" le importa a una sola persona. Mandarlo por el
 * canal del negocio se lo enseñaría a los cinco repartidores como si fuera de
 * cada uno.
 *
 * El aviso se guarda antes de emitirse, así que la campana tiene historial y
 * sigue ahí después de cerrar la app. Sin eso sería un aviso que solo existe si
 * estabas mirando en ese instante.
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
class AvisoPersonal implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Notification $aviso)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.' . $this->aviso->user_id);
    }

    public function broadcastAs(): string
    {
        return 'aviso.personal';
    }

    public function broadcastWith(): array
    {
        return [
            'notification_id' => $this->aviso->notification_id,
            'tipo'            => $this->aviso->tipo,
            'message'         => $this->aviso->message,
            'datos'           => $this->aviso->datos,
            'read'            => (bool) $this->aviso->read,
            'date'            => (string) $this->aviso->date,
        ];
    }
}
