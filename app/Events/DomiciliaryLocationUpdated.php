<?php

namespace App\Events;

use App\Models\Order\OrderGeolocation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * DÓNDE VA EL DOMICILIARIO
 *
 * El canal es PRIVADO, como los otros tres eventos del pedido.
 *
 * Era público, y eso lo dejaba abierto de par en par: un canal público no pide
 * autorización, y la clave del websocket va compilada dentro de la app, o sea
 * que es de dominio público. Cualquiera podía suscribirse a `order.123`
 * probando números y recibir en vivo las coordenadas del domiciliario que
 * llevaba ese pedido, sin cuenta y sin permiso.
 *
 * `order.{orderSalesId}` ya estaba autorizado en routes/channels.php y solo
 * admite al comprador dueño de la orden, así que hacerlo privado no exigió
 * nada más.
 */
class DomiciliaryLocationUpdated implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public $geolocation;

    public function __construct(OrderGeolocation $geolocation)
    {
        $this->geolocation = $geolocation;
    }

    // Un canal por pedido: quien sigue el suyo no ve el de nadie más.
    public function broadcastOn()
    {
        return new PrivateChannel('order.'.$this->geolocation->orderSales_id);
    }

    public function broadcastAs()
    {
        return 'location.updated';
    }

    /**
     * Solo lo que el mapa necesita.
     *
     * Sin este método Laravel serializa la propiedad pública entera, y el
     * cliente recibía el registro completo —con sus identificadores internos y
     * sus fechas— envuelto en `geolocation`. Acá se declara qué se publica, que
     * además es lo que hace el contrato estable: cambiar una columna de la
     * tabla deja de cambiar lo que reciben los teléfonos.
     */
    public function broadcastWith(): array
    {
        return [
            'order_id'  => (int) $this->geolocation->orderSales_id,
            'latitude'  => (float) $this->geolocation->latitude,
            'longitude' => (float) $this->geolocation->longitude,
            'at'        => optional($this->geolocation->created_at)->toIso8601String(),
        ];
    }
}
