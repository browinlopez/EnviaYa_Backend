<?php

declare(strict_types=1);

namespace App\Support;

/**
 * LOS PERCENTILES DE LAS LÍNEAS DE LATENCIA.
 *
 * Vive aquí y no dentro del script para que se pueda probar. El cálculo de un
 * percentil es la clase de código que parece obvio y se equivoca en los
 * bordes —con una sola muestra, con dos, con el p100— y un informe que se
 * equivoca en silencio es peor que no tenerlo: nadie vuelve a comprobar un
 * número que ya salió impreso.
 *
 * MÉTODO: el percentil más próximo por rango, sobre la muestra ordenada. No
 * se interpola. Interpolar entre dos peticiones reales inventa una duración
 * que nadie esperó, y con unos cientos de muestras la diferencia es menor que
 * el ruido de medir con `microtime`. Lo que importa es que el método esté
 * escrito: dos informes que usan convenciones distintas dan números distintos
 * para la misma tarde y nadie sabe cuál creer.
 */
class PercentilesDeLatencia
{
    /**
     * @param  list<float>  $muestras
     */
    public static function percentil(array $muestras, float $p): float
    {
        if ($muestras === []) {
            throw new \InvalidArgumentException('No hay muestras que resumir.');
        }

        if ($p < 0 || $p > 100) {
            throw new \InvalidArgumentException("Un percentil va de 0 a 100, no {$p}.");
        }

        sort($muestras);

        /*
         * El rango por redondeo hacia arriba, el método de «nearest rank».
         * Con p = 0 el índice saldría -1, así que se sujeta en 0: el
         * percentil 0 es el mínimo.
         */
        $rango = (int) ceil(($p / 100) * count($muestras));

        return $muestras[max(0, $rango - 1)];
    }

    /**
     * Agrupa las líneas por verbo y patrón de ruta, y resume cada grupo.
     *
     * @param  list<array{ruta:string,verbo:string,estado:int,ms:float,consultas:int,ms_base:float}>  $lineas
     * @return list<array<string,mixed>>
     */
    public static function porRuta(array $lineas, int $minimoParaPercentil = 20): array
    {
        $grupos = [];

        foreach ($lineas as $l) {
            $clave = $l['verbo'].' '.$l['ruta'];
            $grupos[$clave][] = $l;
        }

        $resumen = [];

        foreach ($grupos as $clave => $suyas) {
            $ms = array_map(fn ($l) => (float) $l['ms'], $suyas);
            $n = count($ms);

            /*
             * CON POCAS MUESTRAS NO SE IMPRIME UN PERCENTIL, se imprime null.
             *
             * Con cinco peticiones, `ceil(0.99 * 5) = 5`: el p99 es el
             * máximo, y el p95 también, y el p90 también. Los tres números
             * serían el mismo valor con tres nombres distintos, y quien lea
             * el informe entenderá que esa ruta tiene una cola fea cuando lo
             * único que pasa es que se llamó cinco veces. El dato honesto es
             * «no hay bastantes muestras».
             */
            $bastantes = $n >= $minimoParaPercentil;

            $resumen[] = [
                'ruta' => $suyas[0]['ruta'],
                'verbo' => $suyas[0]['verbo'],
                'n' => $n,
                'p50' => $bastantes ? self::percentil($ms, 50) : null,
                'p95' => $bastantes ? self::percentil($ms, 95) : null,
                'p99' => $bastantes ? self::percentil($ms, 99) : null,
                'max' => max($ms),
                'consultas_max' => max(array_map(fn ($l) => (int) $l['consultas'], $suyas)),
                // Cuánto de la espera es base de datos. Es lo que decide si
                // la ruta se arregla con un índice o con otra cosa.
                'ms_base_mediana' => self::percentil(
                    array_map(fn ($l) => (float) $l['ms_base'], $suyas),
                    50
                ),
                'errores' => count(array_filter($suyas, fn ($l) => (int) $l['estado'] >= 500)),
            ];
        }

        /*
         * Por p95 descendente, y las que no tienen bastantes muestras al
         * final por su máximo: el informe se lee de arriba abajo y arriba
         * tiene que estar lo que de verdad se sabe que es lento.
         */
        usort($resumen, function ($a, $b) {
            if (($a['p95'] === null) !== ($b['p95'] === null)) {
                return $a['p95'] === null ? 1 : -1;
            }

            return ($b['p95'] ?? $b['max']) <=> ($a['p95'] ?? $a['max']);
        });

        return $resumen;
    }

    /**
     * Lee las líneas de un fichero del canal `latencia`.
     *
     * Formato de Monolog: `[fecha] entorno.INFO: peticion {"ruta":…}`. Se
     * busca el PRIMER `{` y se decodifica de ahí al final, en vez de partir
     * por `: ` —que aparece dentro del JSON— o de una expresión regular que
     * tendría que entender llaves anidadas.
     *
     * @return list<array<string,mixed>>
     */
    public static function leer(string $ruta): array
    {
        $lineas = [];

        $f = fopen($ruta, 'r');

        if ($f === false) {
            throw new \RuntimeException("No se pudo abrir {$ruta}.");
        }

        while (($linea = fgets($f)) !== false) {
            $llave = strpos($linea, '{');

            if ($llave === false) {
                continue;
            }

            $datos = json_decode(substr($linea, $llave), true);

            // Una línea cortada por la rotación a mitad de escritura, o una
            // línea de otra cosa que acabó en el mismo fichero: se salta sin
            // tumbar el informe.
            if (! is_array($datos) || ! isset($datos['ruta'], $datos['ms'])) {
                continue;
            }

            $lineas[] = $datos;
        }

        fclose($f);

        return $lineas;
    }
}
