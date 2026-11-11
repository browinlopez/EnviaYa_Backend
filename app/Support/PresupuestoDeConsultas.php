<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AVISA CUANDO UNA PETICIÓN PASA DEMASIADO TIEMPO EN LA BASE DE DATOS.
 *
 * §25 pide medir el tiempo acumulado de consultas por petición, y aquí no
 * había con qué: **Pulse, Telescope y Nightwatch no están instalados** —ni
 * siquiera en `composer.json`—, así que de un endpoint lento en producción no
 * quedaba ni rastro. Lo único que se sabía era lo que alguien notara a ojo.
 *
 * NO ES UN DETECTOR DE CONSULTA LENTA, y el propio §25 avisa de no
 * confundirlos. Esto mide la SUMA: veinte consultas de 30 ms no disparan
 * ninguna alarma de consulta lenta y son 600 ms de espera para el tendero.
 * Es justo el caso que dejan los N+1.
 *
 * LO QUE SE ESCRIBE, Y LO QUE NO. Va el patrón de la ruta
 * —`v1/negocio/pedidos/{id}/cancelar`, con el marcador y no el número—, el
 * verbo, el total de milisegundos y cuántas consultas fueron. **No van la SQL
 * ni los parámetros**: §25 pide enmascarar los parámetros sensibles, y la
 * forma segura de enmascararlos es no escribirlos. Con la ruta y el total ya
 * se sabe dónde mirar.
 *
 * SALTA UNA VEZ POR PETICIÓN, no una por consulta: es como funciona
 * `whenQueryingForLongerThan`. Bajo Octane el contador se reinicia y el
 * manejador se rearma en cada petición —lo hace
 * `Octane\Listeners\RefreshQueryDurationHandling`, comprobado— y bajo FPM
 * cada petición es un proceso nuevo. Sin eso, el aviso se dispararía una vez
 * por trabajador y nunca más, que es peor que no tenerlo.
 *
 * LO QUE ESTO **NO** PUEDE DECIR, y conviene saberlo antes de leer el log:
 * cuánto tardó la petición EN TOTAL. El manejador salta en el instante en que
 * la suma cruza el presupuesto, no al final, así que en ese momento el
 * acumulado vale ≈ el presupuesto y siempre valdría lo mismo. Por eso se
 * escribe `ms_al_cruzar` y no un «total»: un campo que siempre dice 400 no
 * informa de nada y haría pensar que todas las rutas tardan igual.
 *
 * O sea que esto contesta **qué rutas se pasan y cuántas veces**, que es con
 * lo que se decide dónde mirar. No contesta «cuánto». Para eso haría falta
 * medir al terminar la petición —un `terminating` con su estado por
 * petición—, y es otra pieza: no se añade por adelantado sin que nadie la
 * haya necesitado todavía.
 */
class PresupuestoDeConsultas
{
    /**
     * Empieza a vigilar, con el presupuesto en milisegundos.
     *
     * Un presupuesto de 0 o menos apaga la vigilancia: así se puede dejar sin
     * efecto desde el entorno sin tocar código, que es lo que hace falta para
     * callar un aviso ruidoso en producción sin desplegar.
     *
     * EN MILISEGUNDOS FRACCIONARIOS, no enteros. En producción el número es
     * redondo —400— pero el tipo tiene que admitir decimales para que esto se
     * pueda probar: con SQLite en memoria, la petición más pesada de la suite
     * consume 0,12 ms de base de datos, así que con un entero el umbral más
     * bajo posible (1 ms) no se alcanza nunca y el aviso no se podría
     * ejercitar. `whenQueryingForLongerThan` compara contra un float, así que
     * no hay nada que convertir.
     */
    public static function vigilar(float $presupuestoMs): void
    {
        if ($presupuestoMs <= 0) {
            return;
        }

        DB::whenQueryingForLongerThan(
            $presupuestoMs,
            function (Connection $conexion, QueryExecuted $evento) use ($presupuestoMs) {
                $peticion = request();

                Log::channel('rendimiento')->warning('presupuesto de consultas excedido', [
                    // El patrón de la ruta, con marcadores: sin ids ni nada
                    // que venga del cliente.
                    'ruta' => $peticion?->route()?->uri() ?? 'fuera de una ruta',
                    'verbo' => $peticion?->method(),
                    'conexion' => $conexion->getName(),
                    'presupuesto_ms' => $presupuestoMs,
                    // El acumulado EN EL INSTANTE DE CRUZAR, no el de la
                    // petición entera: ver la nota de arriba.
                    'ms_al_cruzar' => round($conexion->totalQueryDuration(), 1),
                    /*
                     * La duración de la consulta que lo cruzó, que es el dato
                     * que de verdad separa los dos casos: si se parece al
                     * presupuesto, fue UNA consulta lenta; si es mucho menor,
                     * fue la suma de muchas —o sea un N+1—.
                     */
                    'cruzo_con_ms' => round($evento->time, 1),
                ]);
            },
        );
    }
}
