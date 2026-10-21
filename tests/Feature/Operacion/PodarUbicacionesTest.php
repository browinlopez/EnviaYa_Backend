<?php

use Illuminate\Support\Facades\DB;

/**
 * LAS UBICACIONES SE BORRAN A LOS NOVENTA DIAS.
 *
 * Esto no es higiene de base de datos: el acuerdo de vinculacion que firma el
 * domiciliario DECLARA ese plazo. Un plazo escrito en un contrato y una tabla
 * que crece para siempre es la clase de desajuste que convierte un documento
 * de respaldo en una prueba en contra.
 */

/*
 * Un domiciliario y un pedido de verdad: `order_geolocation` tiene claves
 * foraneas a los dos, asi que no se pueden inventar los numeros.
 */
beforeEach(function () {
    $this->escenario = escenarioDeTope(null);
});

/** Una posicion con la fecha que se quiera. */
function posicionDeHace(int $dias): int
{
    $e = test()->escenario;

    $id = DB::table('order_geolocation')->insertGetId([
        'domiciliary_id' => $e['domiId'],
        'orderSales_id'  => $e['orderId'],
        'latitude'       => 10.9878,
        'longitude'      => -74.7889,
    ], 'geolocation_id');

    DB::table('order_geolocation')->where('geolocation_id', $id)
        ->update(['created_at' => now()->subDays($dias)]);

    return $id;
}

test('borra lo anterior al plazo y conserva lo de dentro', function () {
    $vieja  = posicionDeHace(91);
    $reciente = posicionDeHace(89);

    $this->artisan('ubicaciones:podar')->assertSuccessful();

    expect(DB::table('order_geolocation')->where('geolocation_id', $vieja)->exists())->toBeFalse()
        ->and(DB::table('order_geolocation')->where('geolocation_id', $reciente)->exists())->toBeTrue();
});

test('deja constancia de que corrio, aunque no hubiera nada que borrar', function () {
    /*
     * Si el contenedor del programador no estuviera levantado, nadie se
     * enteraria: la tabla crece en silencio y el contrato sigue prometiendo un
     * borrado que no ocurre. Con la marca, comprobarlo es mirar una fila.
     */
    $this->artisan('ubicaciones:podar')->assertSuccessful();

    expect(
        DB::table('platform_settings')->where('key', 'operacion.ultima_poda_ubicaciones')->value('value'),
    )->not->toBeNull();
});

test('en seco no borra ni deja constancia', function () {
    // Una prueba a mano no puede dejar dicho que el borrado se cumplio ese dia.
    $vieja = posicionDeHace(100);

    $this->artisan('ubicaciones:podar --seco')->assertSuccessful();

    expect(DB::table('order_geolocation')->where('geolocation_id', $vieja)->exists())->toBeTrue()
        ->and(DB::table('platform_settings')->where('key', 'operacion.ultima_poda_ubicaciones')->exists())
        ->toBeFalse();
});

test('lo que la poda borra está indexado por fecha', function () {
    /*
     * La poda pregunta `WHERE created_at < corte` todas las noches. Sin índice
     * por fecha eso recorre la tabla COMPLETA para encontrar lo que hay que
     * borrar, y la tabla es justamente lo que está creciendo: el comando que
     * existe para que no se desborde sería lo más caro de la madrugada.
     */
    $porFecha = collect(\Illuminate\Support\Facades\Schema::getIndexes('order_geolocation'))
        ->contains(fn ($i) => (string) ($i['columns'][0] ?? '') === 'created_at');

    expect($porFecha)->toBeTrue();
});
