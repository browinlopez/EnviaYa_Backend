<?php

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * QUIÉN PUEDE PREGUNTAR POR DÓNDE VA UN DOMICILIARIO.
 *
 * El endpoint solo validaba que el `domiciliary_id` existiera. Los
 * identificadores son correlativos, así que cualquier cuenta con sesión podía
 * pedir la última posición de cualquier domiciliario de la plataforma probando
 * números: por dónde anda, a qué hora, desde dónde salió.
 *
 * Pasan tres: el propio domiciliario, el negocio al que reparte, y el
 * comprador mientras tenga un pedido vivo con él.
 */

/** Un domiciliario de un negocio, con una posición guardada. */
function flotaConPosicion(): array
{
    foreach ([1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario', 4 => 'admin'] as $id => $nombre) {
        \App\Models\Rol::firstOrCreate(['rol_id' => $id], ['name' => $nombre, 'guard_name' => 'web']);
    }

    $domiUser = \App\Models\User::factory()->create(['rol' => 3]);
    $tendero  = \App\Models\User::factory()->create(['rol' => 2]);
    $ajeno    = \App\Models\User::factory()->create(['rol' => 1]);
    $cliente  = \App\Models\User::factory()->create(['rol' => 1]);

    $negocio = DB::table('business')->insertGetId([
        'name' => 'Tienda de la flota', 'qualification' => 0, 'state' => 1,
    ]);

    $owner = DB::table('owner')->insertGetId(['user_id' => $tendero->user_id, 'state' => 1]);
    DB::table('owner_busines')->insert(['owner_id' => $owner, 'busines_id' => $negocio]);

    $domiciliario = DB::table('domiciliary')->insertGetId([
        'user_id' => $domiUser->user_id, 'qualification' => 0, 'state' => 1,
    ]);
    DB::table('business_domiciliary')->insert([
        'busines_id' => $negocio, 'domiciliary_id' => $domiciliario,
    ]);

    // El medio de pago tiene clave foranea desde el pedido.
    DB::table('payment_methods')->insertOrIgnore([
        'methods_id' => 1, 'name' => 'Efectivo', 'state' => 1,
    ]);

    $buyer = DB::table('buyer')->insertGetId([
        'user_id' => $cliente->user_id, 'qualification' => 0,
        'belongs_to_complex' => 0, 'state' => 1,
    ]);

    $pedido = DB::table('orderssales')->insertGetId([
        'buyer_id' => $buyer, 'busines_id' => $negocio, 'domiciliary_id' => $domiciliario,
        'methods_id' => 1, 'total' => 10000, 'subtotal' => 8000, 'domicilio' => 2000,
        'state' => 3, 'sale_date' => now(),
    ]);

    DB::table('order_geolocation')->insert([
        'domiciliary_id' => $domiciliario, 'orderSales_id' => $pedido,
        'latitude' => 10.9878, 'longitude' => -74.7889, 'state' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return compact('domiUser', 'tendero', 'ajeno', 'cliente', 'domiciliario', 'pedido');
}

function pedirPosicion(int $domiciliario)
{
    return test()->getJson("/v1/orders/geolocation/latest?domiciliary_id={$domiciliario}");
}

test('el propio domiciliario puede ver su posicion', function () {
    $f = flotaConPosicion();
    Sanctum::actingAs($f['domiUser']);

    $r = pedirPosicion($f['domiciliario'])->assertOk();

    expect((float) $r->json('latitude'))->toBe(10.9878);
});

test('el tendero ve por donde va su flota', function () {
    $f = flotaConPosicion();
    Sanctum::actingAs($f['tendero']);

    pedirPosicion($f['domiciliario'])->assertOk();
});

test('el comprador con un pedido vivo tambien', function () {
    $f = flotaConPosicion();
    Sanctum::actingAs($f['cliente']);

    pedirPosicion($f['domiciliario'])->assertOk();
});

test('un desconocido recibe 403 y no una respuesta vacia', function () {
    /*
     * Responder «no hay posición» a quien no debería estar preguntando le
     * confirma que el número existe. El 403 no.
     */
    $f = flotaConPosicion();
    Sanctum::actingAs($f['ajeno']);

    pedirPosicion($f['domiciliario'])->assertStatus(403);
});

test('al entregar, el comprador deja de poder mirar donde anda', function () {
    $f = flotaConPosicion();
    DB::table('orderssales')->where('orderSales_id', $f['pedido'])->update(['state' => 4]);

    Sanctum::actingAs($f['cliente']);

    pedirPosicion($f['domiciliario'])->assertStatus(403);
});

test('sin sesion no se responde nada', function () {
    $f = flotaConPosicion();

    pedirPosicion($f['domiciliario'])->assertStatus(401);
});
