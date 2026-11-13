<?php

/**
 * LA SUPERFICIE DE LA API NO CAMBIA PORQUE SE MUEVA UN ARCHIVO.
 *
 * Esta prueba existe para poder reorganizar el backend sin romper a nadie.
 * Congela las 325 rutas de `/v1` en `superficie-api.json`: verbo, URI, nombre
 * y middleware. Mover un controlador a otra carpeta, cambiarle el namespace o
 * partirlo en varios no toca ninguna de esas cuatro cosas, así que la prueba
 * sigue en verde. Borrar una ruta, cambiarle la URI o quitarle el middleware
 * que la protege, sí la rompe.
 *
 * LO QUE NO SE CONGELA, A PROPÓSITO: la clase y el método que atienden. Eso es
 * justo lo que una reorganización tiene permitido cambiar, y de que exista ya
 * se ocupa `Areas/RutasSinDestinoTest`.
 *
 * EL MIDDLEWARE ES LA MITAD DEL VALOR. Un refactor que deje una ruta sin
 * `EnsureBusinessOwner` no falla ninguna prueba funcional —el endpoint sigue
 * contestando— y abre los datos de una tienda a cualquiera con sesión. Acá se
 * nota en el acto.
 *
 * Si un cambio de contrato es querido, se regenera el archivo EN EL MISMO PR
 * y la revisión ve la diferencia línea a línea, que es exactamente el momento
 * en que alguien tiene que preguntarse a qué cliente publicado le afecta.
 *
 *     CONGELAR_SUPERFICIE=1 vendor/bin/pest tests/Feature/Contrato/
 *
 * El archivo lo escribe ESTA prueba y no `route:list`, porque los dos hablan
 * vocabularios distintos: `route:list` resuelve los alias a nombres de clase
 * (`App\Http\Middleware\EnsureAdmin`) y el router los devuelve como se
 * escribieron (`admin`). Comparar uno con otro daba 135 rutas «sin
 * middleware» que lo tenían entero.
 */

use Illuminate\Support\Facades\Route;

/** Las rutas vivas de `/v1`, en la misma forma que el archivo congelado. */
function superficieViva(): array
{
    $filas = [];

    foreach (Route::getRoutes() as $ruta) {
        $uri = $ruta->uri();

        if (! str_starts_with($uri, 'v1/')) {
            continue;
        }

        $verbos = array_values(array_diff($ruta->methods(), ['HEAD']));

        /*
         * EFECTIVO = lo reunido MENOS lo excluido, y esto es un arreglo con
         * fecha: 13 de noviembre de 2026.
         *
         * `gatherMiddleware()` junta el de la ruta y el del controlador y NO
         * resta `excludedMiddleware()`. Así que un
         * `->withoutMiddleware('auth')` dejaba la ruta sin guardia EN
         * EJECUCION y el congelado seguía diciendo que lo tenía: la prueba
         * pasaba en verde con la puerta abierta.
         *
         * Lo destapó un sabotaje que NO falló: se le quitó el guardia a
         * `DELETE /profile` --la baja de la cuenta-- y las tres pruebas
         * siguieron verdes. Una prueba que no se rompe cuando debería es peor
         * que no tenerla, porque se cita como garantía.
         *
         * Hoy nadie usa `withoutMiddleware` en este repositorio, así que al
         * arreglarlo los dos archivos congelados no cambian ni una línea: el
         * arreglo no es por lo que hay, es por lo que se puede escribir
         * mañana en una línea y sin que nada chille.
         */
        $middleware = array_values(array_diff(
            $ruta->gatherMiddleware(),
            $ruta->excludedMiddleware()
        ));
        sort($middleware);

        $filas[] = [
            'verbo' => implode('|', $verbos),
            'uri' => $uri,
            'nombre' => $ruta->getName(),
            'middleware' => array_values($middleware),
        ];
    }

    usort($filas, fn ($a, $b) => [$a['uri'], $a['verbo']] <=> [$b['uri'], $b['verbo']]);

    return $filas;
}

/**
 * El archivo congelado. Si se pide regenerarlo, se reescribe y se devuelve lo
 * nuevo: el PR enseña la diferencia línea a línea, que es cuando alguien tiene
 * que preguntarse a qué cliente publicado le afecta.
 */
function superficieCongelada(): array
{
    $ruta = __DIR__.'/superficie-api.json';

    if (env('CONGELAR_SUPERFICIE')) {
        $viva = superficieViva();
        file_put_contents(
            $ruta,
            json_encode($viva, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'
',
        );

        return $viva;
    }

    return json_decode(file_get_contents($ruta), true, flags: JSON_THROW_ON_ERROR);
}

test('ninguna ruta de la API aparece, desaparece ni cambia de URI', function () {
    $congelada = superficieCongelada();

    $clave = fn (array $f) => $f['verbo'].' '.$f['uri'];

    $antes = array_map($clave, $congelada);
    $ahora = array_map($clave, superficieViva());

    /* Se dicen las dos listas por separado y no un `toBe` del conjunto: con
       325 rutas, un diff crudo es ilegible y lo que hace falta saber es cuál
       sobra y cuál falta. */
    expect(array_values(array_diff($antes, $ahora)))->toBe([], 'rutas que desaparecieron');
    expect(array_values(array_diff($ahora, $antes)))->toBe([], 'rutas nuevas sin congelar');
});

test('ninguna ruta pierde el middleware que la protege', function () {
    $congelada = superficieCongelada();

    $porClave = [];
    foreach (superficieViva() as $f) {
        $porClave[$f['verbo'].' '.$f['uri']] = $f['middleware'];
    }

    $perdidos = [];

    foreach ($congelada as $f) {
        $clave = $f['verbo'].' '.$f['uri'];

        // Que haya desaparecido lo dice la prueba de arriba; acá no se repite.
        if (! isset($porClave[$clave])) {
            continue;
        }

        $falta = array_diff($f['middleware'], $porClave[$clave]);

        if ($falta !== []) {
            $perdidos[] = $clave.' → le falta '.implode(', ', $falta);
        }
    }

    expect($perdidos)->toBe([]);
});

test('ninguna ruta pierde su nombre', function () {
    // Los nombres los usan `route()` y las pruebas; perderlos en una mudanza
    // de archivos rompe llamadas que no fallan al compilar.
    $congelada = superficieCongelada();

    $porClave = [];
    foreach (superficieViva() as $f) {
        $porClave[$f['verbo'].' '.$f['uri']] = $f['nombre'];
    }

    $perdidos = [];

    foreach ($congelada as $f) {
        $clave = $f['verbo'].' '.$f['uri'];

        if (isset($porClave[$clave]) && $f['nombre'] !== null && $porClave[$clave] !== $f['nombre']) {
            $perdidos[] = "{$clave} → se llamaba «{$f['nombre']}» y ahora «{$porClave[$clave]}»";
        }
    }

    expect($perdidos)->toBe([]);
});
