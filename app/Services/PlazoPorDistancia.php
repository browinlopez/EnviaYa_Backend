<?php

namespace App\Services;

/**
 * CUÁNTO TARDA, SEGÚN LO LEJOS QUE ESTÉ LA TIENDA.
 *
 * El plazo era uno solo para todo: veinte minutos, igual a tres cuadras que
 * a cinco kilómetros. A quien vive cerca se le prometía de más —y la tienda
 * de la esquina parecía igual de lenta que la del otro barrio— y a quien vive
 * lejos se le prometía algo que el domiciliario no podía cumplir, así que su
 * entrega entraba como "tarde" sin que nadie hiciera nada mal.
 *
 * Ahora son tres números del panel: lo que cuesta preparar y salir —la base—,
 * los minutos que suma cada kilómetro, y un tope para que una dirección en el
 * borde de la cobertura no prometa hora y media.
 *
 * Los minutos se redondean hacia ARRIBA y de cinco en cinco: nadie promete
 * "23 minutos", y un plazo redondo se lee como lo que es —una estimación— en
 * vez de fingir una precisión que no existe.
 */
class PlazoPorDistancia
{
    /** A cuántos minutos se redondea. */
    private const PASO_MINUTOS = 5;

    public function minutosPara(?float $km): int
    {
        $base = (int) Ajustes::valor('operacion.tiempo_entrega_min');

        /*
         * Sin distancia —una tienda sin coordenadas, o alguien que todavía no
         * eligió dirección— se promete la base. Es lo que se prometía antes de
         * que esto existiera, y sirve para enseñar algo en la lista.
         */
        if ($km === null || $km <= 0) {
            return $base;
        }

        $porKm = (float) Ajustes::valor('operacion.minutos_por_km');
        $tope  = (int) Ajustes::valor('operacion.tiempo_entrega_max');

        $minutos = $base + ($km * $porKm);
        $minutos = (int) (ceil($minutos / self::PASO_MINUTOS) * self::PASO_MINUTOS);

        // El tope manda, pero nunca por debajo de la base: un tope mal puesto
        // no puede prometer menos de lo que cuesta salir de la tienda.
        return max($base, min($minutos, max($base, $tope)));
    }
}
