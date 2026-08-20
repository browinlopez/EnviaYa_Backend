<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Cancelación de un pedido por parte del comprador.
 *
 * La app pintaba un botón "Cancelar" sin acción y el servidor no tenía por
 * dónde: `updateStatus` solo admite los estados 2, 3 y 4. Lo que se fija acá
 * son las tres reglas de la acción nueva:
 *
 *  · solo el dueño del pedido,
 *  · solo mientras la tienda no lo haya aceptado (estado 1),
 *  · y que el pedido acabe en 5 (Cancelado), no borrado.
 */

/** Comprador con sesión abierta y un pedido suyo en el estado que se pida. */
function pedidoDe(int $estado = 1): array
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $user = User::factory()->create(['rol' => 1]);

    $buyerId = DB::table('buyer')->insertGetId([
        'user_id'            => $user->user_id,
        'qualification'      => 0,
        'belongs_to_complex' => 0,
        'state'              => 1,
    ]);

    $businessId = DB::table('business')->insertGetId([
        'name'          => 'Tienda del Pedido',
        'qualification' => 0,
        'state'         => 1,
    ]);

    $orderId = DB::table('orderssales')->insertGetId([
        'buyer_id'   => $buyerId,
        'busines_id' => $businessId,
        'subtotal'   => 10000,
        'domicilio'  => 2000,
        'total'      => 12000,
        'sale_date'  => now(),
        'state'      => $estado,
    ], 'orderSales_id');

    return compact('user', 'orderId');
}

/* ---------------------------------------------------------------------- */

test('el comprador cancela su pedido mientras nadie lo ha tocado', function () {
    ['user' => $user, 'orderId' => $orderId] = pedidoDe(1);
    Sanctum::actingAs($user);

    $this->putJson("/v1/orders/{$orderId}/cancel")
        ->assertOk()
        ->assertJsonPath('message', 'Pedido cancelado.');

    // Cancelado es un estado, no una baja: el pedido sigue en el historial.
    expect((int) DB::table('orderssales')->where('orderSales_id', $orderId)->value('state'))
        ->toBe(5);
});

test('no se puede cancelar un pedido que la tienda ya aceptó', function () {
    ['user' => $user, 'orderId' => $orderId] = pedidoDe(2);
    Sanctum::actingAs($user);

    $this->putJson("/v1/orders/{$orderId}/cancel")->assertStatus(422);

    expect((int) DB::table('orderssales')->where('orderSales_id', $orderId)->value('state'))
        ->toBe(2);
});

test('nadie puede cancelar el pedido de otra persona', function () {
    // El punto del caso: `updateStatus` no comprueba pertenencia y basta con
    // saber el identificador. Acá no.
    ['orderId' => $orderId] = pedidoDe(1);

    $intruso = User::factory()->create(['rol' => 1]);
    Sanctum::actingAs($intruso);

    $this->putJson("/v1/orders/{$orderId}/cancel")->assertStatus(403);

    expect((int) DB::table('orderssales')->where('orderSales_id', $orderId)->value('state'))
        ->toBe(1);
});

test('un pedido que no existe responde 404 y no 500', function () {
    ['user' => $user] = pedidoDe(1);
    Sanctum::actingAs($user);

    $this->putJson('/v1/orders/999999/cancel')->assertStatus(404);
});
