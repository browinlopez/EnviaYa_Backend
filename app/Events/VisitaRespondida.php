<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * «EL RESIDENTE YA CONTESTÓ», AL INSTANTE.
 *
 * Antes el panel del celador preguntaba cada tres segundos si había
 * respuesta. Ahora el servidor la anuncia en cuanto llega, por el canal
 * personal de cada interesado:
 *
 *  · el CELADOR que preguntó, para que el panel la muestre sin esperar;
 *  · los RESIDENTES avisados, para que a los demás del apartamento se les
 *    quite la tarjeta y no contesten algo que ya se contestó.
 *
 * Es un extra. Si Reverb no está, el panel sigue preguntando (más despacio)
 * y la app se entera al contestar; y el celador siempre puede llamar al
 * citófono y dejar pasar igual.
 *
 * `Now` y no en cola: es una respuesta que alguien está esperando en la
 * puerta, no tiene sentido que haga fila detrás de otros trabajos.
 */
class VisitaRespondida implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<int>  $destinatarios  user_id de quienes deben enterarse.
     */
    public function __construct(
        public array $destinatarios,
        public int $solicitudId,
        public string $estado,
        public ?int $respondioId,
        public ?string $respondioNombre,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(
            fn (int $id) => new PrivateChannel('App.Models.User.'.$id),
            array_values(array_unique($this->destinatarios)),
        );
    }

    public function broadcastAs(): string
    {
        return 'visita.respondida';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->solicitudId,
            'state' => $this->estado,
            'answered_by' => $this->respondioId,
            'answered_by_name' => $this->respondioNombre,
        ];
    }
}
