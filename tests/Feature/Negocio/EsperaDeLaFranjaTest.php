<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * DESDE CUÁNDO ESPERA EL PEDIDO MÁS VIEJO SIN ACEPTAR.
 *
 * El panel enseña en TODAS sus pantallas una franja que dice cuántos pedidos
 * hay sin aceptar y cuánto le queda al más viejo antes de los treinta
 * minutos. La cuenta de pedidos ya se servía; la fecha no, y sin ella el
 * reloj de esa franja no se puede contar —solo se sabe cuántos hay, no desde
 * cuándo—, que era justo lo que la franja existe para decir.
 *
 * Lo que se prueba es que la fecha sea la del MÁS VIEJO y que solo mire los
 * que están sin aceptar: cualquiera de las dos cosas mal deja la franja
 * contando un reloj que no corresponde a ningún pedido.
 */
function tenderoConPedidos(array $pedidos): array
{
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $tendero = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId(
        ['user_id' => $tendero->user_id, 'state' => 1], 'owner_id',
    );
    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $businessId = DB::table('business')->insertGetId([
        'name' => 'Mercadito del Buho', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');
    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $businessId, 'state' => 1,
    ]);

    $comprador = User::factory()->create(['rol' => 1]);
    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1,
    ]);

    foreach ($pedidos as [$estado, $haceMinutos]) {
        DB::table('orderssales')->insert([
            'buyer_id' => $buyerId, 'busines_id' => $businessId,
            'subtotal' => 18000, 'domicilio' => 2000, 'total' => 20000,
            'sale_date' => now()->subMinutes($haceMinutos),
            'state' => $estado, 'payment_state' => 'paid',
        ]);
    }

    return compact('tendero', 'businessId');
}

test('la ficha dice desde cuándo espera el más viejo sin aceptar', function () {
    $t = tenderoConPedidos([[1, 40], [1, 12], [2, 90]]);

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson('/v1/negocio/me')->assertOk();

    expect($r->json('stats.nuevos'))->toBe(2);

    // El de hace 40 minutos, no el de hace 12: la franja avisa del que lleva
    // más tiempo esperando, que es el que se va a poner rojo primero.
    expect(abs(now()->diffInMinutes($r->json('stats.espera_desde'))))->toBeGreaterThanOrEqual(39);
});

test('el pedido ya aceptado no cuenta para la espera', function () {
    // Sólo hay uno sin aceptar, y es el más NUEVO. Si la consulta mirara
    // todos los estados devolvería el de hace 90 minutos y la franja diría
    // que hay alguien esperando hora y media cuando no es cierto.
    $t = tenderoConPedidos([[2, 90], [1, 5]]);

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson('/v1/negocio/me')->assertOk();

    expect($r->json('stats.nuevos'))->toBe(1)
        ->and(abs(now()->diffInMinutes($r->json('stats.espera_desde'))))->toBeLessThan(10);
});

test('sin pedidos sin aceptar no hay nada que contar', function () {
    $t = tenderoConPedidos([[2, 30], [4, 200]]);

    Sanctum::actingAs($t['tendero']);
    $r = $this->getJson('/v1/negocio/me')->assertOk();

    // `null`, no la fecha de hoy: con una fecha cualquiera la franja se
    // pintaría sola en todas las pantallas sin que haya nadie esperando.
    expect($r->json('stats.nuevos'))->toBe(0)
        ->and($r->json('stats.espera_desde'))->toBeNull();
});
