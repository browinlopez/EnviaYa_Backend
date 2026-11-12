<?php

declare(strict_types=1);

namespace App\Http\Resources\Resena;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EL NEGOCIO DENTRO DE UNA RESENA. Diez claves.
 *
 * NO ES EL DE LA FICHA, y la diferencia esta medida, clave por clave, para
 * que nadie las una por parecerse:
 *
 *   solo aqui              city
 *   solo en la ficha       latitude, longitude, distance_km, delivery_fee,
 *                          delivery_time_minutes, in_range, accepting_orders,
 *                          type
 *
 * Las otras nueve son las mismas. Un diff las vería casi iguales y la
 * tentación es dejar una; unirlas le quitaria `city` a esta respuesta o le
 * añadiria ocho campos de reparto a una lista de resenas, y las dos cosas
 * cambian lo que reciben apps ya publicadas.
 *
 * `razon_social` y `NIT` salen aqui, y esta lista es de un endpoint PUBLICO.
 * Se conservan porque ya salian y porque la ficha del negocio --tambien
 * publica-- los publica igual: son los datos legales de la tienda, no de una
 * persona. Lo que si se cuido en su dia fue el correo del RESENADOR, que esta
 * en `ResenadorResource`.
 */
class NegocioDeLaResenaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $b = $this->resource;

        return [
            // `business_id` no existe en la tabla: la columna es `busines_id`,
            // con una sola `s`, y leerla por el nombre bonito devolvia null.
            'business_id' => $b->busines_id,
            'name' => $b->name,
            'phone' => $b->phone,
            'address' => $b->address,
            'qualification' => $b->qualification,
            'razon_social' => $b->razonSocial_DCD,
            'NIT' => $b->NIT,
            'logo' => $b->logo,
            'city' => $b->city,
            'state' => $b->state,
        ];
    }
}
