<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * EL CONTRATO OpenAPI DESCRIBE LA API DE VERDAD — §34.
 *
 * `docs/laravel-12/openapi.json` lo genera `php scripts/openapi.php` desde el
 * router, y el CI comprueba con `--revisar` que el fichero del repositorio es
 * el que saldría hoy. Esta prueba es la otra mitad: que el generador no se
 * quede callado.
 *
 * LA DIFERENCIA ENTRE LAS DOS COSAS IMPORTA. `--revisar` falla cuando el
 * fichero y el router no coinciden; no dice nada si el generador deja de
 * extraer esquemas y produce 294 operaciones vacías. Eso saldría «al día» y
 * el contrato describiría la API como un listado de URLs sin contenido.
 *
 * Así que acá se fijan los mínimos que el generador tiene que seguir
 * sacando, y cada número está medido, no elegido:
 *
 *   292 operaciones   = las rutas de `/v1` que el router tiene hoy
 *   272 con Bearer    y 20 públicas, que es el mismo 20 que cuenta por su
 *                     cuenta `SoloParaCambiarPorSesion` en su docblock
 *
 * Eran 294, 273 y 21 hasta el 16 de noviembre de 2026, cuando se retiró
 * `POST /v1/email/resend-verification` —una segunda grafía de
 * `/v1/resend-verification-email`, al mismo método— y antes `GET /v1/users`.
 * La de las públicas puso esta prueba en rojo al retirarla, que es
 * exactamente para lo que está contada dos veces.
 *   95 con cuerpo     las que declaran un `FormRequest`
 *
 * Los mínimos van por debajo del número de hoy a propósito: una ruta nueva
 * sube el total y no tiene por qué poner roja esta prueba. Lo que no puede
 * pasar es que CAIGAN, porque eso significa que el generador dejó de ver algo.
 */
function contratoOpenApi(): array
{
    $ruta = base_path('docs/laravel-12/openapi.json');

    expect(is_file($ruta))->toBeTrue('No existe docs/laravel-12/openapi.json: php scripts/openapi.php');

    return (array) json_decode((string) file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);
}

/** @return list<array{string, string, array<string, mixed>}> */
function operacionesDelContrato(): array
{
    $fuera = [];

    foreach ((array) (contratoOpenApi()['paths'] ?? []) as $ruta => $verbos) {
        foreach ((array) $verbos as $verbo => $op) {
            $fuera[] = [(string) $ruta, strtoupper((string) $verbo), (array) $op];
        }
    }

    return $fuera;
}

it('el contrato tiene una operacion por cada ruta de v1, ni una menos', function () {
    /*
     * Se cuenta contra el ROUTER y no contra un número escrito: si mañana se
     * retiran rutas —como se retiraron 31 el 11 de noviembre— esta prueba
     * sigue valiendo sin tocarla.
     */
    $enElRouter = [];

    foreach (Route::getRoutes() as $r) {
        if (! str_starts_with($r->uri(), 'v1/')) {
            continue;
        }

        foreach (array_diff($r->methods(), ['HEAD']) as $verbo) {
            $enElRouter[] = $verbo.' /'.$r->uri();
        }
    }

    $enElContrato = array_map(
        fn (array $o) => $o[1].' '.$o[0],
        operacionesDelContrato(),
    );

    sort($enElRouter);
    sort($enElContrato);

    expect($enElContrato)->toBe($enElRouter);
});

it('toda operacion dice de que middleware depende, que aqui ES el permiso', function () {
    /*
     * La autorización de esta API va por middleware y no por Policy —decisión
     * documentada en EXCEPCIONES.md—, así que `x-middleware` no es un extra:
     * es la única parte del contrato que dice quién puede llamar qué. Una
     * operación sin esa clave describe una puerta sin decir si tiene cerradura.
     */
    $sinMiddleware = [];

    foreach (operacionesDelContrato() as [$ruta, $verbo, $op]) {
        if (! isset($op['x-middleware']) || ! is_array($op['x-middleware']) || $op['x-middleware'] === []) {
            $sinMiddleware[] = $verbo.' '.$ruta;
        }
    }

    expect($sinMiddleware)->toBe([]);
});

it('el generador sigue extrayendo esquemas, y no solo URLs', function () {
    $ops = operacionesDelContrato();

    $conBearer = 0;
    $publicas = 0;
    $conCuerpo = 0;
    $escrituras = 0;
    $cuerpoSinDocumentar = 0;

    foreach ($ops as [, $verbo, $op]) {
        ($op['security'] ?? []) !== [] ? $conBearer++ : $publicas++;

        if (in_array($verbo, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $escrituras++;

            if (isset($op['requestBody'])) {
                $conCuerpo++;
            } elseif (isset($op['x-cuerpo'])) {
                $cuerpoSinDocumentar++;
            }
        }
    }

    // Medidos el 11 de noviembre de 2026; los mínimos van por debajo para que
    // una ruta nueva no ponga esto rojo.
    expect($conBearer)->toBeGreaterThanOrEqual(250);
    expect($conCuerpo)->toBeGreaterThanOrEqual(90);
    expect($escrituras)->toBeGreaterThanOrEqual(150);

    /*
     * TODA ESCRITURA DICE ALGO DEL CUERPO: o su esquema, o que no está
     * documentado. Ninguna se queda muda, porque una escritura sin ninguna de
     * las dos claves IMPLICA que no acepta cuerpo, y eso es una afirmación
     * distinta de «no se sabe». Costó una vuelta: los DELETE se quedaban
     * fuera, y en esta API varios llevan cuerpo —`DELETE /v1/devices` manda
     * el token del dispositivo—.
     */
    expect($conCuerpo + $cuerpoSinDocumentar)->toBe($escrituras);

    /*
     * Las públicas: el mismo 20 que cuenta por su cuenta el docblock de
     * `SoloParaCambiarPorSesion`. Dos medidas independientes del mismo
     * hecho, y EXACTA a propósito mientras las otras tres son mínimos:
     * una ruta pública nueva es justo lo que hay que mirar dos veces.
     *
     * Era 21 hasta que el 16 de noviembre de 2026 se retiró
     * `POST /v1/email/resend-verification`, que no pide sesión. Esta
     * línea se puso roja sola y hubo que mover las dos cuentas.
     */
    expect($publicas)->toBe(20);
});

it('lo que el contrato promete de una ruta concreta es lo que el router tiene', function () {
    /*
     * Una comprobación de punta a punta, porque los conteos de arriba pasarían
     * con los datos mezclados entre operaciones.
     *
     * SE ELIGEN DOS RUTAS Y NO UNA, y eso lo decidió un fallo de esta misma
     * prueba. La primera versión pedía una sola ruta que tuviera las tres
     * cosas —sesión, `FormRequest` y respuesta congelada— y eligió crear un
     * pedido, que es la escritura más importante de la plataforma. Se puso
     * roja: `POST /v1/orders/orders` **no tiene `FormRequest`**, valida en
     * línea con su motivo escrito en EXCEPCIONES.md. O sea que la ruta con más
     * peso de la API es de las 183 cuyo cuerpo el contrato no describe, y eso
     * conviene saberlo en vez de taparlo cambiando de ejemplo sin decir nada.
     */

    // 1. Una con `FormRequest`: el cuerpo tiene que estar descrito.
    $conCuerpo = contratoOpenApi()['paths']['/v1/admin/areas']['post'] ?? null;

    expect($conCuerpo)->not->toBeNull();
    expect($conCuerpo['security'])->toBe([['bearerAuth' => []]]);
    expect($conCuerpo['x-middleware'])->toContain('auth:sanctum');

    $esquema = $conCuerpo['requestBody']['content']['application/json']['schema'];
    expect($esquema['properties'])->toHaveKey('name');
    expect($esquema['required'])->toContain('name');
    expect($conCuerpo['x-form-request'])->toContain('Request');

    // 2. Y una con respuesta congelada: la forma y EL ESTADO.
    $conRespuesta = contratoOpenApi()['paths']['/v1/orders/orders']['post'] ?? null;

    expect($conRespuesta)->not->toBeNull();

    /*
     * LOS CODIGOS SE COMPARAN COMO ENTEROS, y esto costo una vuelta: PHP
     * convierte las claves numericas de un array a `int`, asi que
     * `array_keys` de `{"201": ...}` devuelve `[201]` y no `['201']`. Un
     * `toContain('201')` sobre eso falla diciendo «no contiene '201'»
     * mientras el 201 esta ahi, que es el peor tipo de fallo: el mensaje
     * manda a buscar al sitio equivocado.
     */
    expect(array_keys($conRespuesta['responses']))->toContain(201);
    expect($conRespuesta['responses']['201']['content']['application/json']['schema']['properties'])
        ->toHaveKey('order');

    /*
     * Y la que destapó el otro fallo: una LECTURA que viaja como POST.
     * `POST /v1/orders/orders` crea y contesta 201; `POST /v1/orders/business`
     * solo lee —manda el filtro en el cuerpo— y contesta 200. El generador
     * deducía el código del verbo y le ponía 201 a las dos, así que el
     * contrato afirmaba un código que nadie había medido. Ahora el estado se
     * guarda junto a la forma congelada y se lee de ahí.
     */
    $lectura = contratoOpenApi()['paths']['/v1/orders/business']['post'] ?? null;

    expect($lectura)->not->toBeNull();
    expect(array_keys($lectura['responses']))->toContain(200);
    expect(array_keys($lectura['responses']))->not->toContain(201);
});
