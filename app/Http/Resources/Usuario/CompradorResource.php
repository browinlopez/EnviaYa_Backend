<?php

declare(strict_types=1);

namespace App\Http\Resources\Usuario;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * EL COMPRADOR DENTRO DE LA RESPUESTA DEL LOGIN.
 *
 * `AuthController::login()` hace `load('buyer')` para el rol 1, porque la app
 * usa ese bloque para saber a que pantalla llevar a la persona. Hasta ahora el
 * modelo se serializaba entero, asi que una columna nueva en `buyer` se
 * publicaba sola.
 *
 * De las tres relaciones del login esta es la mansa: cinco columnas y ninguna
 * que no deba ver su dueño.
 */
class CompradorResource extends JsonResource
{
    /**
     * Lo que esta respuesta publica, sacado de la respuesta REAL del login.
     *
     * Cinco claves y ninguna delicada: el id, el del usuario, la calificacion, si pertenece a un conjunto y el estado.
     */
    private const CAMPOS = [
        'buyer_id',
        'user_id',
        'qualification',
        'belongs_to_complex',
        'state',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return Arr::only($this->resource->toArray(), self::CAMPOS);
    }
}
