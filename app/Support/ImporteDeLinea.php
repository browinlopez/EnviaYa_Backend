<?php

namespace App\Support;

/**
 * CUÁNTO VALE UNA LÍNEA DE PEDIDO.
 *
 * Una sola regla, en un solo sitio, y es lo único que hay que propagar al
 * resto del código cuando entra el peso:
 *
 *     importe = line_total ?? round(unit_price * amount, 2)
 *
 * El `??` es la frontera entre lo viejo y lo nuevo. Hasta hoy el importe no
 * estaba escrito en ninguna parte: se recalculaba como `unit_price * amount`
 * en cada consumidor —el carrito en `EconomiaDelPedido`, el comprobante en
 * `FacturaService`, el detalle del panel—. Las líneas por peso SÍ lo traen
 * congelado, porque en un pedido «3.000 de queso» el importe es el dato de
 * partida y no el resultado de una multiplicación.
 *
 * No se rellenó `line_total` hacia atrás a propósito: dos fuentes de verdad
 * del mismo importe, una de ellas con quince mil filas históricas sin
 * verificar, es peor que una regla de lectura de una línea.
 */
class ImporteDeLinea
{
    /**
     * Lo que se le cobra al cliente por esta línea.
     *
     * @param  object|array  $linea  Una fila de `orderssales_detail`.
     */
    public static function de($linea): float
    {
        $valor = static::campo($linea, 'line_total');

        if ($valor !== null && $valor !== '') {
            return round((float) $valor, 2);
        }

        return round(
            (float) static::campo($linea, 'unit_price') * (float) static::campo($linea, 'amount'),
            2,
        );
    }

    /**
     * Cuánto se vendió, en la unidad de la línea.
     *
     * Lo pesado manda sobre lo pedido: es lo que de verdad salió de la
     * tienda, y es lo que tiene que descontar del inventario y figurar en el
     * comprobante. Mientras nadie haya pesado, vale lo pedido.
     */
    public static function cantidad($linea): float
    {
        foreach (['qty_final', 'qty_requested'] as $campo) {
            $valor = static::campo($linea, $campo);

            if ($valor !== null && $valor !== '') {
                return (float) $valor;
            }
        }

        return (float) static::campo($linea, 'amount');
    }

    /** Acepta tanto el objeto de una consulta cruda como un array. */
    private static function campo($linea, string $campo)
    {
        if (is_array($linea)) {
            return $linea[$campo] ?? null;
        }

        return $linea->{$campo} ?? null;
    }
}
