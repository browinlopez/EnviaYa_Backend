<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * EN LA PORTERÍA NO LO DEJARON ENTRAR.
 *
 * El celador escaneó el código y el sistema dijo que no: el domiciliario no
 * lleva pedidos en camino a este conjunto, o la plataforma lo desactivó.
 * Antes el código se gastaba igual y no se le avisaba de nada: su app seguía
 * enseñando un QR «vigente» que ya no servía, y él no sabía por qué no
 * pasaba.
 *
 * Ahora el código NO se gasta al rechazar --no dio entrada a nadie-- y su app
 * se entera del motivo para enseñárselo. Va por el canal personal, como
 * `CodigoDeAccesoUsado`, y sin cola por el mismo motivo: sirve ahora, en la
 * puerta, o no sirve.
 */
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
