<?php

declare(strict_types=1);

namespace App\Http\Resources\Resena;

use App\Models\Reviews\DomiciliaryReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UNA RESENA DE UN DOMICILIARIO.
 *
 * Era el cierre `->map()` de `ResenasDeDomiciliarioController::index()`.
 *
 * La cabecera de cinco claves es la MISMA que `ResenaDeUnNegocioResource`;
 * lo que cuelga, no. Y el bloque del resenador es el mismo de verdad, asi que
 * va por `ResenadorResource`: ahi esta escrita, una sola vez, la decision de
 * no publicar el correo de quien califica.
 *
 * `domiciliary` NO lleva guardia de null, y es como estaba: la consulta filtra
 * por `domiciliary_id`, asi que una resena sin domiciliario no puede salir en
 * esta lista. Anadirle un `?:` seria cambiar la respuesta en un caso que no
 * ocurre y esconder un fallo de datos si alguna vez ocurriera.
 */
class ResenaDeUnDomiciliarioResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /* Tipado a proposito, y no es adorno: estas lecturas vivian en el
           controlador, donde PHPStan SI las comprobaba. Sin esta linea el
           recurso es `mixed` y las cuatro avisos de relacion no declarada
           desaparecerian del informe sin que nadie las arreglara. */
        /** @var DomiciliaryReview $r */
        $r = $this->resource;

        return [
            'review_id' => $r->reviews_id,
            'qualification' => $r->qualification,
            'comment' => $r->comment,
            'state' => $r->state,
            'created_at' => $r->created_at,
            'user' => $r->buyer && $r->buyer->user
                ? (new ResenadorResource($r->buyer))->resolve()
                : null,
            'domiciliary' => (new DomiciliarioDeLaResenaResource($r->domiciliary))->resolve(),
        ];
    }
}
