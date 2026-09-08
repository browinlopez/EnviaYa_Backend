<?php

use App\Services\Ajustes;
use App\Services\TarifaPorDistancia;

/**
 * El domicilio cuesta según lo lejos que esté la puerta.
 *
 * Antes era una sola cifra para todo el mundo: lo mismo cruzar la calle que
 * atravesar el barrio. Quien hacía el viaje largo cobraba igual que quien
 * bajaba a la esquina.
 *
 * La escala pactada, sobre una tarifa base de $2.000:
 *
 *     0 – 1,5 km  ->  $2.000
 *     1,5 – 2,5   ->  $3.000
 *     2,5 – 3,5   ->  $4.000   … y así
 */

function tarifas(): TarifaPorDistancia
{
    return app(TarifaPorDistancia::class);
}

test('dentro del radio base se cobra la tarifa base', function () {
    expect(tarifas()->paraDistancia(0.4))->toBe(2000.0);
    expect(tarifas()->paraDistancia(1.5))->toBe(2000.0);
});

test('pasado el radio base sube un escalon entero', function () {
    /*
     * A 1,51 km ya se salió del radio, así que paga el escalón completo. Se
     * redondea hacia ARRIBA a propósito: con `round`, el tramo entre 1,5 y 2
     * se cobraría como si no se hubiera salido.
     */
    expect(tarifas()->paraDistancia(1.51))->toBe(3000.0);
    expect(tarifas()->paraDistancia(2.5))->toBe(3000.0);
});

test('cada kilometro siguiente suma otro escalon', function () {
    expect(tarifas()->paraDistancia(2.6))->toBe(4000.0);
    expect(tarifas()->paraDistancia(3.5))->toBe(4000.0);
    expect(tarifas()->paraDistancia(5.0))->toBe(6000.0);
});

test('sin coordenadas se cobra la base, no un recargo inventado', function () {
    /*
     * Los negocios y las direcciones cargados antes de que esto existiera no
     * tienen latitud. Cobrarles el escalón máximo por un dato que le falta al
     * sistema sería cobrar de más por un error propio.
     */
    expect(tarifas()->paraDistancia(null))->toBe(2000.0);
    expect(tarifas()->kilometros(null, null, 10.9, -74.8))->toBeNull();
});

test('la escala se mueve desde el panel, sin tocar codigo', function () {
    Ajustes::guardar([
        'operacion.radio_base_km' => 2.0,
        'operacion.paso_precio'   => 500,
    ], null);

    // Ahora el radio base llega a 2 km y cada escalón cuesta la mitad.
    expect(tarifas()->paraDistancia(1.9))->toBe(2000.0);
    expect(tarifas()->paraDistancia(2.1))->toBe(2500.0);
});

test('mide la distancia real entre dos puntos', function () {
    // Dos puntos de Barranquilla separados por algo más de dos kilómetros.
    $km = tarifas()->kilometros(10.9878, -74.7889, 10.9962, -74.8070);

    expect($km)->toBeGreaterThan(2.0);
    expect($km)->toBeLessThan(2.4);
});

test('el radio maximo dice hasta donde reparte una tienda', function () {
    Ajustes::guardar(['operacion.radio_maximo_km' => 6.0], null);

    expect(tarifas()->reparteHasta(5.9))->toBeTrue();
    expect(tarifas()->reparteHasta(6.1))->toBeFalse();

    // En cero no hay límite, y sin distancia no se bloquea a nadie.
    Ajustes::guardar(['operacion.radio_maximo_km' => 0], null);
    expect(tarifas()->reparteHasta(80.0))->toBeTrue();
    expect(tarifas()->reparteHasta(null))->toBeTrue();
});
