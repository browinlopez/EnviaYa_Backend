<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ContadorDeConsultas;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * CUÁNTO TARDA CADA PETICIÓN, UNA LÍNEA POR PETICIÓN.
 *
 * §25 pide presupuestos de rendimiento y percentiles por endpoint. Lo que
 * había era `PresupuestoDeConsultas`, que avisa cuando una petición se pasa de
 * tiempo EN LA BASE DE DATOS, y su propio comentario dice lo que no puede
 * decir: cuánto tardó la petición en total. El manejador de
 * `whenQueryingForLongerThan` salta en el instante de cruzar el presupuesto,
 * así que el acumulado en ese momento vale ≈ el presupuesto siempre. Esta es
 * la pieza que faltaba, y no se añadió antes a propósito: ahora hace falta,
 * porque sin duraciones no hay percentil que calcular.
 *
 * QUÉ MIDE EXACTAMENTE, que no es lo mismo que lo que espera quien usa la app:
 * desde que la petición entra en este middleware hasta que sale la respuesta.
 * Bajo Octane eso es casi toda la petición, porque el arranque del framework
 * ya pasó. Bajo FPM se queda fuera el arranque —decenas de milisegundos— y
 * en los dos casos se queda fuera la red y la cola de nginx. **El reloj de
 * pared del usuario es `$request_time` de nginx, no esto.** Lo que esto mide
 * es el tiempo de la aplicación, que es el único sobre el que se puede actuar
 * cambiando código.
 *
 * POR QUÉ UN MIDDLEWARE Y NO `LARAVEL_START`. La constante se define una vez
 * en `public/index.php`, y bajo Octane eso ocurre al arrancar el trabajador:
 * medir desde ahí daría el tiempo de vida del proceso y crecería toda la
 * tarde. `REQUEST_TIME_FLOAT` sí es por petición, pero depende de lo que
 * Swoole ponga en el array de servidor y aquí no hay Swoole para
 * comprobarlo —Windows— así que no se usa lo que no se puede verificar. Un
 * middleware corre una vez por petición en los dos entornos, y eso sí se
 * comprueba.
 *
 * VA EN EL STACK GLOBAL, no por ruta. Dos motivos: una lista de rutas a medir
 * se queda incompleta el día que alguien añade un endpoint, y el middleware
 * global es invisible para `gatherMiddleware()` —comprobado: el congelado de
 * la superficie de la API no contiene `SetContentLengthForDevServer`, que
 * también es global—, así que medir no toca el contrato de las 325 rutas.
 *
 * LO QUE SE ESCRIBE: el patrón de la ruta con sus marcadores, el verbo, el
 * estado, los milisegundos, cuántas consultas y cuántos milisegundos de base.
 * **No va la URI, que lleva ids; no va la SQL; no van los parámetros; no va
 * el usuario.** Mismo criterio que `PresupuestoDeConsultas`: la forma segura
 * de enmascarar un parámetro sensible es no escribirlo.
 *
 * MUESTREO SÍ, UMBRAL NO. Si el volumen molesta se baja `muestreo` y el
 * fichero se encoge proporcionalmente, pero la FORMA de la distribución se
 * conserva y los percentiles siguen siendo ciertos. Un umbral —«registra solo
 * lo que pase de 500 ms»— haría justo lo contrario: una muestra censurada por
 * arriba, donde el «p50» sería el percentil de la cola y no de las
 * peticiones. Es el mismo error que medir un plan de ejecución sobre un mes
 * que contiene media tabla. Por eso no hay umbral: no es que falte, es que
 * mentiría.
 */
class MedidaDeLaPeticion
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->vaAMedir()) {
            return $next($request);
        }

        /*
         * SE REINICIAN LOS DOS CONTADORES AQUÍ, Y NO AL FINAL.
         *
         * Bajo Octane la conexión sobrevive a la petición: sin esto, lo que se
         * escribiría es el acumulado del trabajador desde que arrancó, o sea
         * un número que solo crece y que haría parecer lenta a la última ruta
         * que atendió. Lo mismo que hay que hacer en las pruebas del
         * presupuesto de consultas para que la segunda medida no herede la
         * primera.
         */
        DB::connection()->resetTotalQueryDuration();
        ContadorDeConsultas::reiniciar();

        $inicio = microtime(true);

        $respuesta = $next($request);

        $ms = (microtime(true) - $inicio) * 1000;

        $this->anotar($request, $respuesta, $ms);

        return $respuesta;
    }

    private function vaAMedir(): bool
    {
        if (! config('rendimiento.medir_peticiones')) {
            return false;
        }

        $muestreo = (float) config('rendimiento.muestreo');

        if ($muestreo >= 1.0) {
            return true;
        }

        if ($muestreo <= 0.0) {
            return false;
        }

        return mt_rand() / mt_getrandmax() < $muestreo;
    }

    private function anotar(Request $request, Response $respuesta, float $ms): void
    {
        $conexion = DB::connection();

        Log::channel('latencia')->info('peticion', [
            // El PATRÓN, con marcadores: `v1/orders/{id}` y no `v1/orders/4812`.
            'ruta' => $request->route()?->uri() ?? 'sin ruta',
            'verbo' => $request->method(),
            'estado' => $respuesta->getStatusCode(),
            'ms' => round($ms, 2),
            'consultas' => ContadorDeConsultas::cuantas(),
            /*
             * DOS DECIMALES, no uno. Con uno, un tiempo de base de 0,02 ms se
             * escribe como `0` y no se distingue de «esta ruta no toca la
             * base», que es una afirmación muy distinta. En producción el
             * número es de milisegundos enteros y da igual; en una base en
             * memoria, no, y es ahí donde esto se prueba.
             */
            'ms_base' => round($conexion->totalQueryDuration(), 2),
        ]);
    }
}
