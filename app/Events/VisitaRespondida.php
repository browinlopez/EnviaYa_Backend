<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// Sin cola: el celador espera la respuesta con la visita en la puerta.
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
