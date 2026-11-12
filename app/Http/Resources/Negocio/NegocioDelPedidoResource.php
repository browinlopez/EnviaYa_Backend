<?php

declare(strict_types=1);

namespace App\Http\Resources\Negocio;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EL NEGOCIO DEL PEDIDO, que en la respuesta va UNA SOLA VEZ.
 *
 * Era `PedidoParaLaApp::negocio()`, movida aqui sin tocar un campo. Sirve la
 * clave `business` de `POST /orders/business`: la lista trae los pedidos de
 * una sola tienda, asi que sus datos viajan arriba y no repetidos dentro de
 * cada pedido.
 *
 * NO ES LA MISMA FORMA que el `business` de dentro de cada pedido, y conviene
 * saberlo antes de intentar juntarlas: esta lleva `qualification` y la de
 * dentro no. Las dos estan en la respuesta que las apps publicadas ya
 * reciben, asi que se quedan las dos.
 */
class NegocioDelPedidoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $negocio = $this->resource;

        return [
            'business_id' => $negocio->busines_id,
            'name' => $negocio->name,
            'address' => $negocio->address,
            'latitude' => $negocio->latitude !== null ? (float) $negocio->latitude : null,
            'longitude' => $negocio->longitude !== null ? (float) $negocio->longitude : null,
            'phone' => $negocio->phone,
            /*
            * `city` NO EXISTE como columna de `business`. La tabla tiene
            * `municipality_id`, y la ciudad de verdad sale de ahi. Esta
            * clave ha estado publicando `null` desde que se escribio, en
            * CINCO respuestas distintas, y lo destapo PHPStan al tipar el
            * recurso: mientras la relacion no estaba declarada, no podia
            * comprobar el campo.
            *
            * Se deja la clave, con `null` explicito: la salida es la
            * misma que hoy --`null` de las dos maneras-- y quitarla del
            * todo obligaria a la app a defenderse de un `undefined`
            * donde hoy recibe un nulo. Lo que cambia es que ya no
            * finge leer una columna que no hay.
            *
            * Si algun dia la ciudad hace falta de verdad, sale de
            * `municipality->name`, que ya viaja en varias de estas
            * respuestas.
            */
            'city' => null,
            'qualification' => $negocio->qualification,
            'state' => $negocio->state,
            'logo' => $negocio->logo,
        ];
    }
}
