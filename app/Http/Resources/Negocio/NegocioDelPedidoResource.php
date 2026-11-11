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
            'city' => $negocio->city,
            'qualification' => $negocio->qualification,
            'state' => $negocio->state,
            'logo' => $negocio->logo,
        ];
    }
}
