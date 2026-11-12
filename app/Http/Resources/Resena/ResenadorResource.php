<?php

declare(strict_types=1);

namespace App\Http\Resources\Resena;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * QUIEN ESCRIBE UNA RESENA. Cuatro claves, y la que NO esta es el punto.
 *
 * Esta forma estaba DOS VECES, en `ResenasDeNegocioController` y en
 * `ResenasDeDomiciliarioController`, identica hasta el guardia de null. Y las
 * dos copias llevaban escrito, cada una con sus palabras, el mismo cuidado:
 *
 *   «Endpoint publico: no exponer el email del resenador.»
 *   «Se omite el email: quien califica no tiene por que quedar expuesto
 *    ante el domiciliario.»
 *
 * Esa es una decision de privacidad tomada dos veces en dos sitios. Mientras
 * esten las dos copias, anadir el correo en una deja la otra sin el y nada lo
 * nota: no hay prueba que compare las dos listas, porque no habia una lista,
 * habia dos. Aqui esta escrita una vez, con su motivo.
 *
 * `qualification` y `state` SALEN DE `buyer`, NO DE `user`. Importa porque
 * `DomiciliarioDeLaResenaResource` lleva un bloque con los MISMOS cuatro
 * nombres de clave --`user_id`, `name`, `qualification`, `state`-- que lee los
 * cuatro de `user`. Mismos nombres, otra fuente: unirlos por parecerse
 * cambiaria los valores de una de las dos respuestas sin cambiar una sola
 * clave, que es la clase de error que no se ve en un diff.
 *
 * EL GUARDIA DE NULL SE QUEDA EN EL CONTROLADOR, a proposito: `buyer` o su
 * `user` pueden faltar, y entonces la respuesta lleva `null` en vez del
 * bloque. Eso es una regla de que se puede ver, no una forma, y un Resource
 * que devolviera un array vacio en ese caso cambiaria la respuesta.
 */
class ResenadorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $buyer = $this->resource;

        return [
            'user_id' => $buyer->user->user_id,
            'name' => $buyer->user->name,
            'qualification' => $buyer->qualification,
            'state' => $buyer->state,
        ];
    }
}
