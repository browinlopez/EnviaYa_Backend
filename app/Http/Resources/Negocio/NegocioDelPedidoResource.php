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
            * `municipality_id`, y la ciudad de verdad sale de ahi. Esta clave
            * estuvo publicando `null` desde que se escribio, en CINCO
            * respuestas distintas, y lo destapo PHPStan al tipar el recurso:
            * mientras la relacion no estaba declarada, no podia comprobar el
            * campo.
            *
            * YA NO. Desde el 13 de noviembre de 2026 sale de
            * `municipality->name`, que es de donde salia la ciudad en los
            * listados de negocio desde siempre. El campo pasa de mentir a
            * decir algo.
            *
            * ES ADITIVO EN LA PRACTICA: la clave ya estaba y su tipo
            * declarado era `string|null`, asi que una app que la leyera
            * recibia nulo y ahora recibe texto. No se quita nada ni se
            * renombra nada.
            *
            * CUESTA UNA PRECARGA, y hay que ponerla donde falte: el
            * `business` de estas respuestas tiene que venir con su
            * `municipality`. Con `preventLazyLoading` activo fuera de
            * produccion, el sitio que se olvide LANZA en las pruebas en vez
            * de gastar una consulta por fila en silencio.
            */
            'city' => $negocio->municipality?->name,
            'qualification' => $negocio->qualification,
            'state' => $negocio->state,
            'logo' => $negocio->logo,
        ];
    }
}
