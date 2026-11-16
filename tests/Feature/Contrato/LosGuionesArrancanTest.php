<?php

declare(strict_types=1);

/**
 * LOS GUIONES DE `scripts/` PUEDEN ARRANCAR.
 *
 * ESTO EXISTE PORQUE EL MISMO FALLO PASÓ TRES VECES, en tres guiones
 * distintos, y las tres pasaron `php -l`.
 *
 * Los guiones sueltos arrancan Laravel a mano:
 *
 *     require vendor/autoload.php;
 *     $app = require bootstrap/app.php;
 *     $app->make(Kernel::class)->bootstrap();
 *
 * Pint acorta `Illuminate\Contracts\Console\Kernel::class` a `Kernel::class`
 * apoyándose en el `use`. Si ese `use` queda **debajo** de la línea que lo
 * usa, PHP resuelve `Kernel` como clase global y revienta en tiempo de
 * ejecución con «Class "Kernel" does not exist». La sintaxis es correcta, así
 * que `php -l` dice que todo está bien.
 *
 * Los tres casos:
 *
 *   percentiles_de_latencia.php   roto al crearlo, arreglado el 11 de nov
 *   openapi.php                   roto por Pint minutos después de escribirlo
 *   anotar_modelos.php            llevaba roto desde antes, y nadie lo sabía
 *                                 porque ningún sitio lo llama
 *
 * El tercero es el que justifica una prueba en vez de tres arreglos: un
 * guion que nada llama no se descubre roto hasta que alguien lo necesita, y
 * eso suele ser el día que hace falta.
 *
 * SE COMPRUEBA EL ORDEN Y NO SE EJECUTAN LOS GUIONES, a propósito: varios
 * escriben —`anotar_modelos.php --escribir` toca los modelos, el de la línea
 * base vacía el registro de latencia— y una prueba no puede tener esos
 * efectos. El orden es lo que falla, así que el orden es lo que se mira.
 */
it('en los guiones de scripts, los use van antes del arranque', function () {
    $malos = [];
    $revisados = 0;

    foreach (glob(base_path('scripts/*.php')) ?: [] as $ruta) {
        $lineas = file($ruta, FILE_IGNORE_NEW_LINES) ?: [];

        // La línea del arranque: es la que revienta.
        $arranque = null;

        foreach ($lineas as $i => $linea) {
            if (str_contains($linea, '->make(') && str_contains($linea, 'Kernel::class')) {
                $arranque = $i;
                break;
            }
        }

        if ($arranque === null) {
            // No arranca Laravel: no le aplica.
            continue;
        }

        $revisados++;

        foreach ($lineas as $i => $linea) {
            if (preg_match('/^use [A-Z]/', $linea) && $i > $arranque) {
                $malos[] = basename($ruta).': «'.trim($linea).'» está en la línea '
                    .($i + 1).', debajo del arranque (línea '.($arranque + 1).')';
            }
        }
    }

    // Que de verdad encontró guiones que arrancan Laravel: con un glob roto,
    // `$malos` sale vacío y la prueba diría que todo está bien sin haber
    // mirado nada.
    expect($revisados)->toBeGreaterThanOrEqual(3);

    expect($malos)->toBe([]);
});

it('la prueba de arriba encuentra el orden mal cuando lo hay', function () {
    /*
     * LA PASADA DE CONTROL. Se escribe un guion con el `use` debajo del
     * arranque —exactamente la forma que estuvo rota tres veces— y se
     * comprueba que la regla lo señala.
     */
    $falso = sys_get_temp_dir().'/guion_'.uniqid().'.php';

    file_put_contents($falso, implode("\n", [
        '<?php',
        '',
        "require 'vendor/autoload.php';",
        '',
        '$app->make(Kernel::class)->bootstrap();',
        '',
        'use Illuminate\Contracts\Console\Kernel;',
        '',
    ]));

    $lineas = file($falso, FILE_IGNORE_NEW_LINES) ?: [];
    @unlink($falso);

    $arranque = null;

    foreach ($lineas as $i => $linea) {
        if (str_contains($linea, '->make(') && str_contains($linea, 'Kernel::class')) {
            $arranque = $i;
            break;
        }
    }

    expect($arranque)->not->toBeNull();

    $malos = [];

    foreach ($lineas as $i => $linea) {
        if (preg_match('/^use [A-Z]/', $linea) && $i > $arranque) {
            $malos[] = trim($linea);
        }
    }

    expect($malos)->toHaveCount(1);
});
