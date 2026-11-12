<?php

declare(strict_types=1);

namespace App\Http\Resources\Usuario;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * EL PROPIETARIO DENTRO DE LA RESPUESTA DEL LOGIN.
 *
 * `load('owner.businesses')` para el rol 2. Diez claves, y cuatro de ellas son
 * las MISMAS que este repositorio ya quito de los listados del catalogo:
 * `document_number`, `birthdate`, `contact_secondary` y `notes`.
 *
 * AQUI SE QUEDAN, y la diferencia importa: en el catalogo esos campos salian
 * del propietario de CUALQUIER tienda a cualquiera que abriera la vitrina
 * —eso era la fuga que se cerro—. En el login son los datos del propio
 * tendero, en su propia sesion. Un tendero puede ver su cedula.
 *
 * Lo que cambia con esta clase es que la lista esta escrita: una columna nueva
 * en `owner` ya no se publica sola.
 *
 * `businesses` viaja por `NegocioVinculadoResource`, que es donde esta el
 * hallazgo de esta tanda.
 */
class PropietarioResource extends JsonResource
{
    /**
     * Lo que esta respuesta publica, sacado de la respuesta REAL del login.
     *
     * Incluye `document_number`, `birthdate`, `contact_secondary` y `notes`. Son datos del PROPIO tendero en su propio login, no de otro.
     */
    private const CAMPOS = [
        'owner_id',
        'user_id',
        'document_type_id',
        'document_number',
        'birthdate',
        'contact_secondary',
        'notes',
        'state',
        'profile_photo',
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
