<?php

declare(strict_types=1);

namespace App\Http\Resources\Usuario;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * UN AVISO DE LA CAMPANA.
 *
 * `NotificacionesController::getNotifications()` devolvia la coleccion de
 * modelos entera. La tabla `notifications` llevaba desde el principio sin
 * usar —los metodos existian comentados y sin ruta—, asi que esta respuesta
 * es joven y nadie habia escrito su forma.
 */
class AvisoResource extends JsonResource
{
    /**
     * Lo que esta respuesta publica HOY, medido contra una fila real.
     *
     * Ocho claves. `datos` es la carga del aviso y viaja tal cual, como hasta ahora.
     */
    private const CAMPOS = [
        'notification_id',
        'user_id',
        'tipo',
        'message',
        'datos',
        'read',
        'date',
        'state',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return Arr::only($this->resource->toArray(), self::CAMPOS);
    }
}
