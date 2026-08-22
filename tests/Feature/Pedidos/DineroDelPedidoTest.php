<?php

use App\Models\Marketing\Coupon;
use App\Models\Rol;
use App\Models\User;
use App\Services\Ajustes;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Las tres cifras de dinero que se congelan en el pedido.
 *
 * Lo que se fija acá es sobre todo QUIÉN PAGA QUÉ, que hasta ahora era una
 * sola cifra y no se podía repartir:
 *
 *   · la comisión de la plataforma sale del negocio, no del domicilio;
 *   · el subsidio del domicilio sale de la plataforma, nunca del repartidor;
 *   · y las tres se congelan, para que mover un ajuste no reescriba lo ya
 *     liquidado.
 */

function escenarioDinero(): array
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $user = User::factory()->create(['rol' => 1]);
    Sanctum::actingAs($user);

    DB::table('buyer')->insert([
        'user_id' => $user->user_id, 'qualification' => 0,
        'belongs_to_complex' => 0, 'state' => 1,
    ]);

    $businessId = DB::table('business')->insertGetId([
        'name' => 'Tienda', 'qualification' => 0, 'state' => 1,
    ]);

    $productId = DB::table('products')->insertGetId([
        'name' => 'Arroz', 'state' => 1,
    ], 'products_id');

    DB::table('products_business')->insert([
        'busines_id' => $businessId, 'products_id' => $productId,
        'price' => 10000, 'amount' => 100,
    ]);

    $addressId = DB::table('user_address')->insertGetId([
        'user_id' => $user->user_id, 'address' => 'Carrera 1 # 2-3', 'state' => 1,
    ]);

    $methodId = DB::table('payment_methods')->insertGetId([
        'name' => 'Efectivo', 'state' => 1,
    ]);

    return compact('user', 'businessId', 'productId', 'addressId', 'methodId');
}

function crearPedido(array $e, array $extra = []): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/v1/orders/orders', array_merge([
        'user_id'    => $e['user']->user_id,
        'busines_id' => $e['businessId'],
        'address_id' => $e['addressId'],
        'methods_id' => $e['methodId'],
        'products'   => [['product_id' => $e['productId'], 'amount' => 3]],
    ], $extra));
}

function ultimoPedido(): object
{
    return DB::table('orderssales')->latest('orderSales_id')->first();
}

/* ---------------------------------------------------------------------- */

test('la comisión de la plataforma se calcula sobre la venta del negocio', function () {
    $e = escenarioDinero();
    crearPedido($e)->assertSuccessful();

    $o = ultimoPedido();

    // 3 x 10.000 = 30.000, sin descuento. El 3 % son 900.
    expect((float) $o->subtotal)->toBe(30000.0)
        ->and((float) $o->platform_fee)->toBe(900.0);
});

test('la comisión NO se cobra sobre el domicilio', function () {
    $e = escenarioDinero();
    crearPedido($e)->assertSuccessful();

    $o = ultimoPedido();

    // Si entrara el domicilio, sobre 32.000 serían 960 y no 900. El domicilio
    // no es venta del negocio: tiene su propio reparto.
    expect((float) $o->platform_fee)
        ->toBe(round(30000 * 0.03, 2));
});

test('un descuento de cupón baja también la comisión', function () {
    $e = escenarioDinero();

    Coupon::create([
        'code' => 'MITAD', 'type' => Coupon::TIPO_PORCENTAJE, 'value' => 50,
        'starts_at' => now()->subDay()->toDateString(),
        'ends_at' => now()->addDay()->toDateString(),
    ]);

    crearPedido($e, ['coupon_code' => 'MITAD'])->assertSuccessful();

    $o = ultimoPedido();

    // Cobrarle el 3 % de 30.000 cuando solo va a recibir 15.000 sería cobrarle
    // dos veces la promoción.
    expect((float) $o->discount)->toBe(15000.0)
        ->and((float) $o->platform_fee)->toBe(450.0);
});

test('con envío gratis el cliente no paga domicilio pero el repartidor cobra igual', function () {
    $e = escenarioDinero();

    Coupon::create([
        'code' => 'ENVIOGRATIS', 'type' => Coupon::TIPO_ENVIO_GRATIS, 'value' => 0,
        'starts_at' => now()->subDay()->toDateString(),
        'ends_at' => now()->addDay()->toDateString(),
    ]);

    crearPedido($e, ['coupon_code' => 'ENVIOGRATIS'])->assertSuccessful();

    $o     = ultimoPedido();
    $base  = (float) Ajustes::valor('operacion.tarifa_domicilio');
    $parte = (float) Ajustes::valor('operacion.reparto_domiciliario');

    expect((float) $o->domicilio)->toBe(0.0)
        ->and((float) $o->delivery_subsidy)->toBe($base)
        // LA LÍNEA QUE IMPORTA: antes esto habría sido 0 y el viaje, gratis.
        ->and((float) $o->domiciliary_fee)->toBe(round($base * $parte))
        ->and((float) $o->total)->toBe(30000.0);
});

test('el envío gratis no rebaja los productos', function () {
    $e = escenarioDinero();

    Coupon::create([
        'code' => 'ENVIOGRATIS', 'type' => Coupon::TIPO_ENVIO_GRATIS, 'value' => 0,
        'starts_at' => now()->subDay()->toDateString(),
        'ends_at' => now()->addDay()->toDateString(),
    ]);

    crearPedido($e, ['coupon_code' => 'ENVIOGRATIS'])->assertSuccessful();

    expect((float) ultimoPedido()->discount)->toBe(0.0);
});

test('un pedido para recoger no tiene domicilio ni subsidio', function () {
    $e = escenarioDinero();
    crearPedido($e, ['pickup' => true])->assertSuccessful();

    $o = ultimoPedido();

    expect((float) $o->domicilio)->toBe(0.0)
        ->and((float) $o->delivery_subsidy)->toBe(0.0)
        // No hay viaje que pagar.
        ->and((float) $o->domiciliary_fee)->toBe(0.0);
});

test('cambiar la comisión no reescribe pedidos ya creados', function () {
    // La razón de ser de la columna. Sin congelar, subir la comisión le
    // cambiaría a un negocio lo que ya se le liquidó el mes pasado.
    $e = escenarioDinero();
    crearPedido($e)->assertSuccessful();

    $id = ultimoPedido()->orderSales_id;

    Ajustes::guardar(['operacion.comision_plataforma' => 0.10], null);

    expect((float) DB::table('orderssales')->where('orderSales_id', $id)->value('platform_fee'))
        ->toBe(900.0);
});

test('un pedido nuevo sí usa la comisión nueva', function () {
    $e = escenarioDinero();
    Ajustes::guardar(['operacion.comision_plataforma' => 0.10], null);

    crearPedido($e)->assertSuccessful();

    expect((float) ultimoPedido()->platform_fee)->toBe(3000.0);
});
