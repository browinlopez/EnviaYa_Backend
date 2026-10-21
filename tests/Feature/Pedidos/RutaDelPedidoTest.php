<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/**
 * LA RUTA SE LE PIDE A GOOGLE UNA VEZ, NO UNA POR PEDIDO.
 *
 * Cada llamada a Directions se factura. El trazo de una tienda a una casa es
 * la misma calle hoy y el mes que viene, así que se guarda: los dos extremos
 * son fijos —el origen es el negocio y el destino una dirección guardada—, lo
 * que convierte esta caché en una caché por tienda y dirección.
 *
 * Antes duraba media hora, y la misma tienda repartiendo dos veces en la
 * tarde al mismo edificio pagaba dos veces.
 */

beforeEach(function () {
    config(['services.google.maps_server_key' => 'clave-de-prueba']);
    Cache::flush();

    // La ruta es de quien esta mirando un pedido, no es publica: sin sesion
    // responde 401, que es justo lo que debe hacer.
    \App\Models\Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Sanctum::actingAs(\App\Models\User::factory()->create(['rol' => 1]));
});

/**
 * El doble de Google, respondiendo bien.
 *
 * No va en el `beforeEach`: los dobles de `Http` se acumulan y gana el PRIMERO
 * que coincide, asi que un exito declarado antes se comia el fallo que queria
 * probar el ultimo caso. Cada prueba declara el suyo.
 */
function fakeRutaOk(): void
{
    Http::fake([
        'maps.googleapis.com/*' => Http::response([
            'routes' => [['overview_polyline' => ['points' => 'abc_def']]],
        ]),
    ]);
}

function pedirRuta(string $origen, string $destino)
{
    return test()->getJson("/v1/rutas?origen={$origen}&destino={$destino}");
}

test('la primera vez se le pregunta a Google y se devuelve la polilinea', function () {
    fakeRutaOk();
    pedirRuta('10.9878,-74.7889', '10.9950,-74.7800')
        ->assertOk()
        ->assertJson(['polilinea' => 'abc_def']);

    Http::assertSentCount(1);
});

test('la misma tienda al mismo edificio no se vuelve a facturar', function () {
    fakeRutaOk();
    pedirRuta('10.9878,-74.7889', '10.9950,-74.7800')->assertOk();
    pedirRuta('10.9878,-74.7889', '10.9950,-74.7800')->assertOk();
    pedirRuta('10.9878,-74.7889', '10.9950,-74.7800')->assertOk();

    // Tres domiciliarios saliendo al mismo sitio: una sola llamada pagada.
    Http::assertSentCount(1);
});

test('los decimales de mas no crean una entrada nueva', function () {
    fakeRutaOk();
    /*
     * «10.9878» y «10.98780» son el mismo punto. Sin normalizar la clave,
     * cada cliente que mandara la coordenada con otro formato pagaba su
     * propia llamada.
     */
    pedirRuta('10.9878,-74.7889', '10.9950,-74.7800')->assertOk();
    pedirRuta('10.98780,-74.78890', '10.99500,-74.78000')->assertOk();

    Http::assertSentCount(1);
});

test('dos destinos distintos sí son dos rutas distintas', function () {
    fakeRutaOk();
    pedirRuta('10.9878,-74.7889', '10.9950,-74.7800')->assertOk();
    pedirRuta('10.9878,-74.7889', '10.9820,-74.7700')->assertOk();

    Http::assertSentCount(2);
});

test('sin clave configurada se responde 503 y no se llama a nadie', function () {
    config(['services.google.maps_server_key' => '']);

    pedirRuta('10.9878,-74.7889', '10.9950,-74.7800')->assertStatus(503);

    Http::assertNothingSent();
});

test('un fallo de Google no se queda guardado', function () {
    /*
     * Si se cacheara el fallo, media hora —ahora un mes— de rutas rotas por
     * un timeout. El `Cache::remember` no guarda cuando la función lanza.
     */
    Http::fake(['maps.googleapis.com/*' => Http::response('se cayo', 500)]);

    pedirRuta('10.9878,-74.7889', '10.9950,-74.7800');

    expect(Cache::has('ruta:v2:' . md5('10.98780,-74.78890|10.99500,-74.78000')))->toBeFalse();
});

test('las coordenadas mal formadas se rechazan antes de gastar una llamada', function () {
    test()->getJson('/v1/rutas?origen=por-ahi&destino=10.99,-74.78')
        ->assertStatus(422);

    Http::assertNothingSent();
});
