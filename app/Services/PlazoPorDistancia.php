<?php

declare(strict_types=1);

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
 *
 * --------------------------------------------------------------------------
 * Y LO QUE DE VERDAD TARDA LA COCINA, CUANDO EL NEGOCIO LO SABE.
 *
 * La base es un número de plataforma: el mismo para la tienda de la esquina
 * y para el restaurante que hace un sancocho. A un plato que tarda treinta y
 * cinco minutos en salir se le prometían los veinte de todo el mundo, y el
 * pedido entraba como «tarde» sin que el cocinero hiciera nada mal.
 *
 * Desde que los platos tienen `minutos_preparacion`, ese número entra acá.
 ======================================================================== */
class PlazoPorDistancia
{
    /** A cuántos minutos se redondea. */
    private const PASO_MINUTOS = 5;

    /**
     * @param  float|null  $km  Lo lejos que vive quien pidió.
     * @param  int  $preparacion  Lo que tarda la cocina en soltar el pedido.
     */
    public function minutosPara(?float $km, int $preparacion = 0): int
    {
        /*
         * LA COCINA MANDA SOBRE LA BASE, Y NO SE SUMA.
         *
         * `max` y no `base + preparacion` porque la base YA incluye preparar:
         * su propio ajuste se llama «lo que cuesta preparar y salir». Sumarlos
         * contaría la preparación dos veces y prometería una hora por un
         * almuerzo.
         *
         * Lo que hace esto es poner un SUELO: si la cocina dice que tarda más
         * que el número genérico de la plataforma, gana la cocina. Si tarda
         * menos --una gaseosa, cero-- manda la base, porque salir de la tienda
         * y montarse en la moto cuesta igual.
         */
        $base = max(
            (int) Ajustes::valor('operacion.tiempo_entrega_min'),
            max(0, $preparacion),
        );

        /*
         * Sin distancia —una tienda sin coordenadas, o alguien que todavía no
         * eligió dirección— se promete la base. Es lo que se prometía antes de
         * que esto existiera, y sirve para enseñar algo en la lista.
         */
        if ($km === null || $km <= 0) {
            return $base;
        }

        $porKm = (float) Ajustes::valor('operacion.minutos_por_km');
        $tope = (int) Ajustes::valor('operacion.tiempo_entrega_max');

        $minutos = $base + ($km * $porKm);
        $minutos = (int) (ceil($minutos / self::PASO_MINUTOS) * self::PASO_MINUTOS);

        // El tope manda, pero nunca por debajo de la base: un tope mal puesto
        // no puede prometer menos de lo que cuesta salir de la tienda.
        return max($base, min($minutos, max($base, $tope)));
    }
}
