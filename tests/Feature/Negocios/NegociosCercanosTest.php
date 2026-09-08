<?php

use App\Models\Rol;
use App\Models\User;
use App\Services\Ajustes;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\DB;

/**
 * Las tiendas, ordenadas por lo cerca que estén.
 *
 * Lo que se fija acá, además del orden:
 *
 *   · Que cada negocio diga a cuánto está y cuánto costaría traer de ahí,
 *     para que el precio del domicilio no sea una sorpresa en el carrito.
 *   · Que NO se esconda ninguno. Quien vive donde todavía no hay cobertura
 *     tiene que poder ver qué tiendas existen y dónde están. Filtrarlas deja
 *     una pantalla vacía sin explicación, que es la peor forma de decir «no
 *     llegamos a tu zona».
 */

function negocioUbicadoEn(string $nombre, ?float $lat, ?float $lon): int
{
    return DB::table('business')->insertGetId([
        'name' => $nombre,
        'latitude' => $lat,
        'longitude' => $lon,
        'qualification' => 0,
        'state' => 1,
    ], 'busines_id');
}

/** Un punto de Barranquilla desde donde se pregunta. */
const AQUI = ['lat' => 10.9894, 'lng' => -74.8434];

beforeEach(function () {
    Ajustes::guardar(['operacion.radio_maximo_km' => 6.0], null);

    // El catálogo pide sesión: sin ella responde 401 y no se prueba nada.
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));
});

/* ---------------------------------------------------------------------- */

test('cada negocio dice a cuanto esta y cuanto costaria el domicilio', function () {
    negocioUbicadoEn('La de al lado', 10.9900, -74.8440);   // a unos cientos de metros

    $r = $this->getJson('/v1/businesses/index?' . http_build_query(AQUI))->assertOk();
    $n = collect($r->json('businesses'))->firstWhere('name', 'La de al lado');

    expect($n['distance_km'])->toBeLessThan(1.5);
    // Dentro del radio base: la tarifa base y nada más.
    expect((float) $n['delivery_fee'])->toBe(2000.0);
    expect($n['in_range'])->toBeTrue();
});

test('la de mas lejos cuesta mas', function () {
    negocioUbicadoEn('Cerca', 10.9900, -74.8440);
    negocioUbicadoEn('Lejos', 11.0168, -74.8029);           // a unos 5 km

    $r = $this->getJson('/v1/businesses/index?' . http_build_query(AQUI))->assertOk();
    $n = collect($r->json('businesses'))->keyBy('name');

    expect($n['Lejos']['distance_km'])->toBeGreaterThan($n['Cerca']['distance_km']);
    expect($n['Lejos']['delivery_fee'])->toBeGreaterThan($n['Cerca']['delivery_fee']);
});

test('vienen ordenados de la mas cercana en adelante', function () {
    negocioUbicadoEn('Lejos', 11.0168, -74.8029);
    negocioUbicadoEn('Cerca', 10.9900, -74.8440);

    $r = $this->getJson('/v1/businesses/index?' . http_build_query(AQUI))->assertOk();
    $nombres = collect($r->json('businesses'))->pluck('name');

    expect($nombres->first())->toBe('Cerca');
});

test('sin coordenadas la respuesta es la de siempre', function () {
    negocioUbicadoEn('Una tienda', 10.9900, -74.8440);

    $r = $this->getJson('/v1/businesses/index')->assertOk();

    // Ni distancias ni orden por cercanía: no hay desde dónde medir.
    expect($r->json('businesses.0.distance_km'))->toBeNull();
});

test('una tienda sin coordenadas no se convierte en una a ocho mil kilometros', function () {
    negocioUbicadoEn('Sin ubicar', null, null);

    $r = $this->getJson('/v1/businesses/index?' . http_build_query(AQUI))->assertOk();
    $n = collect($r->json('businesses'))->firstWhere('name', 'Sin ubicar');

    /*
     * `(float) null` es 0.0, y el punto (0, 0) cae en el golfo de Guinea. Sin
     * la guarda, esta tienda salía a 8.353 km con un domicilio de ocho
     * millones de pesos. Pasó de verdad al probarlo con datos reales.
     */
    expect($n['distance_km'])->toBeNull();
    expect((float) $n['delivery_fee'])->toBe(2000.0);
});

test('sin tiendas en cobertura se dice, y se enseñan igual', function () {
    // Todas lejísimos: ninguna reparte hasta acá.
    negocioUbicadoEn('Muy lejos', 11.2400, -74.2000);

    $r = $this->getJson('/v1/businesses/index?' . http_build_query(AQUI))->assertOk();

    expect($r->json('hay_cercanos'))->toBeFalse();

    /*
     * Y aun así aparece, con su ubicación. Es lo que permite decir «no hay
     * tiendas que repartan a tu zona» y enseñar dónde están las que hay, en
     * vez de una lista vacía.
     */
    $n = collect($r->json('businesses'))->firstWhere('name', 'Muy lejos');
    expect($n)->not->toBeNull();
    expect($n['in_range'])->toBeFalse();
    expect($n['latitude'])->not->toBeNull();
});
