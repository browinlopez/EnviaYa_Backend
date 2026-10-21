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

/* ====================== LA RUTA GUARDADA EN EL PEDIDO ====================
   Con la caché, la primera entrega a un edificio nuevo paga una llamada y las
   siguientes no. Guardándola en el pedido se llega al suelo: una llamada por
   pedido y ninguna más, ni aunque se borre la caché, ni aunque lo abran el
   comprador, el domiciliario, el panel y la torre de control.
   ====================================================================== */

/** Un pedido despachado, con tienda y dirección situadas en el mapa. */
function pedidoConCoordenadas(): array
{
    $e = escenarioDeTope(null);

    DB::table('business')->where('busines_id', $e['businessId'])->update([
        'latitude' => 10.9878, 'longitude' => -74.7889,
    ]);

    $comprador = DB::table('buyer')
        ->where('buyer_id', DB::table('orderssales')->where('orderSales_id', $e['orderId'])->value('buyer_id'))
        ->value('user_id');

    $addressId = DB::table('user_address')->insertGetId([
        'user_id' => $comprador, 'address' => 'Apto 302', 'street' => 'Cra 43 #76-120',
        'latitude' => 10.9950, 'longitude' => -74.7800, 'state' => 1,
    ], 'address_id');

    DB::table('orderssales')->where('orderSales_id', $e['orderId'])->update([
        'state' => 3, 'domiciliary_id' => $e['domiId'], 'address_id' => $addressId,
    ]);

    return $e;
}

test('con el número del pedido se traza una vez y se guarda', function () {
    $e = pedidoConCoordenadas();
    fakeRutaOk();

    Sanctum::actingAs($e['tendero']);

    test()->getJson("/v1/rutas?order_id={$e['orderId']}")
        ->assertOk()
        ->assertJson(['polilinea' => 'abc_def']);

    $guardado = DB::table('orderssales')->where('orderSales_id', $e['orderId'])->first();

    expect($guardado->route_polyline)->toBe('abc_def')
        ->and($guardado->route_traced_at)->not->toBeNull();

    Http::assertSentCount(1);
});

test('con la ruta ya guardada no se toca ni la caché ni Google', function () {
    // Es el suelo: el comprador, el domiciliario, el panel y la torre pueden
    // dibujar la misma línea sin que nadie vuelva a pagar.
    $e = pedidoConCoordenadas();
    fakeRutaOk();

    DB::table('orderssales')->where('orderSales_id', $e['orderId'])->update([
        'route_polyline' => 'ya_trazada', 'route_traced_at' => now(),
    ]);

    Cache::flush();
    Sanctum::actingAs($e['tendero']);

    test()->getJson("/v1/rutas?order_id={$e['orderId']}")
        ->assertOk()
        ->assertJson(['polilinea' => 'ya_trazada']);

    Http::assertNothingSent();
});

test('un fallo de Google NO deja el pedido marcado como ya trazado', function () {
    /*
     * Si se marcara el intento fallido, el pedido se quedaría sin ruta para
     * siempre el día que la clave de Google quede puesta.
     */
    $e = pedidoConCoordenadas();

    Http::fake(['maps.googleapis.com/*' => Http::response([], 500)]);
    Sanctum::actingAs($e['tendero']);

    test()->getJson("/v1/rutas?order_id={$e['orderId']}")->assertStatus(503);

    $guardado = DB::table('orderssales')->where('orderSales_id', $e['orderId'])->first();

    expect($guardado->route_polyline)->toBeNull()
        ->and($guardado->route_traced_at)->toBeNull();
});

test('la ruta de un pedido ajeno no se sirve', function () {
    /*
     * La ruta dice de qué tienda a qué casa va un pedido, y los números son
     * correlativos: sin esto se iría sacando la dirección aproximada de cada
     * entrega de la plataforma.
     */
    $e = pedidoConCoordenadas();
    fakeRutaOk();

    $curioso = \App\Models\User::factory()->create(['rol' => 1]);
    Sanctum::actingAs($curioso);

    test()->getJson("/v1/rutas?order_id={$e['orderId']}")->assertStatus(404);

    Http::assertNothingSent();
});

test('un pedido sin coordenadas no revienta: se responde sin trazado', function () {
    $e = escenarioDeTope(null);
    fakeRutaOk();

    Sanctum::actingAs($e['tendero']);

    test()->getJson("/v1/rutas?order_id={$e['orderId']}")->assertStatus(503);

    Http::assertNothingSent();
});

test('la app publicada sigue pidiendo por origen y destino', function () {
    // No se puede cambiar a voluntad lo que ya está en los teléfonos.
    fakeRutaOk();

    pedirRuta('10.9878,-74.7889', '10.9950,-74.7800')->assertOk();
});
