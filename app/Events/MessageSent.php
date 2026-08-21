<?php

namespace App\Events;

use App\Models\Chat\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;

    public function __construct(Message $message)
    {
        /*
         * Se relee de la base antes de emitir.
         *
         * `Message` tiene `$timestamps = false` y `created_at` no está en
         * `$fillable`: lo pone la base al insertar, así que el modelo recién
         * creado no lo conoce y el evento salía con `created_at: null`. La app
         * lo necesita para la hora del mensaje y para agrupar por día; sin él
         * mostraba "Invalid Date".
         */
        $this->message = $message->fresh() ?? $message;
        $this->message->load('user');
    }

    public function broadcastOn()
    {
        return new PrivateChannel('chat.' . $this->message->chat_id);
    }

    /*
     * Un nombre corto y estable para escuchar desde la app.
     *
     * Sin `broadcastAs`, Laravel emite el evento con el nombre completo de la
     * clase (`App\Events\MessageSent`), que ata el cliente a la ruta interna
     * del backend: mover o renombrar la clase rompería la escucha en teléfonos
     * ya instalados. El resto de eventos del proyecto ya lo hacen así.
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith()
    {
        return [
            'message_id' => $this->message->message_id,
            // El chat y la fecha los necesita la app para colocar el mensaje:
            // sin `created_at` no puede agrupar por día ni poner la hora, y
            // acababa mostrando "Invalid Date".
            'chat_id'    => $this->message->chat_id,
            'user_id'    => $this->message->user_id,
            'role_id'    => $this->message->role_id,
            'name'       => $this->message->user->name ?? null,
            'content'    => $this->message->content,
            /*
             * Sin `toDateTimeString()`: el modelo tiene `$timestamps = false` y
             * no castea `created_at`, así que al releerlo de la base llega como
             * texto y llamar al método de Carbon reventaba el evento entero
             * —el mensaje se guardaba y no se anunciaba—. Se manda tal cual, que
             * es el mismo formato que ya devuelve el listado de mensajes.
             */
            'created_at' => (string) $this->message->created_at,
        ];
    }
}
