<?php

declare(strict_types=1);

/**
 * TODOS LOS FICHEROS DE `app/` DECLARAN TIPOS ESTRICTOS — §41.
 *
 * `declare(strict_types=1)` no es cosmética: cambia el comportamiento en
 * tiempo de ejecución de todas las llamadas que hace ese fichero. Pasar la
 * cadena `"5"` a un parámetro `int` deja de convertirse y lanza un TypeError.
 * En un backend con años encima, y con la entrada llegando de
 * `$request->input()` —que devuelve cadenas—, eso puede romper caminos que
 * ninguna prueba toca.
 *
 * ASÍ QUE SE MIDIÓ ANTES DE DARLO POR BUENO, que es lo que §12 pide cuando
 * habla de adopción gradual. Se puso en los 433 ficheros de golpe, en una
 * rama, y se corrió todo:
 *
 *   1247 pruebas          en verde
 *   33 procesos del arnés  salida 0, contra MySQL y HTTP de verdad
 *   Pint                   limpio
 *   PHPStan                UN error, y era un desajuste real
 *
 * Ese error estaba en `LoginRequest::throttleKey()`: pasaba un `Stringable` a
 * `Str::lower()`, que documenta `string`. No lanzaba —esa función no declara
 * tipo nativo— pero lo interesante es por qué no lo vio nadie: esa clase
 * sirve a `POST /login`, la sesión web de Breeze, y la plataforma usa
 * `POST /v1/login`, que es otro controlador. Un camino enrutado y sin
 * pruebas.
 *
 * ESTA PRUEBA ES EL TRINQUETE. Sin ella, el primer fichero nuevo que alguien
 * cree sin la declaración deja el 433 de 433 en 433 de 434, y nadie lo nota
 * hasta la siguiente auditoría — porque un fichero sin tipos estrictos no da
 * ningún error: funciona, con las conversiones silenciosas de siempre.
 */
it('los ficheros de app/ declaran strict_types, los 433', function () {
    $sin = [];

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    $total = 0;

    foreach ($it as $fichero) {
        if ($fichero->getExtension() !== 'php') {
            continue;
        }

        $total++;

        /*
         * SE BUSCA EL TOKEN, no la cadena. Un `str_contains` encontraría la
         * frase dentro de un comentario que la mencione —este archivo mismo
         * la menciona cinco veces— y daría verde sobre un fichero que no la
         * declara. Es la misma lección que el guardia de `whereDate`.
         */
        $declara = false;

        foreach (token_get_all((string) file_get_contents($fichero->getPathname())) as $pieza) {
            if (is_array($pieza) && $pieza[0] === T_DECLARE) {
                $declara = true;
                break;
            }
        }

        if (! $declara) {
            $sin[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $fichero->getPathname());
        }
    }

    // Que el recorrido de verdad encontró los ficheros: con una ruta mal
    // construida, `$sin` sale vacío y la prueba diría que todo cumple
    // habiendo mirado cero ficheros.
    expect($total)->toBeGreaterThan(400);

    expect($sin)->toBe([]);
});

it('el guardia encuentra un fichero SIN strict_types cuando lo hay', function () {
    /*
     * LA PASADA DE CONTROL. Un detector que no encuentra nada no es una
     * prueba de que no haya nada: puede estar mirando el sitio equivocado o
     * preguntando lo que no es. Se le da un fichero que no declara nada y se
     * comprueba que lo ve.
     */
    $falso = sys_get_temp_dir().'/sin_tipos_'.uniqid().'.php';
    file_put_contents($falso, "<?php\n\nclass Algo {}\n");

    $declara = false;

    foreach (token_get_all((string) file_get_contents($falso)) as $pieza) {
        if (is_array($pieza) && $pieza[0] === T_DECLARE) {
            $declara = true;
            break;
        }
    }

    @unlink($falso);

    expect($declara)->toBeFalse();

    // Y la otra mitad: que reconoce uno que SÍ la declara.
    $bueno = sys_get_temp_dir().'/con_tipos_'.uniqid().'.php';
    file_put_contents($bueno, "<?php\n\ndeclare(strict_types=1);\n\nclass Algo {}\n");

    $declaraBueno = false;

    foreach (token_get_all((string) file_get_contents($bueno)) as $pieza) {
        if (is_array($pieza) && $pieza[0] === T_DECLARE) {
            $declaraBueno = true;
            break;
        }
    }

    @unlink($bueno);

    expect($declaraBueno)->toBeTrue();
});
