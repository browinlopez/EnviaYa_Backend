<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * LO QUE UN PEDIDO LE HACE AL INVENTARIO, Y LO QUE NO SE PUEDE COMPRAR GRATIS.
 *
 * Tres reglas, las tres pedidas por el negocio:
 *
 *  · un producto a $0 no entra a un pedido, aunque llegue en el carrito;
 *  · al comprar se descuentan las unidades;
 *  · y aunque el número quede en negativo, se puede seguir vendiendo.
 *
 * Y dos que salen de cómo está hecho el pedido: un reintento de pago no resta dos
 * veces, y cancelar devuelve solo lo que se había restado.
 */

/** Una tienda con productos y un comprador listo para pedir. */
function tiendaConInventario(array $productos): array
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $comprador = User::factory()->create(['rol' => 1]);

    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $comprador->user_id, 'qualification' => 0,
        'belongs_to_complex' => 0, 'state' => 1,
    ], 'buyer_id');

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');

    $negocio = DB::table('business')->insertGetId([
        'name' => 'Tienda del inventario', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
        'latitude' => 11.0, 'longitude' => -74.8,
    ], 'busines_id');

    $ids = [];
    foreach ($productos as $nombre => [$precio, $existencias]) {
        $id = DB::table('products')->insertGetId(['name' => $nombre, 'state' => 1], 'products_id');
        DB::table('products_business')->insert([
            'busines_id' => $negocio, 'products_id' => $id, 'price' => $precio, 'amount' => $existencias,
        ]);
        $ids[$nombre] = $id;
    }

    $direccion = DB::table('user_address')->insertGetId([
        'user_id' => $comprador->user_id, 'address' => 'Calle 1',
        'latitude' => 11.001, 'longitude' => -74.801, 'state' => 1,
    ], 'address_id');

    // Efectivo y la pasarela: la base de pruebas nace sin medios de pago.
    DB::table('payment_methods')->insertOrIgnore([
        ['methods_id' => 1, 'name' => 'Efectivo', 'state' => 1],
        ['methods_id' => 2, 'name' => 'Tarjeta', 'state' => 1],
    ]);

    Sanctum::actingAs($comprador);

    return compact('comprador', 'buyerId', 'negocio', 'ids', 'direccion');
}

function pedir(array $t, array $lineas, int $metodo = 1)
{
    if ($metodo === 2) {
        /*
         * La pasarela de verdad llamaría a Bold. Lo que se prueba acá no es el
         * cobro sino que el pedido quede esperándolo —eso lo marca
         * ArmadoDelPedido— para que el siguiente intento lo reutilice.
         */
        test()->mock(\App\Services\PagoEnLinea::class, fn ($m) => $m->shouldReceive('cobrar')->andReturn(null));
    }

    return test()->postJson('/v1/orders/orders', [
        'user_id'    => $t['comprador']->user_id,
        'busines_id' => $t['negocio'],
        'address_id' => $t['direccion'],
        'products'   => collect($lineas)->map(fn ($c, $n) => ['product_id' => $t['ids'][$n], 'amount' => $c])->values()->all(),
        'methods_id' => $metodo,
    ] + ($metodo === 2 ? ['payment_method' => ['type' => 'CARD']] : []));
}

function existencias(array $t, string $nombre): int
{
    return (int) DB::table('products_business')
        ->where('busines_id', $t['negocio'])
        ->where('products_id', $t['ids'][$nombre])
        ->value('amount');
}

/* --------------------------------------------------------- precio en cero -- */

it('un producto a $0 no entra al pedido, aunque llegue en el carrito', function () {
    /*
     * La vitrina ya lo escondía; lo que faltaba era que el servidor lo
     * rechazara al cobrar. En producción había diez así.
     */
    $t = tiendaConInventario(['Crema dental' => [0, 10], 'Arroz' => [3000, 10]]);

    pedir($t, ['Crema dental' => 2, 'Arroz' => 1])
        ->assertStatus(422)
        ->assertJsonPath('product_ids', [$t['ids']['Crema dental']]);

    // Rechazado entero: ni el pedido se crea ni el arroz se descuenta.
    expect(DB::table('orderssales')->count())->toBe(0)
        ->and(existencias($t, 'Arroz'))->toBe(10);
});

it('la cotización tampoco cuenta un producto a $0', function () {
    // Mismo método que el cobro: lo que se enseña y lo que se cobra no se separan.
    $t = tiendaConInventario(['Crema dental' => [0, 10]]);

    test()->postJson('/v1/orders/cotizar', [
        'busines_id' => $t['negocio'],
        'products'   => [['product_id' => $t['ids']['Crema dental'], 'amount' => 1]],
    ])->assertStatus(422);
});

/* -------------------------------------------------------------- descontar -- */

it('al comprar se descuentan las unidades de cada producto', function () {
    $t = tiendaConInventario(['Arroz' => [3000, 10], 'Aceite' => [12500, 5]]);

    pedir($t, ['Arroz' => 3, 'Aceite' => 1])->assertStatus(201);

    expect(existencias($t, 'Arroz'))->toBe(7)
        ->and(existencias($t, 'Aceite'))->toBe(4)
        ->and((bool) DB::table('orderssales')->value('stock_descontado'))->toBeTrue();
});

it('puede quedar en negativo y se sigue vendiendo', function () {
    /*
     * La regla del negocio: el conteo de la tienda casi nunca es exacto, y
     * bloquear una venta por un número que nadie contó es perder el pedido de
     * algo que sí está en el estante.
     */
    $t = tiendaConInventario(['Panela' => [2200, 2]]);

    pedir($t, ['Panela' => 5])->assertStatus(201);
    expect(existencias($t, 'Panela'))->toBe(-3);

    // Y con el número ya en negativo, otra compra entra igual.
    pedir($t, ['Panela' => 1])->assertStatus(201);
    expect(existencias($t, 'Panela'))->toBe(-4);
});

it('reintentar el pago no resta el carrito dos veces', function () {
    /*
     * El pedido que quedó esperando el cobro se REUTILIZA al volver a pagar, y
     * sus líneas se reescriben. Sin devolver lo del intento anterior, cada clic
     * restaría otra vez.
     */
    $t = tiendaConInventario(['Arroz' => [3000, 10]]);

    pedir($t, ['Arroz' => 2], metodo: 2)->assertStatus(201);
    pedir($t, ['Arroz' => 2], metodo: 2)->assertStatus(201);
    pedir($t, ['Arroz' => 2], metodo: 2)->assertStatus(201);

    expect(DB::table('orderssales')->count())->toBe(1)
        ->and(existencias($t, 'Arroz'))->toBe(8);
});

it('si el reintento cambia el carrito, cuenta lo de ahora y no lo de antes', function () {
    $t = tiendaConInventario(['Arroz' => [3000, 10], 'Aceite' => [3000, 10]]);

    pedir($t, ['Arroz' => 2], metodo: 2)->assertStatus(201);
    // Mismo total —así se reutiliza el pedido—, otro producto.
    pedir($t, ['Aceite' => 2], metodo: 2)->assertStatus(201);

    expect(existencias($t, 'Arroz'))->toBe(10)
        ->and(existencias($t, 'Aceite'))->toBe(8);
});

/* --------------------------------------------------------------- cancelar -- */

it('cancelar devuelve las unidades', function () {
    $t = tiendaConInventario(['Arroz' => [3000, 10]]);

    pedir($t, ['Arroz' => 4])->assertStatus(201);
    expect(existencias($t, 'Arroz'))->toBe(6);

    $pedido = DB::table('orderssales')->value('orderSales_id');
    test()->putJson("/v1/orders/{$pedido}/cancel")->assertOk();

    expect(existencias($t, 'Arroz'))->toBe(10)
        ->and((bool) DB::table('orderssales')->value('stock_descontado'))->toBeFalse();
});

it('cancelar un pedido de antes de este cambio no infla el inventario', function () {
    /*
     * Los pedidos que ya existían nunca restaron nada. Si al cancelarse
     * devolvieran, la tienda terminaría con más unidades de las que tiene.
     */
    $t = tiendaConInventario(['Arroz' => [3000, 10]]);

    $pedido = DB::table('orderssales')->insertGetId([
        'buyer_id' => $t['buyerId'], 'busines_id' => $t['negocio'],
        'subtotal' => 3000, 'domicilio' => 0, 'total' => 3000,
        'sale_date' => now(), 'state' => 1,
    ], 'orderSales_id');
    DB::table('orderssales_detail')->insert([
        'orderSales_id' => $pedido, 'product_id' => $t['ids']['Arroz'], 'amount' => 1, 'unit_price' => 3000,
    ]);

    test()->putJson("/v1/orders/{$pedido}/cancel")->assertOk();

    expect(existencias($t, 'Arroz'))->toBe(10);
});
