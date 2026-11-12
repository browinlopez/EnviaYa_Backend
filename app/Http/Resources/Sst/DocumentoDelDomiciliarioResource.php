<?php

declare(strict_types=1);

namespace App\Http\Resources\Sst;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * UN DOCUMENTO DE UN DOMICILIARIO, en el panel de SST.
 *
 * Era el `array_merge($d->toArray(), [...])` de
 * `DocumentosApiController::documentos()`. Mismo agujero que los otros dos:
 * la respuesta eran las columnas de la tabla, asi que anadir una columna la
 * publicaba.
 *
 * ESTE ES EL QUE MAS IMPORTA DE LOS TRES, porque la tabla guarda los papeles
 * de una persona —licencia, seguro, antecedentes— y es la clase de tabla a la
 * que se le anade una columna sin pensar en quien la va a recibir.
 *
 * Los cuatro campos de abajo NO son columnas: `domiciliary_name` y
 * `domiciliary_phone` los resuelve el modelo, y `situation` y `days_left` los
 * calcula —vigente, por vencer, vencido, y cuantos dias quedan—. Son lo que
 * el panel pinta en los semaforos, y por eso van al lado y no se dejan a la
 * interfaz: la regla de cuando un papel esta «por vencer» es del negocio.
 */
class DocumentoDelDomiciliarioResource extends JsonResource
{
    /** Lo que esta respuesta publica, sacado de la respuesta real. */
    private const CAMPOS = [
        'id',
        'domiciliary_id',
        'type',
        'number',
        'issued_at',
        'expires_at',
        'media_id',
        'notes',
        'state',
        'created_at',
        'updated_at',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $d = $this->resource;

        return array_merge(Arr::only($d->toArray(), self::CAMPOS), [
            'domiciliary_name' => $d->domiciliary_name,
            'domiciliary_phone' => $d->domiciliary_phone,
            'situation' => $d->situacion(),
            'days_left' => $d->diasRestantes(),
        ]);
    }
}
