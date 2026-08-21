<?php

namespace App\Events;

use App\Models\Chat\ChatParticipant;
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

    /** Participantes del chat menos el autor. */
    public $destinatarios = [];

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

        // Se resuelve acá y no en `broadcastOn()` porque ese método puede
        // llamarse más de una vez y esto es una consulta a la base.
        $this->destinatarios = ChatParticipant::where('chat_id', $this->message->chat_id)
            ->where('user_id', '!=', $this->message->user_id)
            ->pluck('user_id')
            ->all();
    }

    /*
     * Dos destinos: la conversación abierta y la bandeja de quien no la tiene
     * abierta.
     *
     * El canal del chat solo lo escucha quien está DENTRO de esa conversación.
     * La pantalla de Mensajes, que es la lista de todas, no puede escucharlo:
     * tendría que suscribirse a un canal por chat y aun así se perdería el
     * primer mensaje de un cliente nuevo —ese chat todavía no está en la lista,
     * así que no hay canal al que suscribirse—. Por eso el mensaje viaja
     * también al canal personal de cada participante.
     *
     * Se excluye al autor: ya tiene el mensaje en pantalla, y recibirlo de
     * vuelta le subiría el contador de no leídos de su propio mensaje.
     */
    public function broadcastOn(): array
    {
        $canales = [new PrivateChannel('chat.' . $this->message->chat_id)];

        foreach ($this->destinatarios as $userId) {
            $canales[] = new PrivateChannel('App.Models.User.' . $userId);
        }

        return $canales;
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
