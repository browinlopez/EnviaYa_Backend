<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * FILTRAR POR DÍAS SIN ENVOLVER LA COLUMNA EN UNA FUNCIÓN.
 *
 * `whereDate('created_at', $dia)` genera `date(created_at) = ?`, y una función
 * sobre la columna filtrada deja el índice inservible para esa parte: el motor
 * tiene que abrir cada fila candidata para calcular `date()` y compararla.
 *
 * MEDIDO con `EXPLAIN` sobre la base de desarrollo, en las dos tablas donde
 * esto de verdad dolía:
 *
 *   complex_entries, un día      con date(): ref, 1388 filas  ->  range, 1
 *   orderssales, ventas de hoy   con date(): ref,  507 filas  ->  range, 21
 *
 * Y las dos crecen para siempre: `complex_entries` con cada visitante que
 * entra por la portería, `orderssales` con cada pedido de la tienda. El coste
 * de un `whereDate` no es una constante que se pueda ignorar; es el tamaño del
 * histórico.
 *
 * ESTO EXISTE COMO PIEZA Y NO COMO TRES PARCHES porque el patrón se repite —y
 * va a seguir repitiéndose— y porque **`hasta` tiene una trampa que un
 * arreglo apresurado se come**: ver abajo.
 *
 * TODO MEDIO ABIERTO: `>=` el principio y `<` el final.
 *
 * Y aquí hay que ser honesto con el motivo, porque el primero que escribí no
 * se sostiene. Dije que cerrar con `<=` y `endOfDay()` perdería lo que cayera
 * en los microsegundos del 23:59:59.999999; fui a romperlo para comprobarlo y
 * **no se rompe**. Las columnas de este esquema son `datetime`/`timestamp` de
 * precisión 0, y MySQL no trunca: un `23:59:59.500000` se guarda REDONDEADO
 * como `00:00:00` del día siguiente. Comprobado. Así que con este esquema las
 * dos formas coinciden y ninguna prueba puede separarlas.
 *
 * Se queda medio abierto igual, por una razón más pequeña y verdadera: es
 * correcto a CUALQUIER precisión, así que sigue siendo correcto el día que
 * alguna de esas columnas pase a `datetime(6)` —y ese día nadie se va a
 * acordar de revisar los filtros—. No hay prueba que lo distinga, y por eso
 * está dicho aquí.
 */
class FiltroDeFechas
{
    /**
     * Ese día entero: de su medianoche a la siguiente, sin incluirla.
     *
     * Sustituye a `whereDate($columna, $dia)`.
     *
     * @template TConsulta
     *
     * @param  TConsulta  $consulta
     * @return TConsulta
     */
    public static function unDia(mixed $consulta, string $columna, mixed $dia): mixed
    {
        $desde = self::medianoche($dia);

        return $consulta
            ->where($columna, '>=', $desde)
            ->where($columna, '<', $desde->copy()->addDay());
    }

    /**
     * Desde ese día en adelante, incluyéndolo.
     *
     * Sustituye a `whereDate($columna, '>=', $dia)`. Es la traducción directa:
     * `date(col) >= '2026-11-01'` es lo mismo que `col >= '2026-11-01 00:00'`.
     *
     * @template TConsulta
     *
     * @param  TConsulta  $consulta
     * @return TConsulta
     */
    public static function desde(mixed $consulta, string $columna, mixed $dia): mixed
    {
        return $consulta->where($columna, '>=', self::medianoche($dia));
    }

    /**
     * Hasta ese día, INCLUYÉNDOLO ENTERO.
     *
     * AQUÍ ESTÁ LA TRAMPA, y es el motivo de que esta clase exista en vez de
     * tres reescrituras a mano. `whereDate($columna, '<=', '2026-11-05')`
     * incluye todo el día 5, hasta las 23:59:59. La traducción que se escribe
     * sin pensar —`where($columna, '<=', '2026-11-05')`— lo convierte en
     * «hasta las 00:00:00 del 5», y se pierde el día entero menos el primer
     * segundo.
     *
     * Un filtro de fechas que calla un día de movimientos de portería o de
     * ventas no da error: da un número más pequeño. Por eso va aquí, con su
     * prueba, y no repetido en cada controlador.
     *
     * @template TConsulta
     *
     * @param  TConsulta  $consulta
     * @return TConsulta
     */
    public static function hasta(mixed $consulta, string $columna, mixed $dia): mixed
    {
        return $consulta->where(
            $columna, '<', self::medianoche($dia)->addDay()
        );
    }

    /**
     * Estrictamente antes de ese día: no incluye el día.
     *
     * Sustituye a `whereDate($columna, '<', $dia)`, que es como se pregunta
     * «esto ya venció» o «esto es de antes de hoy». La traducción sí es
     * directa aquí —`date(col) < '2026-11-05'` es `col < '2026-11-05 00:00'`—
     * pero existe como método para que nadie tenga que volver a decidir cuál
     * de los cuatro casos es el suyo, que es donde se cuela el de `hasta`.
     *
     * @template TConsulta
     *
     * @param  TConsulta  $consulta
     * @return TConsulta
     */
    public static function antesDe(mixed $consulta, string $columna, mixed $dia): mixed
    {
        return $consulta->where($columna, '<', self::medianoche($dia));
    }

    /**
     * Ese año entero: del 1 de enero al 1 de enero siguiente, sin incluirlo.
     *
     * Sustituye a `whereYear($columna, $anio)`, que tiene exactamente el mismo
     * problema que `whereDate`: `year(columna) = ?` envuelve la columna en una
     * función y deja el índice inservible para esa parte.
     *
     * MEDIDO, Y CON HONESTIDAD: hoy esto NO se nota. Las tablas donde estaba
     * —`safety_incidents` con 6 filas, `settlements` con 10, `pqrs` con 13,
     * `landing_requests` con 0— son tan pequeñas que `EXPLAIN` da el mismo
     * plan con la función y sin ella: `type=ref`, una fila leída. Así que esto
     * no arregla una lentitud; quita una mina.
     *
     * La mina es real porque ya explotó al lado: `complex_entries` tenía el
     * mismo patrón y a 1388 filas el `whereDate` costaba leerlas todas. Un
     * incidente de seguridad al mes son 120 filas en diez años; las PQRS de
     * una plataforma que funcione crecen bastante más rápido. El día que
     * duela, nadie se va a acordar de mirar aquí.
     *
     * @template TConsulta
     *
     * @param  TConsulta  $consulta
     * @return TConsulta
     */
    public static function elAnio(mixed $consulta, string $columna, int $anio): mixed
    {
        $desde = Carbon::create($anio, 1, 1)->startOfDay();

        return $consulta
            ->where($columna, '>=', $desde)
            ->where($columna, '<', $desde->copy()->addYear());
    }

    /**
     * Ese mes de ese año, entero.
     *
     * AQUÍ ESTÁ LA SEGUNDA TRAMPA de esta clase, y es peor que la de `hasta`
     * porque el código que la tiene parece correcto.
     *
     * `whereMonth($columna, 11)` **a secas** no significa «noviembre de este
     * año»: significa noviembre de TODOS los años. Los cuatro sitios que se
     * convirtieron lo sabían y lo escribían en dos líneas —`whereMonth(...)`
     * y `whereYear(...)` seguidas—, pero son dos líneas que hay que recordar
     * poner juntas, y la que falta no da error: da un número más grande, y
     * crece un año cada enero.
     *
     * Por eso este método **exige el año como parámetro** en vez de usar el
     * actual por omisión. Un parámetro que no se puede olvidar es mejor que
     * un comentario que avisa de no olvidarlo.
     *
     * @template TConsulta
     *
     * @param  TConsulta  $consulta
     * @return TConsulta
     */
    public static function elMes(mixed $consulta, string $columna, int $anio, int $mes): mixed
    {
        $desde = Carbon::create($anio, $mes, 1)->startOfDay();

        return $consulta
            ->where($columna, '>=', $desde)
            ->where($columna, '<', $desde->copy()->addMonth());
    }

    /**
     * La medianoche de ese día, venga como texto, como fecha o como Carbon.
     *
     * Se normaliza en un solo sitio porque los filtros de las pantallas llegan
     * como `'2026-11-05'` y el código de dentro usa Carbon. Mezclar los dos
     * formatos en cada sitio es donde se cuelan las comparaciones de texto
     * contra `datetime`.
     */
    private static function medianoche(mixed $dia): Carbon
    {
        if ($dia instanceof CarbonInterface) {
            return Carbon::instance($dia->toDateTime())->startOfDay();
        }

        return Carbon::parse((string) $dia)->startOfDay();
    }
}
