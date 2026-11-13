<?php

declare(strict_types=1);

namespace App\Http\Resources\Conjunto;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * LA FICHA DE UN CONJUNTO RESIDENCIAL. Catorce claves.
 *
 * Era el metodo `ficha()` de `MiConjuntoController`, y la leen dos pantallas:
 * la del administrador del conjunto y la del celador en la porteria.
 *
 * `gate_notes` ES LA CLAVE QUE JUSTIFICA QUE ESTA FORMA SEA UNA Y NO DOS. Son
 * las instrucciones permanentes de la porteria --«despues de las 10 p.m. no
 * se reciben domicilios», «la torre 7 no tiene ascensor»-- y el celador tiene
 * que verlas sin ir a buscarlas. Si el dia de manana el administrador
 * necesita campos que el celador no, se parte en dos clases hermanas como se
 * hizo con el domiciliario, no se le anade una bandera a esta.
 *
 * `nit` y `email` salen porque es la ficha del conjunto ante su propio
 * administrador, no un directorio publico: esta ruta va detras del middleware
 * `conjunto`, que resuelve de que conjunto es quien pregunta y no se fia de
 * un identificador del cuerpo.
 *
 * `require_authorization` llega como booleano y no como el `0`/`1` de la
 * tabla: una cadena «0» es verdadera en JavaScript y el panel pintaria el
 * interruptor al reves. Es el mismo molde que `Business::accepting_orders`
 * lleva en sus `casts` por el mismo motivo.
 */
class ConjuntoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $c = $this->resource;

        return [
            'complex_id' => (int) $c->complex_id,
            'name' => $c->name,
            'photo' => $c->photo,
            'address' => $c->address,
            'phone' => $c->phone,
            'email' => $c->email,
            'admin_name' => $c->admin_name,
            'nit' => $c->nit,
            // Las instrucciones permanentes de la porteria: ver la cabecera.
            'gate_notes' => $c->gate_notes,
            'require_authorization' => (bool) $c->require_authorization,
            'towers_count' => $c->towers_count,
            'apartments_per_tower' => $c->apartments_per_tower,
            'latitude' => $c->latitude !== null ? (float) $c->latitude : null,
            'longitude' => $c->longitude !== null ? (float) $c->longitude : null,
        ];
    }
}
