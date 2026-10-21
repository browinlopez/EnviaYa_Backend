<?php

use App\Events\DomiciliaryLocationUpdated;
use App\Models\Order\OrderGeolocation;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * LA TORRE DE CONTROL DE LA FLOTA
 *
 * Lo que el tendero se pregunta mirando la calle: quién está repartiendo, qué
 * lleva encima cada uno y cuánta plata carga.
 *
 * La prueba que de verdad importa acá es la de la EDAD de la posición. El
 * teléfono solo reporta mientras el domiciliario tiene abierta la pantalla de
 * seguimiento, así que un pin quieto no significa una moto quieta: si la torre
 * pintara coordenadas sin decir de cuándo son, el tendero llamaría a regañar a
 * alguien que va manejando.
 */

/** Un pedido ya despachado, encima del domiciliario. */
function despachado(array $e, int $haceMinutos = 25): void
{
    DB::table('orderssales')->where('orderSales_id', $e['orderId'])->update([
        'state'          => 3,
        'domiciliary_id' => $e['domiId'],
        'dispatched_at'  => now()->subMinutes($haceMinutos),
    ]);
}

/* ---------------------------------------------------------------------- */

test('la torre dice quién reparte, qué lleva y cuánta plata carga', function () {
    $e = escenarioDeTope(200000, metodo: 1, total: 60000);
    despachado($e);

    Sanctum::actingAs($e['tendero']);

    $r = $this->getJson('/v1/negocio/torre')->assertSuccessful();

    expect($r->json('flota'))->toHaveCount(1);

    $uno = $r->json('flota.0');

    expect($uno['domiciliary_id'])->toBe($e['domiId'])
        ->and($uno['a_bordo'])->toBe(1)
        ->and($uno['pedidos'][0]['orderSales_id'])->toBe($e['orderId'])
        ->and($uno['pedidos'][0]['en_efectivo'])->toBeTrue()
        ->and($uno['pedidos'][0]['total'])->toEqual(60000)
        // Lleva veinticinco minutos con él: es la cifra que hace saltar al
        // tendero, y por eso se manda calculada.
        ->and($uno['pedidos'][0]['minutos'])->toBe(25)
        ->and($uno['desde'])->not->toBeNull()
        ->and($uno['tope_efectivo'])->toEqual(200000);
});

test('la posición viaja SIEMPRE con su edad al lado', function () {
    $e = escenarioDeTope(null);
    despachado($e);

    OrderGeolocation::create([
        'domiciliary_id' => $e['domiId'],
        'orderSales_id'  => $e['orderId'],
        'latitude'       => 10.9878,
        'longitude'      => -74.7889,
    ]);

    DB::table('order_geolocation')->update(['created_at' => now()->subMinutes(14)]);

    Sanctum::actingAs($e['tendero']);

    $posicion = $this->getJson('/v1/negocio/torre')->json('flota.0.posicion');

    expect($posicion['latitude'])->toBe(10.9878)
        ->and($posicion['hace_minutos'])->toBe(14)
        ->and($posicion['at'])->not->toBeNull();
});

test('una posición de ayer no se enseña: ya no dice dónde está nadie', function () {
    $e = escenarioDeTope(null);
    despachado($e);

    OrderGeolocation::create([
        'domiciliary_id' => $e['domiId'],
        'orderSales_id'  => $e['orderId'],
        'latitude'       => 10.9878,
        'longitude'      => -74.7889,
    ]);

    DB::table('order_geolocation')->update(['created_at' => now()->subHours(13)]);

    Sanctum::actingAs($e['tendero']);

    expect($this->getJson('/v1/negocio/torre')->json('flota.0.posicion'))->toBeNull();
});

test('de dos posiciones, se enseña la última', function () {
    $e = escenarioDeTope(null);
    despachado($e);

    foreach ([[10.0, -74.0, 40], [11.0, -75.0, 3]] as [$lat, $lon, $hace]) {
        $geo = OrderGeolocation::create([
            'domiciliary_id' => $e['domiId'],
            'orderSales_id'  => $e['orderId'],
            'latitude'       => $lat,
            'longitude'      => $lon,
        ]);

        DB::table('order_geolocation')
            ->where('geolocation_id', $geo->geolocation_id)
            ->update(['created_at' => now()->subMinutes($hace)]);
    }

    Sanctum::actingAs($e['tendero']);

    expect($this->getJson('/v1/negocio/torre')->json('flota.0.posicion.hace_minutos'))->toBe(3);
});

test('lo que lleva de OTRA tienda no es asunto de este tendero', function () {
    $e = escenarioDeTope(null);
    despachado($e);

    // El mismo domiciliario reparte para la tienda de enfrente.
    $tipo  = DB::table('category_business')->insertGetId(['name' => 'Otra'], 'id');
    $otra  = DB::table('business')->insertGetId([
        'name' => 'La de Enfrente', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    $buyerId = DB::table('orderssales')->where('orderSales_id', $e['orderId'])->value('buyer_id');

    DB::table('orderssales')->insert([
        'buyer_id' => $buyerId, 'busines_id' => $otra, 'methods_id' => 1,
        'subtotal' => 8000, 'domicilio' => 2000, 'total' => 10000,
        'domiciliary_fee' => 500, 'sale_date' => now(),
        'state' => 3, 'domiciliary_id' => $e['domiId'], 'dispatched_at' => now(),
        'payment_state' => 'pending_cash',
    ]);

    Sanctum::actingAs($e['tendero']);

    expect($this->getJson('/v1/negocio/torre')->json('flota.0.a_bordo'))->toBe(1);
});

test('un tendero no ve la flota de otra tienda', function () {
    $e = escenarioDeTope(null);
    despachado($e);

    // Otro tendero, con su propia tienda y sin domiciliarios.
    $ajeno = tenderoConCatalogo();
    Sanctum::actingAs($ajeno['user']);

    expect($this->getJson('/v1/negocio/torre')->json('flota'))->toBe([]);
});

test('la posición sale por el canal de la torre y NO por el de la tienda', function () {
    /*
     * `business.{id}` lo escuchan también los domiciliarios de la tienda —es
     * como se enteran de que entró trabajo—, así que meter ahí las coordenadas
     * le daría a cada uno la posición en vivo de sus compañeros.
     */
    $e = escenarioDeTope(null);
    despachado($e);

    $geo = OrderGeolocation::create([
        'domiciliary_id' => $e['domiId'],
        'orderSales_id'  => $e['orderId'],
        'latitude'       => 10.9878,
        'longitude'      => -74.7889,
    ]);

    $canales = collect((new DomiciliaryLocationUpdated($geo))->broadcastOn())
        ->map(fn ($c) => $c->name)
        ->all();

    expect($canales)->toContain('private-order.' . $e['orderId'])
        ->and($canales)->toContain('private-negocio.' . $e['businessId'] . '.torre')
        ->and($canales)->not->toContain('private-business.' . $e['businessId']);
});

test('el aviso de posición dice de qué domiciliario es', function () {
    // La torre pinta una moto por domiciliario y no por pedido: sin esto no
    // sabría cuál de los pines mover.
    $e = escenarioDeTope(null);
    despachado($e);

    $geo = OrderGeolocation::create([
        'domiciliary_id' => $e['domiId'],
        'orderSales_id'  => $e['orderId'],
        'latitude'       => 10.9878,
        'longitude'      => -74.7889,
    ]);

    expect((new DomiciliaryLocationUpdated($geo))->broadcastWith()['domiciliary_id'])
        ->toBe($e['domiId']);
});

test('la torre NO se trae doce horas de posiciones para quedarse con una', function () {
    /*
     * ESTA ES LA PRUEBA DE QUE LA TORRE AGUANTA EL TURNO COMPLETO.
     *
     * Con el reporte en segundo plano el teléfono manda una posición cada
     * veinte segundos mientras la moto rueda: una hora de reparto son ciento
     * ochenta filas de un solo domiciliario, y la torre se refresca sola cada
     * quince segundos. Traerse la ventana entera para leer la última fila es
     * lo que convierte una pantalla en una tormenta contra la base.
     *
     * Se comprueba por la consulta y no por el resultado a propósito: el
     * resultado sale bien de las dos maneras, y lo que se rompe al crecer es
     * justamente lo que no se ve en la respuesta.
     */
    $e = escenarioDeTope(null);
    despachado($e);

    $filas = [];

    for ($i = 0; $i < 180; $i++) {
        $filas[] = [
            'domiciliary_id' => $e['domiId'],
            'orderSales_id'  => $e['orderId'],
            'latitude'       => 10.98 + $i / 100000,
            'longitude'      => -74.78,
            'created_at'     => now()->subSeconds(20 * $i),
            'updated_at'     => now(),
        ];
    }

    DB::table('order_geolocation')->insert($filas);

    Sanctum::actingAs($e['tendero']);

    DB::enableQueryLog();
    $r = $this->getJson('/v1/negocio/torre')->assertSuccessful();

    $consultas = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn ($q) => str_contains($q, 'order_geolocation'));

    DB::disableQueryLog();

    expect($consultas)->not->toBeEmpty();

    // Cada una pide UNA fila. Ninguna se trae el montón.
    $consultas->each(fn ($q) => expect($q)->toContain('limit 1'));

    // Y la que sale es la de ahora mismo, no una de las 179 anteriores.
    expect($r->json('flota.0.posicion.hace_minutos'))->toBe(0);
});

test('la última posición de una moto se encuentra por índice y no recorriendo', function () {
    /*
     * Sin el par `(domiciliary_id, created_at)`, la base sabe encontrar las
     * filas de esa moto pero tiene que ordenarlas TODAS para saber cuál es la
     * última: con el índice, la última es el final de su tramo y la encuentra
     * sin ordenar nada. Es lo que hace que la consulta de arriba siga
     * costando lo mismo con la tabla en millones de filas.
     */
    $porMotoYFecha = collect(\Illuminate\Support\Facades\Schema::getIndexes('order_geolocation'))
        ->contains(fn ($i) => array_map('strval', $i['columns']) === ['domiciliary_id', 'created_at']);

    expect($porMotoYFecha)->toBeTrue();
});
