<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Lo que ve un domiciliario en su app.
 *
 * EL FALLO QUE ESTO FIJA. Su pantalla sacaba la lista de
 * `POST /v1/orders/business`: se bajaba los pedidos ENTEROS de la tienda —con
 * nombre, teléfono y dirección de cada comprador— y filtraba en el teléfono
 * los que eran suyos. Cuando ese endpoint se cerró a quien no es dueño del
 * negocio, la app se quedó mostrando «0 de 3» y «no hay pedidos por tomar» a
 * alguien que llevaba cuatro entregas encima. Se comprobó en el emulador.
 *
 * Acá se fijan las tres cosas que tienen que valer a la vez: que vea los
 * suyos, que vea los que puede tomar, y que de esos últimos NO reciba los
 * datos de una persona con la que todavía no tiene nada que ver.
 */

function escenarioDomiciliario(): array
{
    foreach ([1 => 'comprador', 3 => 'domiciliario'] as $id => $nombre) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $nombre, 'guard_name' => 'web']);
    }
    DB::table('payment_methods')->insertOrIgnore([
        'methods_id' => 1, 'name' => 'Efectivo', 'state' => 1,
    ]);

    $repartidor = User::factory()->create(['rol' => 3]);
    $domiId = DB::table('domiciliary')->insertGetId([
        'user_id' => $repartidor->user_id, 'available' => 1,
        'qualification' => 0, 'state' => 1,
    ], 'domiciliary_id');

    $comprador = User::factory()->create(['rol' => 1, 'name' => 'Ana Comprado', 'phone' => '3001234567']);
    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $comprador->user_id, 'qualification' => 0,
        'belongs_to_complex' => 0, 'state' => 1,
    ]);

    $negocioId = DB::table('business')->insertGetId([
        'name' => 'Tienda de la esquina', 'qualification' => 0, 'state' => 1,
    ]);
    DB::table('business_domiciliary')->insert([
        'busines_id' => $negocioId, 'domiciliary_id' => $domiId, 'state' => 1,
    ]);

    $pedido = function (int $estado, ?int $domi) use ($buyerId, $negocioId) {
        return DB::table('orderssales')->insertGetId([
            'buyer_id' => $buyerId, 'busines_id' => $negocioId,
            'domiciliary_id' => $domi, 'methods_id' => 1,
            'subtotal' => 5000, 'domicilio' => 2000, 'total' => 7000,
            'domiciliary_fee' => 500, 'sale_date' => now(),
            'state' => $estado, 'payment_state' => 'pending_cash',
        ], 'orderSales_id');
    };

    return [
        'repartidor' => $repartidor,
        'mio'        => $pedido(3, $domiId),   // en camino, asignado a mí
        'porTomar'   => $pedido(2, null),      // listo y sin dueño
        'deOtro'     => $pedido(3, null),      // en camino sin asignar: ni mío ni tomable
    ];
}

function misPedidos(User $quien): \Illuminate\Testing\TestResponse
{
    Sanctum::actingAs($quien);

    return test()->getJson('/v1/domiciliaries/pedidos');
}

/* ---------------------------------------------------------------------- */

test('ve los pedidos que le asignaron', function () {
    $e = escenarioDomiciliario();

    $r = misPedidos($e['repartidor'])->assertOk();

    $ids = collect($r->json('orders'))->pluck('order_id');
    expect($ids)->toContain($e['mio']);
    expect($r->json('resumen.en_camino'))->toBe(1);
});

test('ve tambien los que puede tomar de su tienda', function () {
    $e = escenarioDomiciliario();

    $r = misPedidos($e['repartidor'])->assertOk();

    expect(collect($r->json('orders'))->pluck('order_id'))->toContain($e['porTomar']);
    expect($r->json('resumen.por_tomar'))->toBe(1);
});

test('de un pedido que todavia no es suyo no recibe los datos de la persona', function () {
    $e = escenarioDomiciliario();

    $r = misPedidos($e['repartidor'])->assertOk();
    $pedidos = collect($r->json('orders'))->keyBy('order_id');

    /*
     * ESTA es la razón de que exista el endpoint. Dar de comer a la pantalla
     * abriendo la lista de la tienda significaba entregarle el nombre, el
     * teléfono y la puerta de cada comprador del negocio, hubiera o no
     * recogido nunca uno de sus pedidos.
     */
    expect($pedidos[$e['porTomar']]['buyer'])->toBeNull();
    expect($pedidos[$e['porTomar']]['delivery_address'])->toBeNull();

    // El suyo sí: tiene que llegar a esa puerta.
    expect($pedidos[$e['mio']]['buyer'])->not->toBeNull();
});

test('no ve un pedido en camino que no es suyo', function () {
    $e = escenarioDomiciliario();

    $r = misPedidos($e['repartidor'])->assertOk();

    expect(collect($r->json('orders'))->pluck('order_id'))
        ->not->toContain($e['deOtro']);
});

test('el ambito sale de la sesion, no de un parametro', function () {
    $e = escenarioDomiciliario();

    // Otro domiciliario, sin tiendas ni pedidos.
    $ajeno = User::factory()->create(['rol' => 3]);
    DB::table('domiciliary')->insert([
        'user_id' => $ajeno->user_id, 'available' => 1,
        'qualification' => 0, 'state' => 1,
    ]);

    $r = misPedidos($ajeno)->assertOk();

    // No hay número que mandar para ver los del otro: la ruta no recibe nada.
    expect($r->json('orders'))->toBe([]);
});

test('una cuenta que no es domiciliario no entra', function () {
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    $comprador = User::factory()->create(['rol' => 1]);

    misPedidos($comprador)->assertForbidden();
});
