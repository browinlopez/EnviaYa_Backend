<?php

declare(strict_types=1);

namespace App\Http\Resources\Pedido;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * LA ULTIMA POSICION CONOCIDA DE UN DOMICILIARIO.
 *
 * Era el array a mano de `ConsultaDePedidosController::lastLocation()`. Cinco
 * claves de una fila de `order_geolocations`.
 *
 * LO QUE NO SE TOCA, y conviene decirlo porque parece un descuido: cuando no
 * hay ninguna posicion guardada el metodo devuelve `[]`, un array vacio, y no
 * `null` ni un 404. Eso se queda igual. La app lo lee asi —`if
 * (data.latitude)`— y cambiarlo por `null` o por un 204 romperia el mapa de
 * seguimiento en las versiones ya publicadas.
 *
 * Y quien puede verla no se decide aqui: eso es
 * `puedeVerAlDomiciliario()`, tres relaciones que dan derecho a mirar. Un
 * Resource filtra campos, no permisos, y confundir las dos cosas es como se
 * acaba con una lista blanca que parece una autorizacion.
 */
class PosicionDelDomiciliarioResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $p = $this->resource;

        return [
            'latitude' => $p->latitude,
            'longitude' => $p->longitude,
            'orderSales_id' => $p->orderSales_id,
            'state' => $p->state,
            'created_at' => $p->created_at,
        ];
    }
}
