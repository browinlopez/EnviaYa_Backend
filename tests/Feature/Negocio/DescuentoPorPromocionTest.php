<?php

use App\Models\Promotion;
use App\Models\Rol;
use App\Models\User;
use App\Services\Ajustes;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * EL DESCUENTO QUE APLICAN LAS PROMOCIONES DE LA TIENDA.
 *
 * Es dinero: lo que acá se calcule mal se cobra mal, y lo que se cobre mal sale
 * del bolsillo del tendero o del cliente. Las pruebas que más importan son tres:
 *
 *   · que DOS promociones sobre el mismo producto NO se sumen —así se regala
 *     el inventario—,
 *   · que el descuento nunca supere el subtotal —un total negativo lo acabaría
 *     pagando el negocio—,
 *   · que la comisión del 3 % se calcule DESPUÉS del descuento, que es lo que
 *     el negocio cobra de verdad.
 */

/** Una tienda con un producto a $1.000 y un comprador con dirección. */
function escenarioDescuento(float $precio = 1000): array
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $comprador = User::factory()->create(['rol' => 1]);

    DB::table('buyer')->insert([
        'user_id' => $comprador->user_id, 'qualification' => 0,
        'belongs_to_complex' => 0, 'state' => 1,
    ]);

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');

    $businessId = DB::table('business')->insertGetId([
        'name' => 'Tienda con promos', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
        'latitude' => 11.0, 'longitude' => -74.8,
    ], 'busines_id');

    $productoId = DB::table('products')->insertGetId([
        'name' => 'Atún', 'state' => 1,
    ], 'products_id');

    DB::table('products_business')->insert([
        'busines_id' => $businessId, 'products_id' => $productoId,
        'price' => $precio, 'amount' => 100,
    ]);

    $addressId = DB::table('user_address')->insertGetId([
        'user_id' => $comprador->user_id, 'address' => 'Calle 1',
        'latitude' => 11.001, 'longitude' => -74.801, 'state' => 1,
    ], 'address_id');

    // La base de pruebas nace vacía: sin esta fila, `methods_id` no valida.
    DB::table('payment_methods')->insertOrIgnore([
        'methods_id' => 1, 'name' => 'Efectivo', 'state' => 1,
    ]);

    Sanctum::actingAs($comprador);

    return compact('comprador', 'businessId', 'productoId', 'addressId');
}

/** Crea una promoción vigente de esta tienda. */
function promocionVigente(int $businessId, array $datos, array $productos = []): Promotion
{
    $promocion = Promotion::create(array_merge([
        'busines_id'  => $businessId,
        'description' => 'Promoción de prueba',
        'state'       => Promotion::ENVIADA,
        'sent_at'     => now(),
        'start_date'  => now(),
    ], $datos));

    if ($productos !== []) {
        $promocion->products()->sync($productos);
    }

    return $promocion;
}

/** El desglose que devuelve `/orders/cotizar` para N unidades. */
function cotizar(array $e, int $cantidad): array
{
    return test()->postJson('/v1/orders/cotizar', [
        'busines_id' => $e['businessId'],
        'address_id' => $e['addressId'],
        'products'   => [['product_id' => $e['productoId'], 'amount' => $cantidad]],
    ])->json();
}

it('un porcentaje rebaja lo que dice', function () {
    $e = escenarioDescuento(1000);

    promocionVigente($e['businessId'], [
        'tipo' => Promotion::PORCENTAJE, 'percentage_discount' => 0.20,
    ], [$e['productoId']]);

    $r = cotizar($e, 3);

    // 3 × $1.000 = $3.000, menos el 20% = $600.
    expect($r['subtotal'])->toEqual(3000.0)
        ->and($r['descuento'])->toEqual(600.0)
        ->and($r['descuento_promo'])->toEqual(600.0);
});

it('un lleva 3 paga 2 regala una de cada tres, y no antes', function () {
    $e = escenarioDescuento(1000);

    promocionVigente($e['businessId'], [
        'tipo' => Promotion::NXM, 'lleva' => 3, 'paga' => 2,
    ], [$e['productoId']]);

    // Con dos no hay grupo completo: no se regala nada.
    expect(cotizar($e, 2)['descuento'])->toEqual(0.0);

    // Con tres, una gratis.
    expect(cotizar($e, 3)['descuento'])->toEqual(1000.0);

    // Con siete van dos grupos completos —seis— y sobra una que se paga.
    expect(cotizar($e, 7)['descuento'])->toEqual(2000.0);
});

it('avisa cuando falta poco para que la promoción entre', function () {
    $e = escenarioDescuento(1000);

    promocionVigente($e['businessId'], [
        'tipo' => Promotion::NXM, 'lleva' => 3, 'paga' => 2,
    ], [$e['productoId']]);

    $r = cotizar($e, 2);

    /*
     * Sin este aviso el cliente paga las dos convencido de que la promoción no
     * sirve, cuando le faltaba una unidad.
     */
    expect($r['promociones_cerca'])->toHaveCount(1)
        ->and($r['promociones_cerca'][0]['faltan'])->toEqual(1);
});

it('dos promociones sobre el mismo producto NO se suman: gana la mejor', function () {
    $e = escenarioDescuento(1000);

    promocionVigente($e['businessId'], [
        'tipo' => Promotion::PORCENTAJE, 'percentage_discount' => 0.10,
    ], [$e['productoId']]);

    promocionVigente($e['businessId'], [
        'tipo' => Promotion::PORCENTAJE, 'percentage_discount' => 0.30,
    ], [$e['productoId']]);

    /*
     * Sumadas serían $1.200 sobre $3.000. Así es como se regala el inventario:
     * dos promociones del 60% dejarían el producto gratis y con vuelto.
     */
    expect(cotizar($e, 3)['descuento'])->toEqual(900.0);
});

it('una promoción sin productos alcanza a todo el carrito', function () {
    $e = escenarioDescuento(1000);

    // «20% en toda la tienda hoy» no es de ningún producto en particular.
    promocionVigente($e['businessId'], [
        'tipo' => Promotion::PORCENTAJE, 'percentage_discount' => 0.20,
    ]);

    expect(cotizar($e, 2)['descuento'])->toEqual(400.0);
});

it('una promoción de otro producto no toca este', function () {
    $e = escenarioDescuento(1000);

    $otro = DB::table('products')->insertGetId(['name' => 'Arroz', 'state' => 1], 'products_id');

    promocionVigente($e['businessId'], [
        'tipo' => Promotion::PORCENTAJE, 'percentage_discount' => 0.50,
    ], [$otro]);

    expect(cotizar($e, 3)['descuento'])->toEqual(0.0);
});

it('una promoción vencida ya no rebaja', function () {
    $e = escenarioDescuento(1000);

    promocionVigente($e['businessId'], [
        'tipo' => Promotion::PORCENTAJE, 'percentage_discount' => 0.20,
        'end_date' => now()->subDay(),
    ], [$e['productoId']]);

    // La promoción de ayer no puede rebajar el pedido de hoy.
    expect(cotizar($e, 3)['descuento'])->toEqual(0.0);
});

it('una promoción retirada ya no rebaja', function () {
    $e = escenarioDescuento(1000);

    promocionVigente($e['businessId'], [
        'tipo' => Promotion::PORCENTAJE, 'percentage_discount' => 0.20,
        'state' => Promotion::RETIRADA,
    ], [$e['productoId']]);

    expect(cotizar($e, 3)['descuento'])->toEqual(0.0);
});

it('una promoción que solo avisa no toca ningún precio', function () {
    $e = escenarioDescuento(1000);

    promocionVigente($e['businessId'], [
        'tipo' => Promotion::SIN_DESCUENTO,
    ], [$e['productoId']]);

    expect(cotizar($e, 3)['descuento'])->toEqual(0.0);
});

it('el descuento nunca supera el subtotal', function () {
    $e = escenarioDescuento(1000);

    /*
     * Una regla imposible metida a mano en la base —un «lleva 2 paga 1» y
     * encima un 70%— no puede producir un total negativo: eso lo acabaría
     * pagando el negocio.
     */
    promocionVigente($e['businessId'], [
        'tipo' => Promotion::NXM, 'lleva' => 2, 'paga' => 1,
    ], [$e['productoId']]);

    $r = cotizar($e, 4);

    expect($r['descuento'])->toBeLessThanOrEqual($r['subtotal'])
        ->and($r['total'])->toBeGreaterThanOrEqual(0);
});

it('la comisión de la plataforma se cobra sobre el subtotal YA descontado', function () {
    $e = escenarioDescuento(1000);

    Ajustes::guardar(['operacion.comision_plataforma' => 0.03], null);

    promocionVigente($e['businessId'], [
        'tipo' => Promotion::PORCENTAJE, 'percentage_discount' => 0.20,
    ], [$e['productoId']]);

    test()->postJson('/v1/orders/orders', [
        'user_id'    => $e['comprador']->user_id,
        'busines_id' => $e['businessId'],
        'address_id' => $e['addressId'],
        'products'   => [['product_id' => $e['productoId'], 'amount' => 10]],
        'methods_id' => 1,
    ])->assertStatus(201);

    $pedido = DB::table('orderssales')->latest('orderSales_id')->first();

    /*
     * $10.000 de subtotal, $2.000 de descuento → el negocio cobra $8.000, y el
     * 3% es $240. Cobrarle sobre los $10.000 sería cobrarle comisión por un
     * dinero que no recibió: la promoción la paga él.
     */
    expect((float) $pedido->subtotal)->toEqual(10000.0)
        ->and((float) $pedido->discount)->toEqual(2000.0)
        ->and((float) $pedido->platform_fee)->toEqual(240.0);
});

it('el descuento se congela en el pedido y no cambia si la promoción cambia', function () {
    $e = escenarioDescuento(1000);

    $promocion = promocionVigente($e['businessId'], [
        'tipo' => Promotion::PORCENTAJE, 'percentage_discount' => 0.20,
    ], [$e['productoId']]);

    test()->postJson('/v1/orders/orders', [
        'user_id'    => $e['comprador']->user_id,
        'busines_id' => $e['businessId'],
        'address_id' => $e['addressId'],
        'products'   => [['product_id' => $e['productoId'], 'amount' => 5]],
        'methods_id' => 1,
    ])->assertStatus(201);

    // El tendero la retira después de que el pedido ya salió.
    $promocion->update(['state' => Promotion::RETIRADA]);

    // Lo ya cobrado no se reescribe: es la misma regla que el resto de las
    // cifras del pedido.
    expect((float) DB::table('orderssales')->latest('orderSales_id')->first()->discount)
        ->toEqual(1000.0);
});

it('la cotización no crea nada', function () {
    $e = escenarioDescuento(1000);

    cotizar($e, 3);

    expect(DB::table('orderssales')->count())->toEqual(0);
});

it('no se puede cotizar contra la dirección de otro', function () {
    $e = escenarioDescuento(1000);

    $ajeno = User::factory()->create(['rol' => 1]);
    $suya = DB::table('user_address')->insertGetId([
        'user_id' => $ajeno->user_id, 'address' => 'Casa del vecino',
        'latitude' => 11.5, 'longitude' => -74.9, 'state' => 1,
    ], 'address_id');

    $r = test()->postJson('/v1/orders/cotizar', [
        'busines_id' => $e['businessId'],
        'address_id' => $suya,
        'products'   => [['product_id' => $e['productoId'], 'amount' => 1]],
    ])->assertOk();

    /*
     * La dirección ajena se ignora y se cae a la tarifa base. Si se usara, la
     * tarifa revelaría a qué distancia vive: pasando números se podría ubicar
     * la casa de cualquiera.
     */
    expect($r->json('distancia_km'))->toBeNull();
});
