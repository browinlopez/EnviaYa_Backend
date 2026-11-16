<?php

declare(strict_types=1);

/**
 * DOS MANERAS DE ESCRIBIR UNA PRUEBA QUE NO PRUEBA NADA, Y LAS DOS PASARON.
 *
 * El 13 de noviembre de 2026 la suite se corrió por primera vez en local
 * contra MySQL 8 —hasta entonces solo SQLite en memoria y un flujo de CI que
 * nadie había mirado de cerca—. De 71 pruebas de rendimiento y concurrencia,
 * **cuatro fallaron, y ninguna por un fallo del código**. Tres eran de estos
 * dos moldes. Este archivo los vigila, porque los dos se escriben sin querer
 * y los dos salen verdes.
 *
 * ──────────────────────────────────────────────────────────────────────
 * 1. COMPARAR SQL CON EL ENTRECOMILLADO DE UN MOTOR
 * ──────────────────────────────────────────────────────────────────────
 *
 * SQLite escribe `select ... from "business"` y MySQL escribe
 * `select ... from ` + acentos graves. Una prueba que compara con la forma de
 * uno NO CASA NUNCA con la del otro:
 *
 *     str_contains($q->sql, 'from "business" where "business"."busines_id" in')
 *
 * Sobre SQLite contaba 1 y pasaba; sobre MySQL contaba 0 y fallaba. Y el
 * sentido de la prueba —que esa fila no se pida dos veces— no depende del
 * motor para nada: lo único que dependía era la escritura.
 *
 * Lo malo no es que falle en MySQL. Es lo que significa el día que alguien
 * cambie de motor o lo meta en el CI: un puñado de rojos que no son defectos,
 * y la conclusión fácil de que «las pruebas de MySQL no sirven».
 *
 * La solución es una línea: quitar las comillas de los identificadores antes
 * de comparar. La comparación sigue siendo exacta y dice lo mismo en los dos
 * motores.
 *
 * ──────────────────────────────────────────────────────────────────────
 * 2. ACUMULAR DENTRO DE UNA FUNCIÓN FLECHA
 * ──────────────────────────────────────────────────────────────────────
 *
 *     $sentencias = [];
 *     DB::listen(fn ($q) => $sentencias[] = strtolower($q->sql));
 *
 * `fn` captura el ámbito de fuera POR VALOR. Eso llena una copia, y
 * `$sentencias` se queda vacío para siempre. La prueba que lo usaba
 * —`PedidoBajoLlaveTest`, que vigila que el pedido se lea con `for update`
 * para que dos personas no despachen el mismo— **no podía pasar**: su
 * `expect([])->not->toBeEmpty()` fallaba dijera lo que dijera el motor.
 *
 * Y NO SE NOTÓ PORQUE ESTABA SALTADA. `for update` solo lo emite MySQL, así
 * que la prueba se saltaba en SQLite con un mensaje razonable. Un
 * `markTestSkipped` es un sitio donde una prueba puede llevar meses rota sin
 * que nada lo diga: lo que la suite mide ahí no es si pasa, es si se salta.
 *
 * POR ESO ESTE ARCHIVO NO SE SALTA NUNCA: es texto, no base de datos.
 */
it('ninguna prueba compara SQL con el entrecomillado de un motor', function () {
    $raiz = base_path('tests');
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS)
    );

    /*
     * Un identificador entrecomillado dentro de una cadena de PHP: `from
     * "tabla"`, `"tabla"."columna"`. Se busca solo en líneas que comparan SQL
     * --`str_contains`, `===`, `$exactas(`-- para no marcar la prosa de los
     * comentarios, que SÍ cuenta la historia con comillas a propósito.
     */
    $rx = '~(str_contains|str_starts_with|preg_match|===|\$exactas\()[^\n]*'
        .'(from|join|into|update|table)\s+\\\\?"[a-z_]+\\\\?"~i';

    $sospechosas = [];
    $revisados = 0;

    foreach ($it as $fichero) {
        if ($fichero->getExtension() !== 'php') {
            continue;
        }

        /*
         * ESTE ARCHIVO NO SE REVISA A SI MISMO, y no es una excusa: sus
         * controles LLEVAN las dos formas malas escritas como cadenas, porque
         * la unica manera de probar que el patron las reconoce es darselas.
         * La primera version se marcaba a si misma --dos rojos, los dos en sus
         * propias lineas de control-- que es justo lo que pasa cuando un
         * vigilante se mete en la lista de vigilados.
         */
        if ($fichero->getFilename() === basename(__FILE__)) {
            continue;
        }

        $revisados++;
        $lineas = file($fichero->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lineas as $n => $linea) {
            // La prosa de los docbloques y los comentarios no se juzga.
            $limpia = ltrim($linea);

            if (str_starts_with($limpia, '*') || str_starts_with($limpia, '//')
                || str_starts_with($limpia, '/*')) {
                continue;
            }

            if (preg_match($rx, $linea)) {
                $sospechosas[] = basename($fichero->getPathname()).':'.($n + 1);
            }
        }
    }

    /* CONTROL: que haya leído las pruebas de verdad. Con la carpeta vacía o un
       recorrido roto, «ninguna sospechosa» es verdad sin significar nada. */
    expect($revisados)->toBeGreaterThan(100);

    /* Y que el patrón reconozca la forma mala y NO la buena. Sin estas dos, la
       prueba pasaría igual con una expresión regular que no casa con nada. */
    expect(preg_match($rx, 'str_contains($q->sql, \'from "business" where x\')'))->toBe(1);
    expect(preg_match($rx, 'str_contains($sql, \'from business where x\')'))->toBe(0);

    expect($sospechosas)->toBe([]);
});

it('ninguna prueba acumula dentro de una funcion flecha', function () {
    $raiz = base_path('tests');
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS)
    );

    /*
     * `fn (...) => $algo[] = ...` o `fn (...) => $algo++`: las dos escriben en
     * una copia. Lo que SÍ vale dentro de una flecha es devolver un valor, que
     * es para lo que está.
     */
    $rx = '~\bfn\s*\([^)]*\)\s*=>\s*\$\w+\s*(\[\s*\]\s*=[^=]|\+\+|--|\.=|\+=)~';

    $malas = [];
    $revisados = 0;

    foreach ($it as $fichero) {
        if ($fichero->getExtension() !== 'php') {
            continue;
        }

        /*
         * ESTE ARCHIVO NO SE REVISA A SI MISMO, y no es una excusa: sus
         * controles LLEVAN las dos formas malas escritas como cadenas, porque
         * la unica manera de probar que el patron las reconoce es darselas.
         * La primera version se marcaba a si misma --dos rojos, los dos en sus
         * propias lineas de control-- que es justo lo que pasa cuando un
         * vigilante se mete en la lista de vigilados.
         */
        if ($fichero->getFilename() === basename(__FILE__)) {
            continue;
        }

        $revisados++;
        $lineas = file($fichero->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lineas as $n => $linea) {
            $limpia = ltrim($linea);

            if (str_starts_with($limpia, '*') || str_starts_with($limpia, '//')
                || str_starts_with($limpia, '/*')) {
                continue;
            }

            if (preg_match($rx, $linea)) {
                $malas[] = basename($fichero->getPathname()).':'.($n + 1);
            }
        }
    }

    expect($revisados)->toBeGreaterThan(100);

    /* Las dos mitades del control, con el caso real que lo motivó. */
    expect(preg_match($rx, 'DB::listen(fn ($q) => $sentencias[] = $q->sql);'))->toBe(1);
    expect(preg_match($rx, 'DB::listen(function ($q) use (&$s) { $s[] = $q->sql; });'))->toBe(0);
    expect(preg_match($rx, '$mapeado = array_map(fn ($x) => $x->id, $filas);'))->toBe(0);

    expect($malas)->toBe([]);
});
