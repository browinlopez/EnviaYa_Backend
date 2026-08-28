<?php

/**
 * NINGUNA RUTA APUNTA A UN MÉTODO QUE NO EXISTE.
 *
 * Una ruta registrada contra un controlador que ya no tiene ese método no
 * falla al arrancar: falla el día que alguien la llama, con un 500 y una traza
 * en producción. Es la clase de huérfano que no se nota hasta que se nota mal.
 *
 * Se comprueban las 280 rutas de `/v1` de una vez, que es más barato que
 * confiar en que nadie renombre un método al refactorizar.
 */

use Illuminate\Support\Facades\Route;

test('cada ruta de la API resuelve a un metodo que existe', function () {
    $rotas = [];

    foreach (Route::getRoutes() as $ruta) {
        $accion = $ruta->getAction('uses');

        // Closures: no hay nada que comprobar.
        if (!is_string($accion) || !str_contains($accion, '@')) {
            continue;
        }

        [$clase, $metodo] = explode('@', $accion, 2);

        if (!class_exists($clase)) {
            $rotas[] = "{$ruta->uri()} → la clase {$clase} no existe";
            continue;
        }

        if (!method_exists($clase, $metodo)) {
            $rotas[] = "{$ruta->uri()} → {$clase}::{$metodo}() no existe";
        }
    }

    expect($rotas)->toBe([]);
});

/**
 * Y ningún alias de middleware apunta a una clase que no está.
 *
 * Mismo caso una capa más abajo: `->middleware('conjunto')` con el alias mal
 * escrito revienta en tiempo de petición, no al arrancar.
 */
test('cada middleware con nombre existe', function () {
    $router  = app('router');
    $alias   = $router->getMiddleware();
    $faltan  = [];

    foreach ($alias as $nombre => $clase) {
        if (!class_exists($clase)) {
            $faltan[] = "{$nombre} → {$clase}";
        }
    }

    foreach (Route::getRoutes() as $ruta) {
        foreach ($ruta->gatherMiddleware() as $mw) {
            if (!is_string($mw)) {
                continue;
            }

            $base = explode(':', $mw)[0];

            if (class_exists($base) || isset($alias[$base])) {
                continue;
            }

            // Los grupos ('api', 'web') los resuelve el router aparte.
            if (in_array($base, ['api', 'web'], true)) {
                continue;
            }

            $faltan[] = "{$ruta->uri()} usa el middleware desconocido «{$base}»";
        }
    }

    expect(array_values(array_unique($faltan)))->toBe([]);
});
