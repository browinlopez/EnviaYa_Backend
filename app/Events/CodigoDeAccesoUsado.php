<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * EL CÓDIGO DE ENTRADA YA SE USÓ.
 *
 * Desde que el código es de un solo uso, la app del domiciliario se quedaba
 * enseñando un QR con su cuenta atrás corriendo después de que el celador lo
 * escaneara. El código ya no valía y la pantalla decía que sí: si el celador
 * tenía que volver a verificar —recargó, se equivocó de campo, entró otro
 * domiciliario en medio— el repartidor le enseñaba algo que el sistema
 * rechazaba, y la culpa parecía del sistema.
 *
 * Va por el canal PERSONAL y no por el del negocio: le importa a una sola
 * persona. Por el canal del negocio se lo enseñaría a los cinco repartidores
 * de esa tienda como si fuera de cada uno.
 *
 * `ShouldBroadcastNow` por el mismo motivo que `AvisoPersonal`: en este
 * proyecto la cola es `database` y no hay worker fiable, así que un evento
 * encolado se anuncia a nadie. Y éste no sirve de nada dos minutos tarde: es
 * para la persona que está en la puerta ahora mismo.
 */
class CodigoDeAccesoUsado implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public string $conjunto,
        public int $pedidos,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.' . $this->userId);
    }

    public function broadcastAs(): string
    {
        return 'codigo.usado';
    }

    /**
     * Se manda el conjunto y cuántos pedidos se le contaron.
     *
     * No es adorno: con eso la pantalla puede decir «entraste a Villa Carolina
     * con 2 pedidos» en vez de un «este código ya se usó» a secas, que deja al
     * repartidor sin saber si fue él quien acaba de entrar o alguien usó su
     * código.
     */
    public function broadcastWith(): array
    {
        return [
            'conjunto' => $this->conjunto,
            'pedidos'  => $this->pedidos,
            'cuando'   => now()->toIso8601String(),
        ];
    }
}
