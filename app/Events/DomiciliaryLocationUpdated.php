<?php

namespace App\Events;

use App\Models\Order\OrderGeolocation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

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

    /** La tienda de ese pedido, para la torre de control de su flota. */
    public ?int $businessId;

    public function __construct(OrderGeolocation $geolocation)
    {
        $this->geolocation = $geolocation;

        /*
         * Se resuelve acá y no en quien emite: así el segundo canal viaja
         * siempre, lo dispare quien lo dispare. Es una lectura de una columna
         * por índice primario cada veinte segundos por pedido en curso.
         */
        $this->businessId = DB::table('orderssales')
            ->where('orderSales_id', $geolocation->orderSales_id)
            ->value('busines_id');

        $this->businessId = $this->businessId === null ? null : (int) $this->businessId;
    }

    /**
     * DOS CANALES, y cada uno por un motivo distinto.
     *
     * `order.{id}` es el del comprador que está mirando su mapa: ese canal lo
     * autoriza solo quien pidió ese pedido.
     *
     * `negocio.{id}.torre` es el de la tienda, para que la torre de control se
     * mueva sola en vez de preguntar cada pocos segundos.
     *
     * NO se usa el canal `business.{id}` que ya existía, aunque fuera gratis:
     * ese lo escuchan también los domiciliarios de la tienda —es como se
     * enteran de que entró trabajo— y meter ahí las coordenadas le daría a
     * cada uno la posición en vivo de sus compañeros. Una cosa es que el
     * tendero vea su flota y otra muy distinta que la flota se vea entre sí.
     */
    public function broadcastOn()
    {
        $canales = [new PrivateChannel('order.'.$this->geolocation->orderSales_id)];

        if ($this->businessId !== null) {
            $canales[] = new PrivateChannel('negocio.'.$this->businessId.'.torre');
        }

        return $canales;
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
            // Para la torre, que pinta una moto por domiciliario y no por
            // pedido: sin esto no sabría cuál de los pines mover.
            'domiciliary_id' => (int) $this->geolocation->domiciliary_id,
            'latitude'  => (float) $this->geolocation->latitude,
            'longitude' => (float) $this->geolocation->longitude,
            'at'        => optional($this->geolocation->created_at)->toIso8601String(),
        ];
    }
}
