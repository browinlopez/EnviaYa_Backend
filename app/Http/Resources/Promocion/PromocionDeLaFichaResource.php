<?php

declare(strict_types=1);

namespace App\Http\Resources\Promocion;

use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UNA PROMOCIÓN COMO LA VE EL COMPRADOR EN LA FICHA DE LA TIENDA.
 *
 * Esta forma estaba DOS VECES en el mismo archivo, a setenta líneas una de
 * otra, con las mismas cuatro claves y los mismos moldes:
 *
 *   ConsultaDeNegociosController:480   la mejor promoción de un producto,
 *                                      dentro del cierre `$promoDe`
 *   ConsultaDeNegociosController:555   la franja de promociones de la tienda
 *
 * Dos copias de una forma no se notan hasta que alguien cambia una: entonces
 * la ficha empieza a decir dos cosas distintas del mismo dato según por dónde
 * se mire, y eso no falla en ninguna prueba.
 *
 * NO ES `PromocionResource`, y la diferencia es el punto. Esa es la promoción
 * del PANEL DEL TENDERO: lleva veintitantas claves —`busines_id`, `usos`,
 * `max_usos`, `destinatarios`, `enviada_el`, `state`, `retirada`— porque el
 * tendero administra sus promociones. El comprador no administra nada: le
 * hacen falta el identificador, el texto, la regla en palabras y hasta cuándo
 * dura. Juntarlas sería publicarle al comprador cuántos envíos le quedan a la
 * tienda este mes.
 *
 * Tampoco es la de `PromocionesDeLaTienda:352` —la carga del aviso de la
 * campana, que lleva `hasta` en ISO junto al texto— ni la de
 * `DescuentoPorPromocion:130`, que cambia `hasta_texto` por `ahorro` porque
 * ahí lo que importa es cuánto se descontó. Cuatro formas de la misma entidad
 * para cuatro lectores distintos, y ahora cada una dice de quién es.
 */
class PromocionDeLaFichaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Promotion $promocion */
        $promocion = $this->resource;

        return [
            'promotion_id' => (int) $promocion->promotion_id,
            'texto' => (string) $promocion->description,
            'regla' => $promocion->reglaEnPalabras(),
            'hasta_texto' => $promocion->hastaEnPalabras(),
        ];
    }
}
