<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Sin cola: al domiciliario le sirve saber el motivo ahora, en la puerta.
class CodigoDeAccesoRechazado implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public string $motivo,
        public string $conjunto,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'codigo.rechazado';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return [
            // `sin_pedidos` o `domiciliario_inactivo`, los mismos de la portería.
            'motivo' => $this->motivo,
            'conjunto' => $this->conjunto,
            'cuando' => now()->toIso8601String(),
        ];
    }
}
