<?php

declare(strict_types=1);

namespace App\Http\Resources\Resena;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EL DOMICILIARIO DENTRO DE UNA RESENA, con su persona anidada.
 *
 * Cuatro claves suyas mas un bloque `user` de otras cuatro. NO lleva correo
 * ni telefono: una lista de resenas de un domiciliario la puede pedir una
 * tienda, y para leer la calificacion no hace falta su telefono.
 *
 * EL BLOQUE `user` DE AQUI NO ES `ResenadorResource`, aunque tenga los
 * MISMOS CUATRO NOMBRES DE CLAVE. Ahi esta la trampa:
 *
 *   aqui              las cuatro salen de `user`
 *   ResenadorResource `user_id` y `name` de `user`; `qualification` y
 *                     `state` de `buyer`
 *
 * Mismos nombres, otra fuente. Unirlos por parecerse cambiaria los valores de
 * una de las dos respuestas SIN cambiar una sola clave, y eso no se ve en un
 * diff ni lo caza una prueba que solo compare nombres de campo. Por eso son
 * dos clases y cada una dice de donde lee.
 */
class DomiciliarioDeLaResenaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $d = $this->resource;

        return [
            'domiciliary_id' => $d->domiciliary_id,
            'available' => $d->available,
            'qualification' => $d->qualification,
            'state' => $d->state,
            'user' => [
                'user_id' => $d->user->user_id,
                'name' => $d->user->name,
                'qualification' => $d->user->qualification,
                'state' => $d->user->state,
            ],
        ];
    }
}
