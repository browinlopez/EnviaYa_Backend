<?php

declare(strict_types=1);

namespace App\Http\Resources\Promocion;

use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UNA PROMOCION DE LA TIENDA, tal como la pinta el panel del tendero.
 *
 * Es la forma que estaba en `Promotion::toApi()`, movida aqui sin tocar un
 * campo, por el mismo motivo que `PedidoResource`: el modelo deja de tener
 * dos trabajos.
 *
 * Dos detalles de la forma que conviene no perder de vista al tocarla, y que
 * ya estaban decididos:
 *
 *   · `max_usos` viaja como `null` y no como `0` cuando no hay tope, porque
 *     la app distingue «sin limite» de «agotada» por ese nulo;
 *   · `productos` sale vacio si la relacion no esta precargada, en vez de
 *     dispararla. Es lo que impide un N+1 en la lista de promociones: quien
 *     la quiera, la precarga.
 */
class PromocionResource extends JsonResource
{
    /**
     * LAS CONSTANTES SE NOMBRAN, no se heredan.
     *
     * Al mover el cuerpo, los tres `self::RETIRADA`, `self::SIN_DESCUENTO` y
     * `self::PORCENTAJE` dejaron de apuntar al modelo y empezaron a apuntar a
     * ESTA clase, que no las tiene. Ahora dicen `Promotion::`.
     *
     * Lo encontro la comparacion byte a byte de la respuesta —un 500 con
     * «Undefined constant»— y no `php -l`, que no mira constantes, ni PHPStan
     * en su nivel, ni ninguna de las 1234 pruebas: ninguna llamaba a la lista
     * de promociones. Un metodo movido de clase cambia el significado de
     * `self` en silencio.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $promocion = $this->resource;

        return [
            'promotion_id' => $promocion->promotion_id,
            'busines_id' => $promocion->busines_id,
            'texto' => $promocion->description,
            'productos' => $promocion->relationLoaded('products')
                ? $promocion->products->map(fn ($p) => [
                    'products_id' => $p->products_id,
                    'name' => $p->name,
                    'image' => $p->image ?? null,
                ])->values()
                : [],
            'enviada_el' => $promocion->sent_at?->toIso8601String(),
            'destinatarios' => (int) $promocion->recipients_count,
            'state' => (int) $promocion->state,
            'desde' => $promocion->start_date?->toIso8601String(),
            'hasta' => $promocion->end_date?->toIso8601String(),
            'hasta_texto' => $promocion->hastaEnPalabras(),
            'vencida' => $promocion->vencida(),
            'empezada' => $promocion->empezada(),
            // `max_usos` viaja como null —y no como 0— cuando no hay tope: la
            // app distingue «sin límite» de «agotada» por ese null.
            'max_usos' => $promocion->max_usos === null ? null : (int) $promocion->max_usos,
            'usos' => (int) $promocion->usos,
            'agotada' => $promocion->agotada(),
            'retirada' => (int) $promocion->state === Promotion::RETIRADA,
            'tipo' => $promocion->tipo ?? Promotion::SIN_DESCUENTO,
            'porcentaje' => $promocion->tipo === Promotion::PORCENTAJE
                ? round(((float) $promocion->percentage_discount) * 100)
                : null,
            'lleva' => $promocion->lleva,
            'paga' => $promocion->paga,
            'regla_texto' => $promocion->reglaEnPalabras(),
        ];
    }
}
