<?php

/**
 * NINGUNA LLAMADA HTTP DE SALIDA SIN TOPE DE TIEMPO
 *
 * Es la regla que el estándar pide en §36 y la que este repositorio ya ha roto
 * tres veces, en tres sitios distintos y con la misma consecuencia:
 *
 *   · `BoldService` — siete llamadas sin `timeout`, dentro de la petición del
 *     comprador. Bastaba que Bold TARDARA para agotar los trabajadores de
 *     Octane y tumbar el backend entero, no solo los pagos.
 *   · Reverb — `client_options` vacío, con seis de siete eventos en
 *     `ShouldBroadcastNow`, o sea dentro de la petición.
 *   · El cliente de R2 — sin una sola opción de `http`, con las subidas de
 *     imágenes dentro de la petición. Medido: 63,6 s contra una dirección no
 *     enrutable, y SIN FIN contra un servidor que acepta y se calla.
 *
 * Las tres se arreglaron una por una, cada vez con la sensación de haber
 * terminado. Esta prueba es lo que convierte eso en una regla: recorre el
 * código y exige que toda cadena que empiece en `Http::` lleve `timeout` y
 * `connectTimeout`.
 *
 * POR QUÉ LOS DOS, y no solo `timeout`. `timeout` acota la operación entera, así
 * que por sí solo ya cierra el agujero: con él no hay espera infinita. Pero
 * decide también cuánto se tarda en RENDIRSE con un host caído, y ahí los dos
 * números hacen cosas distintas: `connectTimeout` corta en el saludo —un host
 * que no está se descarta en tres segundos en vez de en quince— y `timeout`
 * cubre la respuesta lenta. Dos de estas llamadas van dentro de la petición
 * (`TrazoDeLaRuta` desde `RutaDelPedidoController`, y el catálogo externo
 * cuando el tendero escanea un código), y con un grupo fijo de trabajadores la
 * diferencia entre tres y quince segundos es cuántas peticiones caben antes de
 * que no quepa ninguna.
 *
 * LO QUE ESTA PRUEBA NO ALCANZA, dicho para que nadie la crea más de lo que es:
 * solo ve `Http::`. Comprobado que hoy basta —no hay Guzzle crudo, ni
 * `curl_init`, ni `file_get_contents` sobre http, ni sockets en `app/`— pero el
 * día que entre un SDK con su propio cliente, esta prueba seguirá en verde sin
 * haberlo mirado. Fue exactamente lo que pasó con R2: el agujero estaba en la
 * configuración de un disco, donde no hay ningún `Http::` que ver, y lo fijan
 * las pruebas de `TopesDelAlmacenamientoTest`.
 */
function llamadasHttpDelCodigo(): array
{
    $llamadas = [];

    $iterador = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path())
    );

    foreach ($iterador as $fichero) {
        if ($fichero->getExtension() !== 'php') {
            continue;
        }

        $texto = file_get_contents($fichero->getPathname());

        /*
         * SIN LOS COMENTARIOS. Un `Http::` dentro de un comentario que explica
         * cómo se llamaba antes a Bold haría roja esta prueba por la prosa que
         * la documenta — es el mismo tropiezo que dio el guardia de las
         * direcciones de correo de pega, y se arregla igual.
         */
        $codigo = '';

        foreach (token_get_all($texto) as $pieza) {
            if (is_array($pieza) && in_array($pieza[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $codigo .= is_array($pieza) ? $pieza[1] : $pieza;
        }

        // Desde `Http::` hasta el `;` que cierra la cadena, que es donde viven
        // los topes: `Http::connectTimeout(3)->timeout(8)->get(...)`.
        preg_match_all('/Http::(.*?);/s', $codigo, $coincidencias);

        foreach ($coincidencias[0] as $cadena) {
            $llamadas[] = [
                'fichero' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $fichero->getPathname()),
                'cadena' => $cadena,
            ];
        }
    }

    return $llamadas;
}

it('encuentra las llamadas HTTP que hay, para no pasar en verde sin mirar nada', function () {
    /*
     * EL CONTROL DEL DETECTOR, y no es ceremonia: si mañana alguien cambia la
     * forma de llamar y la expresión deja de emparejar, las dos pruebas de
     * abajo pasarían en verde sobre una lista vacía. Un detector que no
     * encuentra nada y dice que todo está bien es peor que ninguno.
     */
    $llamadas = llamadasHttpDelCodigo();

    /*
     * SEIS ES EL NUMERO DE HOY, y baja solo si alguien quita una llamada: eso
     * es una decision que merece mirarse, no un detalle que deba pasar
     * callado. Lo que no se admite es que baje porque el detector dejo de
     * emparejar.
     */
    expect(count($llamadas))->toBeGreaterThanOrEqual(6);

    $ficheros = array_unique(array_column($llamadas, 'fichero'));

    /*
     * `BoldService` SE NOMBRA A PROPOSITO, y no por completitud.
     *
     * Esta misma linea encontro el fallo mas grave de toda la jornada. El
     * detector devolvia cinco llamadas y ninguna era de Bold, y la pasarela
     * tiene siete: porque `peticion()` se habia quedado llamandose a si misma
     * y en el fichero ya no quedaba un solo `Http::`. La ausencia de un
     * `Http::` donde tiene que haberlo era el sintoma de una recursion
     * infinita que mataba el proceso en cada pago.
     */
    expect(implode(' ', $ficheros))
        ->toContain('BoldService.php')
        ->toContain('PushFirebase.php');
});

it('ninguna llamada HTTP de salida se hace sin timeout', function () {
    $sinTope = [];

    foreach (llamadasHttpDelCodigo() as $l) {
        if (! str_contains($l['cadena'], 'timeout(')) {
            $sinTope[] = $l['fichero'].': '.trim(explode("\n", $l['cadena'])[0]);
        }
    }

    expect($sinTope)->toBe([]);
});

it('ninguna llamada HTTP de salida se hace sin connectTimeout', function () {
    $sinConnect = [];

    foreach (llamadasHttpDelCodigo() as $l) {
        if (! str_contains($l['cadena'], 'connectTimeout(')) {
            $sinConnect[] = $l['fichero'].': '.trim(explode("\n", $l['cadena'])[0]);
        }
    }

    expect($sinConnect)->toBe([]);
});
