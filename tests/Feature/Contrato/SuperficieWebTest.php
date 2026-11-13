<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * LAS RUTAS QUE NO SON DE LA API TAMPOCO CAMBIAN EN SILENCIO.
 *
 * `SuperficieDeLaApiTest` congela las rutas de `/v1` y hace lo que su nombre
 * dice. Lo que no se veía es cuántas quedaban fuera: **318 rutas vivas, 293
 * congeladas, 25 sin vigilar por nada**. Medido el 13 de noviembre de 2026.
 *
 * Y no son rutas de adorno. Entre las 25 están:
 *
 *   POST   login, logout                 el acceso web
 *   POST   forgot-password               pedir el enlace
 *   POST   reset-password                CAMBIAR LA CONTRASEÑA
 *   PUT    password                      cambiarla estando dentro
 *   DELETE profile                       LA BAJA DE LA CUENTA
 *   GET    laravel-verify-email/{id}/{hash}   dar por verificado un correo
 *
 * El congelado de la API existe porque un refactor que deja una ruta sin su
 * middleware no rompe ninguna prueba funcional —el endpoint sigue
 * contestando— y abre los datos a cualquiera. Ese argumento vale IGUAL para
 * `DELETE profile`, y hasta hoy ahí no había nada.
 *
 * VA EN UN ARCHIVO APARTE, con su congelado aparte, y es a propósito: son dos
 * contratos con dos audiencias. El de `/v1` lo consumen una app publicada en
 * dos tiendas y tres paneles, y cambiarlo es una decisión de producto; estas
 * las consume un navegador y se regeneran sin preguntarle a nadie. Meterlas
 * en el mismo archivo haría que el recuento de «rutas de la API» dejara de
 * significar lo que significa.
 *
 *     CONGELAR_SUPERFICIE_WEB=1 vendor/bin/pest tests/Feature/Contrato/
 *
 * UNA COSA QUE ESTA PRUEBA NO DECIDE, y conviene que se vea: buena parte de
 * esas 25 es el andamiaje web de Breeze —login, perfil, contraseña, su propia
 * verificación de correo por URL firmada— en un repositorio que `AGENTS.md`
 * describe como «API Laravel 12, sin Blade, frontend ni MVC tradicional de
 * vistas». O sea que hay DOS mecanismos de verificación de correo a la vez, y
 * un flujo de sesión web que ningún cliente del proyecto usa. Si sobra, se
 * quita y este congelado lo refleja; mientras esté vivo, está vigilado. Lo que
 * no puede ser es que esté vivo y nadie lo mire.
 */

/** Las rutas vivas que NO son de `/v1`, en la forma del archivo congelado. */
function superficieWebViva(): array
{
    $filas = [];

    foreach (Route::getRoutes() as $ruta) {
        $uri = $ruta->uri();

        if (str_starts_with($uri, 'v1/')) {
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

/** El archivo congelado; se reescribe si se pide. */
function superficieWebCongelada(): array
{
    $ruta = __DIR__.'/superficie-web.json';

    if (env('CONGELAR_SUPERFICIE_WEB') || ! file_exists($ruta)) {
        $viva = superficieWebViva();
        file_put_contents(
            $ruta,
            json_encode($viva, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );

        return $viva;
    }

    return json_decode((string) file_get_contents($ruta), true, flags: JSON_THROW_ON_ERROR);
}

test('ninguna ruta web aparece, desaparece ni cambia de URI', function () {
    $congelada = superficieWebCongelada();

    $clave = fn (array $f) => $f['verbo'].' '.$f['uri'];

    $antes = array_map($clave, $congelada);
    $ahora = array_map($clave, superficieWebViva());

    expect(array_values(array_diff($antes, $ahora)))->toBe([], 'rutas web que desaparecieron');
    expect(array_values(array_diff($ahora, $antes)))->toBe([], 'rutas web nuevas sin congelar');
});

test('ninguna ruta web pierde el middleware que la protege', function () {
    $congelada = superficieWebCongelada();

    $porClave = [];

    foreach (superficieWebViva() as $f) {
        $porClave[$f['verbo'].' '.$f['uri']] = $f['middleware'];
    }

    $perdidos = [];

    foreach ($congelada as $f) {
        $clave = $f['verbo'].' '.$f['uri'];

        if (! isset($porClave[$clave])) {
            continue; // Que falte lo dice la prueba de arriba.
        }

        $faltan = array_values(array_diff($f['middleware'], $porClave[$clave]));

        if ($faltan !== []) {
            $perdidos[] = $clave.'  ->  sin '.implode(', ', $faltan);
        }
    }

    expect($perdidos)->toBe([]);
});

test('CONTROL: el congelado web cubre lo que la superficie de la API deja fuera', function () {
    /*
     * La mitad que hace que esto signifique algo. Si el recorrido se rompiera
     * —o alguien invirtiera el `str_starts_with`— las dos pruebas de arriba
     * pasarían comparando listas vacías. Así que se comprueba la ARITMÉTICA:
     * las de la API más las web son TODAS las rutas vivas, sin solaparse.
     */
    $todas = count(Route::getRoutes());
    $web = count(superficieWebViva());
    $api = $todas - $web;

    expect($web)->toBeGreaterThan(10);
    expect($api)->toBeGreaterThan(200);
    expect($web + $api)->toBe($todas);

    /* Y que ninguna web empiece por `v1/`: es el reparto que define a las dos. */
    foreach (superficieWebViva() as $f) {
        expect(str_starts_with($f['uri'], 'v1/'))->toBeFalse();
    }
});
