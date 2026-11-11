<?php

declare(strict_types=1);

namespace App\Http\Resources\Negocio;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * LOS CAMPOS DEL NEGOCIO QUE VAN EN LAS TRES RESPUESTAS.
 *
 * Era `NegocioParaLaApp::base()`, movida aqui sin tocar un campo. La sirven la
 * vitrina (`GET /businesses/index`), los mejores por calificacion y la ficha
 * de un negocio, y es la lectura de MAS TRAFICO de la plataforma: la hace cada
 * comprador al abrir la app.
 *
 * POR QUE EXISTIA EL SERVICIO Y POR QUE AHORA ES UN RESOURCE. `index`,
 * `indexByQualification` y `show` construian la misma respuesta con variantes
 * —178, 130 y 124 lineas, con cincuenta identicas literalmente en las tres—.
 * El servicio junto los bloques compartidos; el Resource es donde §04 quiere
 * que vivan, y la diferencia practica es que ahora la forma no esta en una
 * clase que tambien podria hacer otras cosas.
 *
 * Lo que cambia de verdad entre las tres respuestas —que se incluye, como se
 * ordena, el modo ligero— sigue en el controlador, que es donde se entiende.
 *
 * `logo` cae a una imagen por omision cuando esta vacio, y eso NO es
 * cosmetica: la app publicada pinta el valor tal cual, asi que un nulo le
 * deja un hueco. Se queda igual.
 */
class NegocioBaseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $negocio = $this->resource;

        return [
            'business_id' => $negocio->busines_id,
            'name' => $negocio->name,
            'phone' => $negocio->phone,
            'address' => $negocio->address,
            'qualification' => (float) $negocio->qualification,
            'razon_social' => $negocio->razonSocial_DCD,
            'NIT' => $negocio->NIT,
            'logo' => $negocio->logo ?? 'https://example.com/default-logo.png',
            'state' => (bool) $negocio->state,
            'municipality' => $negocio->municipality ? [
                'id' => $negocio->municipality->id,
                'name' => $negocio->municipality->name,
            ] : null,
            'owner_count' => $negocio->owners->count(),
        ];
    }
}
