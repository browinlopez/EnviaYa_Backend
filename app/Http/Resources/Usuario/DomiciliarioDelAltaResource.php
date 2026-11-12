<?php

declare(strict_types=1);

namespace App\Http\Resources\Usuario;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * EL DOMICILIARIO QUE DEVUELVE EL ALTA Y LA CORRECCION.
 *
 * `DomiciliaryController::update()` devolvia esto:
 *
 *     'user' => new UsuarioResource($user),
 *     'domiciliary' => $domiciliary,
 *
 * El usuario ya estaba migrado —sexta tanda— y el domiciliario de al lado se
 * quedo crudo. Es el sitio mas facil de pasar por alto: la respuesta parece
 * atendida porque la primera clave lo esta.
 *
 * Y NO LO ENCONTRO EL MEDIDOR, sino la revision a mano. `scripts/
 * formas_de_entidad.py` busca de donde sale la variable, y `$domiciliary`
 * llega por RELACION —`$user->domiciliary`—, que tiene la misma forma que
 * `$nombre = $user->name`. El punto ciego esta escrito en la cabecera de ese
 * guion; este es uno de los dos sitios que se le escapan.
 */
class DomiciliarioDelAltaResource extends JsonResource
{
    /**
     * Lo que esta respuesta publica HOY, medido contra una fila real.
     *
     * Diez claves: las mismas que `DomiciliarioResource` publica dentro del login, medidas por separado contra la fila real para no suponerlo.
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
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return Arr::only($this->resource->toArray(), self::CAMPOS);
    }
}
