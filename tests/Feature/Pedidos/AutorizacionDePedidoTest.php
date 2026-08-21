<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Quién puede mover un pedido por PUT /v1/orders/update.
 *
 * Esa ruta solo exigía estar autenticado: validaba el formato y a partir de ahí
 * operaba sobre el pedido que le dijeran. Como `orderSales_id` es un entero
 * correlativo, cualquier sesión válida podía aceptar, despachar y dar por
 * entregado el pedido de otra persona probando números.
 *
 * Por esta misma ruta pasan las TRES transiciones de la operación, así que lo
 * que se fija acá es el camino principal, no un caso raro.
 */
function escenarioOperacion(int $estado = 1): array
{
    foreach ([1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario'] as $id => $nombre) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $nombre, 'guard_name' => 'web']);
    }

    $businessId = DB::table('business')->insertGetId([
        'name' => 'Tienda del Pedido', 'qualification' => 0, 'state' => 1,
    ]);

    // El dueño: user -> owner -> owner_busines -> business.
    $tendero = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId(['user_id' => $tendero->user_id, 'state' => 1]);
    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $businessId, 'state' => 1,
    ]);

    $domiUser = User::factory()->create(['rol' => 3]);
    $domiId = DB::table('domiciliary')->insertGetId([
        'user_id' => $domiUser->user_id, 'qualification' => 0,
        'available' => 1, 'state' => 1,
    ]);

    $comprador = User::factory()->create(['rol' => 1]);
    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $comprador->user_id, 'qualification' => 0,
        'belongs_to_complex' => 0, 'state' => 1,
    ]);

    // Un método que no sea efectivo: al entregar, el 1 registra el cobro y eso
    // se prueba aparte. Hay que crearlo, la tabla tiene clave foránea.
    $methodId = DB::table('payment_methods')->insertGetId(['name' => 'Tarjeta', 'state' => 1]);

    $orderId = DB::table('orderssales')->insertGetId([
        'buyer_id' => $buyerId, 'busines_id' => $businessId,
        'domiciliary_id' => $estado >= 3 ? $domiId : null,
        'subtotal' => 10000, 'domicilio' => 2000, 'total' => 12000,
        'sale_date' => now(), 'state' => $estado,
        'methods_id' => $methodId,
    ], 'orderSales_id');

    // El intruso: una cuenta válida cualquiera, como la de quien se acaba de
    // registrar. Es el atacante realista, no un hacker.
    $intruso = User::factory()->create(['rol' => 1]);

    return compact('tendero', 'domiUser', 'comprador', 'intruso', 'orderId', 'businessId');
}

function mover(int $orderId, int $estado, ?int $userId = null): array
{
    return array_filter([
        'order_id' => $orderId,
        'state'    => $estado,
        'user_id'  => $userId,
    ], fn ($v) => $v !== null);
}

/* ------------------------------ ACEPTAR ------------------------------- */

test('la tienda acepta su propio pedido', function () {
    $e = escenarioOperacion(1);
    Sanctum::actingAs($e['tendero']);

    test()->putJson('/v1/orders/update', mover($e['orderId'], 2))->assertOk();

    expect((int) DB::table('orderssales')->where('orderSales_id', $e['orderId'])->value('state'))
        ->toBe(2);
});

test('un desconocido no puede aceptar el pedido de una tienda', function () {
    $e = escenarioOperacion(1);
    Sanctum::actingAs($e['intruso']);

    test()->putJson('/v1/orders/update', mover($e['orderId'], 2))->assertStatus(403);

    expect((int) DB::table('orderssales')->where('orderSales_id', $e['orderId'])->value('state'))
        ->toBe(1);
});

test('ni siquiera el comprador dueño del pedido puede aceptarlo por la tienda', function () {
    // Aceptar significa "me comprometo a prepararlo": no es del cliente.
    $e = escenarioOperacion(1);
    Sanctum::actingAs($e['comprador']);

    test()->putJson('/v1/orders/update', mover($e['orderId'], 2))->assertStatus(403);
});

/* ----------------------------- DESPACHAR ------------------------------ */

test('el domiciliario puede tomar un pedido para sí mismo', function () {
    $e = escenarioOperacion(2);
    Sanctum::actingAs($e['domiUser']);

    test()->putJson('/v1/orders/update', mover($e['orderId'], 3, $e['domiUser']->user_id))
        ->assertOk();

    $o = DB::table('orderssales')->where('orderSales_id', $e['orderId'])->first();
    expect((int) $o->state)->toBe(3)
        // El plazo prometido se sella acá.
        ->and($o->promised_minutes)->not->toBeNull();
});

test('la tienda puede despachárselo a un domiciliario', function () {
    $e = escenarioOperacion(2);
    Sanctum::actingAs($e['tendero']);

    test()->putJson('/v1/orders/update', mover($e['orderId'], 3, $e['domiUser']->user_id))
        ->assertOk();
});

test('nadie puede cargarle un pedido a otro domiciliario', function () {
    // Sin esto, cualquiera podría llenarle el cupo de entregas simultáneas a
    // alguien y dejarlo sin poder aceptar trabajo.
    $e = escenarioOperacion(2);
    Sanctum::actingAs($e['intruso']);

    test()->putJson('/v1/orders/update', mover($e['orderId'], 3, $e['domiUser']->user_id))
        ->assertStatus(403);

    expect((int) DB::table('orderssales')->where('orderSales_id', $e['orderId'])->value('state'))
        ->toBe(2);
});

/* ------------------------------ ENTREGAR ------------------------------ */

test('el domiciliario asignado marca la entrega', function () {
    $e = escenarioOperacion(3);
    Sanctum::actingAs($e['domiUser']);

    test()->putJson('/v1/orders/update', mover($e['orderId'], 4))->assertOk();

    expect((int) DB::table('orderssales')->where('orderSales_id', $e['orderId'])->value('state'))
        ->toBe(4);
});

test('la tienda no puede dar por entregado lo que no llevó', function () {
    // Es la transición que mueve dinero: registra el cobro en efectivo y la
    // ganancia del domiciliario. Solo la declara quien lo lleva.
    $e = escenarioOperacion(3);
    Sanctum::actingAs($e['tendero']);

    test()->putJson('/v1/orders/update', mover($e['orderId'], 4))->assertStatus(403);

    expect((int) DB::table('orderssales')->where('orderSales_id', $e['orderId'])->value('state'))
        ->toBe(3);
});

test('otro domiciliario no puede cerrar una entrega ajena', function () {
    $e = escenarioOperacion(3);

    $otro = User::factory()->create(['rol' => 3]);
    DB::table('domiciliary')->insert([
        'user_id' => $otro->user_id, 'qualification' => 0, 'available' => 1, 'state' => 1,
    ]);
    Sanctum::actingAs($otro);

    test()->putJson('/v1/orders/update', mover($e['orderId'], 4))->assertStatus(403);
});
