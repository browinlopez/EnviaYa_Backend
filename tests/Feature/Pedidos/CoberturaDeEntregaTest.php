<?php

use App\Models\Rol;
use App\Models\User;
use App\Services\Ajustes;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * HASTA DÓNDE REPARTE UNA TIENDA.
 *
 * `operacion.radio_maximo_km` existía, `TarifaPorDistancia::reparteHasta` lo
 * calculaba y los listados devolvían `in_range` por negocio. No lo miraba
 * NADIE: ni la app ni la creación del pedido. Con el radio en 6 km se podía
 * pedir a 12,5 y el servidor lo aceptaba, cobrando el escalón que tocara.
 *
 * La comprobación va en `OrderController::store` por lo mismo que la de
 * negocio apagado: filtrar los listados no basta, porque basta con conservar
 * el identificador —un favorito, un pedido viejo, una pantalla abierta— para
 * saltárselo. Lo que haga la app es cortesía; la puerta está acá.
 */

/** Comprador, negocio con coordenadas, producto y método de pago. */
function escenarioCobertura(?float $latNegocio = 11.0168543, ?float $lonNegocio = -74.8029776): array
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $user = User::factory()->create(['rol' => 1]);
    Sanctum::actingAs($user);

    DB::table('buyer')->insert([
        'user_id'            => $user->user_id,
        'qualification'      => 0,
        'belongs_to_complex' => 0,
        'state'              => 1,
    ]);

    $businessId = DB::table('business')->insertGetId([
        'name'          => 'Tienda con reparto limitado',
        'qualification' => 0,
        'state'         => 1,
        'latitude'      => $latNegocio,
        'longitude'     => $lonNegocio,
    ]);

    $productId = DB::table('products')->insertGetId([
        'name'  => 'Arroz',
        'state' => 1,
    ], 'products_id');

    DB::table('products_business')->insert([
        'busines_id'  => $businessId,
        'products_id' => $productId,
        'price'       => 10000,
        'amount'      => 100,
    ]);

    $methodId = DB::table('payment_methods')->insertGetId([
        'name'  => 'Efectivo',
        'state' => 1,
    ]);

    return compact('user', 'businessId', 'productId', 'methodId');
}

/** Una dirección del comprador en el punto que se le diga. */
function direccionEn(array $e, ?float $lat, ?float $lon): int
{
    return DB::table('user_address')->insertGetId([
        'user_id'   => $e['user']->user_id,
        'address'   => 'Calle de prueba',
        'latitude'  => $lat,
        'longitude' => $lon,
        'state'     => 1,
    ]);
}

function pedidoHacia(array $e, int $addressId, array $extra = []): array
{
    return array_merge([
        'user_id'    => $e['user']->user_id,
        'busines_id' => $e['businessId'],
        'address_id' => $addressId,
        'methods_id' => $e['methodId'],
        'products'   => [['product_id' => $e['productId'], 'amount' => 1]],
    ], $extra);
}

/* ---------------------------------------------------------------------- */

test('dentro del radio el pedido se crea', function () {
    Ajustes::guardar(['operacion.radio_maximo_km' => 6.0], null);

    $e = escenarioCobertura();
    // Medio kilómetro al oeste de la tienda.
    $cerca = direccionEn($e, 11.0168543, -74.7979776);

    $this->postJson('/v1/orders/orders', pedidoHacia($e, $cerca))
        ->assertSuccessful();

    expect(DB::table('orderssales')->count())->toBe(1);
});

test('fuera del radio se rechaza y no queda pedido a medias', function () {
    Ajustes::guardar(['operacion.radio_maximo_km' => 6.0], null);

    $e = escenarioCobertura();
    // Soledad: 12,5 km en línea recta. El doble del radio.
    $lejos = direccionEn($e, 10.9080932, -74.7739365);

    $this->postJson('/v1/orders/orders', pedidoHacia($e, $lejos))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'out_of_range');

    /*
     * Lo que de verdad importa: que no quede un pedido escrito. La
     * comprobación va ANTES de abrir la transacción justamente por esto.
     */
    expect(DB::table('orderssales')->count())->toBe(0);
});

test('recoger en tienda no depende del radio', function () {
    // Quien va por su pedido puede vivir donde quiera: no hay reparto que
    // limitar, y bloquearlo sería perder una venta por una regla que no aplica.
    Ajustes::guardar(['operacion.radio_maximo_km' => 6.0], null);

    $e = escenarioCobertura();

    $this->postJson('/v1/orders/orders', array_merge(
        pedidoHacia($e, 0),
        ['pickup' => true, 'address_id' => null],
    ))->assertSuccessful();
});

test('sin coordenadas se deja pasar, no se castiga el dato que falta', function () {
    /*
     * Los negocios cargados antes de que existiera la ubicación no tienen
     * latitud. Bloquearles las ventas por eso sería castigarlos por algo que
     * no decidieron; es la misma decisión que toma la tarifa.
     */
    Ajustes::guardar(['operacion.radio_maximo_km' => 6.0], null);

    $e = escenarioCobertura(null, null);
    $lejos = direccionEn($e, 10.9080932, -74.7739365);

    $this->postJson('/v1/orders/orders', pedidoHacia($e, $lejos))
        ->assertSuccessful();
});

test('en cero no hay limite', function () {
    // Es la salida para operar sin cobertura definida, y es como estaba el
    // sistema hasta ahora.
    Ajustes::guardar(['operacion.radio_maximo_km' => 0], null);

    $e = escenarioCobertura();
    $lejos = direccionEn($e, 10.9080932, -74.7739365);

    $this->postJson('/v1/orders/orders', pedidoHacia($e, $lejos))
        ->assertSuccessful();
});

test('el limite se mueve desde el panel', function () {
    $e = escenarioCobertura();
    $lejos = direccionEn($e, 10.9080932, -74.7739365);

    Ajustes::guardar(['operacion.radio_maximo_km' => 6.0], null);
    $this->postJson('/v1/orders/orders', pedidoHacia($e, $lejos))->assertStatus(422);

    // Ampliando la cobertura, la misma dirección entra.
    Ajustes::guardar(['operacion.radio_maximo_km' => 20.0], null);
    $this->postJson('/v1/orders/orders', pedidoHacia($e, $lejos))->assertSuccessful();
});
