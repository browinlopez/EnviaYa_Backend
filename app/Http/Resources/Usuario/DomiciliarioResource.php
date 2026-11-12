<?php

declare(strict_types=1);

namespace App\Http\Resources\Usuario;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * EL DOMICILIARIO DENTRO DE LA RESPUESTA DEL LOGIN.
 *
 * `load('domiciliary.businesses')` para el rol 3. Once claves, con lo del
 * contrato —si lo firmo, cuando y en que ciudad— y su documento. Son sus
 * datos, en su sesion.
 *
 * `businesses` viaja por `NegocioVinculadoResource`: ahi esta lo que si hay
 * que decidir.
 */
class DomiciliarioResource extends JsonResource
{
    /**
     * Lo que esta respuesta publica, sacado de la respuesta REAL del login.
     *
     * Lleva `document`, `contract_media_id`, `contract_signed_at` y `contract_city`: los papeles del propio domiciliario, en su propia sesion.
     */
    private const CAMPOS = [
        'domiciliary_id',
        'user_id',
        'available',
        'document',
        'contract_media_id',
        'contract_signed_at',
        'contract_city',
        'qualification',
        'state',
        'registrado_por_negocio',
        'businesses',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $datos = Arr::only($this->resource->toArray(), self::CAMPOS);

        /*
         * Los negocios vinculados pasan por su propio Resource, para que una
         * columna nueva de `business` no se publique sola en el login. Solo
         * si la relacion esta cargada: el login la carga, otros sitios no, y
         * tocarla aqui disparia una consulta por usuario.
         */
        if ($this->resource->relationLoaded('businesses')) {
            $datos['businesses'] = NegocioVinculadoResource::collection(
                $this->resource->businesses,
            )->resolve();
        }

        return $datos;
    }
}
