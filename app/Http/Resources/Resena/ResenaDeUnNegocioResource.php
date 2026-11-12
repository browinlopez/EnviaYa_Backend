<?php

declare(strict_types=1);

namespace App\Http\Resources\Resena;

use App\Models\Reviews\BusinessReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UNA RESENA DE UN NEGOCIO, en la lista publica de resenas de una tienda.
 *
 * Era el cierre `->map()` de `ResenasDeNegocioController::index()`.
 *
 * LA CABECERA DE CINCO CLAVES --`review_id`, `qualification`, `comment`,
 * `state`, `created_at`-- era IDENTICA a la de
 * `ResenaDeUnDomiciliarioResource`. No se junta en una clase padre: lo que
 * viene despues es distinto --aqui un negocio, alli un domiciliario con su
 * persona-- y una clase base de cinco claves para dos hijos anade una
 * indireccion que no paga. Lo que si se junto es el bloque del resenador,
 * que llevaba una decision de privacidad escrita dos veces.
 *
 * TERCERA FORMA DE RESENA DEL REPOSITORIO, y conviene saber las tres:
 *
 *   ResenaDeUnNegocioResource        esta: la lista publica de una tienda
 *   ResenaDeUnDomiciliarioResource   la lista de un domiciliario
 *   Negocio\ResenaDelNegocioResource la resena DENTRO de la ficha, que usa
 *                                    `rating` en vez de `qualification`,
 *                                    lleva `buyer_id` y no lleva `state` ni
 *                                    el bloque del resenador
 *
 * Tres lectores, tres formas. La tercera no se toca: cambiarle `rating` por
 * `qualification` para que «cuadren» rompe la ficha en las apps publicadas.
 */
class ResenaDeUnNegocioResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /* Tipado a proposito, y no es adorno: estas lecturas vivian en el
           controlador, donde PHPStan SI las comprobaba. Sin esta linea el
           recurso es `mixed` y las cuatro avisos de relacion no declarada
           desaparecerian del informe sin que nadie las arreglara. */
        /** @var BusinessReview $r */
        $r = $this->resource;

        return [
            'review_id' => $r->reviews_id,
            'qualification' => $r->qualification,
            'comment' => $r->comment,
            'state' => $r->state,
            'created_at' => $r->created_at,
            /* El guardia se queda aca y no dentro del Resource: `buyer` o su
               `user` pueden faltar, y entonces la respuesta lleva `null` en
               vez del bloque. Es una regla de que hay, no una forma. */
            'user' => $r->buyer && $r->buyer->user
                ? (new ResenadorResource($r->buyer))->resolve()
                : null,
            'business' => $r->business
                ? (new NegocioDeLaResenaResource($r->business))->resolve()
                : null,
        ];
    }
}
