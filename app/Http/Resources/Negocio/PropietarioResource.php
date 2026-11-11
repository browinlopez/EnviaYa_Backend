<?php

declare(strict_types=1);

namespace App\Http\Resources\Negocio;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DEL PROPIETARIO SOLO SALE LO QUE HACE FALTA PARA COMPRAR.
 *
 * Era `NegocioParaLaApp::propietarios()`, movida aqui sin tocar un campo.
 *
 * Y lleva una historia que conviene no perder: aqui salian tambien su numero
 * de documento, su fecha de nacimiento y su direccion, a cualquiera que
 * abriera el catalogo. Cuatro campos es lo que queda. Si algun dia el panel
 * necesita los otros, van por la API de administracion, que tiene puerta.
 */
class PropietarioResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $owner = $this->resource;

        return [
            'owner_id' => $owner->owner_id,
            'user_id' => $owner->user_id,
            'profile_photo' => $owner->profile_photo ?? 'https://example.com/default-user.png',
            'state' => (bool) $owner->state,
        ];
    }
}
