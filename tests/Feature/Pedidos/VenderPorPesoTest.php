<?php

use App\Models\Order\OrdersSales;
use App\Models\Order\OrdersSalesDetail;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * VENDER POR PESO.
 *
 * El peso no existía en la plataforma: ni unidad de medida, ni precio por
 * kilo, ni decimales. `amount` es entero en dos tablas y hay veintidós sitios
 * que lo dan por hecho. Media tienda de barrio —queso, tomate, carne— no cabía.
 *
 * LAS DOS DECISIONES QUE SOSTIENEN TODO, y que estas pruebas vigilan:
 *
 *  · `amount` NO se migró a decimal. En una línea al peso vale 1 y la
 *    cantidad de verdad va en columnas nuevas. Así, el código que no se enteró
 *    del cambio sigue siendo correcto porque solo opera sobre unidades.
 *
 *  · EL TOTAL NO CAMBIA AL PESAR. En la tienda se pide «3.000 de queso» o
 *    «500 g de tomate», y las dos formas fijan el precio al pedir. Lo que el
 *    cliente aceptó es lo que paga; el tendero pesa para acercarse. Por eso no
 *    hay cobros que ajustar ni diferencias que devolver.
 *
 * La primera prueba del archivo es la red de seguridad: un carrito de pura
 * unidad tiene que dar exactamente lo mismo que antes.
 */

/** Un producto que esta tienda vende por kilo, a $12.000 el kilo. */
function productoAlPeso(array $e, string $unidad = 'kg', float $precio = 12000): int
{
    $productId = DB::table('products')->insertGetId([
        'name'  => 'Queso campesino',
        'state' => 1,
    ], 'products_id');

    DB::table('products_business')->insert([
        'busines_id'   => $e['businessId'],
        'products_id'  => $productId,
        'price'        => $precio,
        'amount'       => 0,
        'sale_unit'    => $unidad,
        'stock_weight' => 10,
    ]);

    return $productId;
}

/** Una línea «3.000 de queso». */
function porPlata(int $productId, float $plata): array
{
    return [
        'product_id'      => $productId,
        'amount'          => 1,
        'pedido_por'      => 'valor',
        'requested_value' => $plata,
    ];
}

/** Una línea «500 g de queso», en la unidad del producto. */
function porCantidad(int $productId, float $cantidad): array
{
    return [
        'product_id'    => $productId,
        'amount'        => 1,
        'pedido_por'    => 'cantidad',
        'requested_qty' => $cantidad,
    ];
}

function pesar(array $e, int $orderId, array $lineas): \Illuminate\Testing\TestResponse
{
    return test()->putJson('/v1/negocio/pedidos/pesaje', [
        'order_id' => $orderId,
        'lineas'   => $lineas,
    ]);
}

/** El tendero dueño de la tienda del escenario, ya dentro. */
function tenderoDe(array $e): void
{
    \App\Models\Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);

    $user = \App\Models\User::factory()->create(['rol' => 2]);

    $ownerId = DB::table('owner')->insertGetId(['user_id' => $user->user_id, 'state' => 1], 'owner_id');
    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $e['businessId'], 'state' => 1,
    ]);

    Sanctum::actingAs($user);
}

/* ---------------------------------------------------------------------- */

test('un carrito de pura unidad da exactamente el mismo total que antes', function () {
    /*
     * LA RED DE SEGURIDAD DEL CAMBIO. Si esto se rompe, se rompió el checkout
     * de todo el mundo y no solo el de quien compra al peso.
     */
    $e = escenarioPedido();

    $this->postJson('/v1/orders/orders', cuerpoPedido($e))->assertSuccessful();

    $orden = OrdersSales::latest('orderSales_id')->first();

    expect((float) $orden->subtotal)->toBe(30000.0)   // 3 x 10.000
        ->and((int) $orden->weighing_state)->toBe(OrdersSales::SIN_PESAJE);

    $linea = OrdersSalesDetail::where('orderSales_id', $orden->orderSales_id)->first();

    expect((int) $linea->amount)->toBe(3)
        ->and($linea->sale_unit)->toBe('unidad')
        // En una línea de unidad el importe NO se congela: se sigue leyendo
        // como siempre, `unit_price * amount`.
        ->and($linea->line_total)->toBeNull();
});

test('«3.000 de queso»: lo que pidió es lo que vale', function () {
    $e = escenarioPedido();
    $queso = productoAlPeso($e);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, [
        'products' => [porPlata($queso, 3000)],
    ]))->assertSuccessful();

    $orden = OrdersSales::latest('orderSales_id')->first();
    $linea = OrdersSalesDetail::where('orderSales_id', $orden->orderSales_id)->first();

    expect((float) $orden->subtotal)->toBe(3000.0)
        ->and((float) $linea->line_total)->toBe(3000.0)
        // La cantidad se deduce de la plata y del precio: es la referencia
        // con la que el tendero pesa y contra la que se mide el desvío.
        ->and((float) $linea->qty_requested)->toBe(0.25)
        ->and($linea->pedido_por)->toBe('valor')
        ->and($linea->sale_unit)->toBe('kg')
        // Y `amount` vale 1: una línea, un concepto.
        ->and((int) $linea->amount)->toBe(1);
});

test('«500 g de queso»: peso por precio del kilo', function () {
    $e = escenarioPedido();
    $queso = productoAlPeso($e);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, [
        'products' => [porCantidad($queso, 0.5)],
    ]))->assertSuccessful();

    $orden = OrdersSales::latest('orderSales_id')->first();

    expect((float) $orden->subtotal)->toBe(6000.0);   // 0,5 x 12.000
});

test('un pedido con algo al peso nace por pesar', function () {
    $e = escenarioPedido();
    $queso = productoAlPeso($e);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, [
        'products' => [porPlata($queso, 3000)],
    ]))->assertSuccessful();

    expect((int) OrdersSales::latest('orderSales_id')->first()->weighing_state)
        ->toBe(OrdersSales::POR_PESAR);
});

test('no sale de la tienda sin pesar', function () {
    /*
     * Si saliera, el domiciliario se llevaría una bolsa cuyo contenido nadie
     * midió y el tendero perdería la única oportunidad de registrarlo.
     */
    $e = escenarioPedido();
    $queso = productoAlPeso($e);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, [
        'products' => [porPlata($queso, 3000)],
    ]))->assertSuccessful();

    $orden = OrdersSales::latest('orderSales_id')->first();
    $orden->state = 2;
    $orden->save();

    tenderoDe($e);

    $r = $this->putJson('/v1/orders/update', [
        'order_id' => $orden->orderSales_id,
        'state'    => 3,
        'user_id'  => $e['user']->user_id,
    ]);

    $r->assertStatus(422);
    expect($r->json('reason'))->toBe('falta_pesaje');
});

test('pesar guarda lo que pesó y NO cambia el total', function () {
    /*
     * El corazón del diseño. El cliente pidió $3.000 de queso: eso es lo que
     * paga, pese lo que pese la báscula. Si el total se moviera habría que
     * recobrar o devolver, y con QR o PSE eso es una devolución manual por
     * pedido.
     */
    $e = escenarioPedido();
    $queso = productoAlPeso($e);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, [
        'products' => [porPlata($queso, 3000)],
    ]))->assertSuccessful();

    $orden = OrdersSales::latest('orderSales_id')->first();
    $linea = OrdersSalesDetail::where('orderSales_id', $orden->orderSales_id)->first();
    $totalAntes = (float) $orden->total;

    tenderoDe($e);

    pesar($e, $orden->orderSales_id, [
        ['orderDet_id' => $linea->orderDet_id, 'qty_final' => 0.28],
    ])->assertOk();

    $orden = $orden->fresh();
    $linea = $linea->fresh();

    expect((float) $linea->qty_final)->toBe(0.28)
        ->and((float) $linea->line_total)->toBe(3000.0)
        ->and((float) $orden->total)->toBe($totalAntes)
        ->and((int) $orden->weighing_state)->toBe(OrdersSales::PESADO)
        ->and($linea->weighed_at)->not->toBeNull();
});

test('el inventario en peso no se trunca', function () {
    /*
     * El fallo que esto evita: `decrement('amount', (int) …)` convertía 1,8 kg
     * en 1. Por eso el peso vive en su propia columna y nunca pasa por ese
     * `(int)`.
     */
    $e = escenarioPedido();
    $queso = productoAlPeso($e);   // nace con 10 kg

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, [
        'products' => [porCantidad($queso, 1.8)],
    ]))->assertSuccessful();

    $enLaTienda = DB::table('products_business')
        ->where('busines_id', $e['businessId'])
        ->where('products_id', $queso)
        ->first();

    expect((float) $enLaTienda->stock_weight)->toBe(8.2);
});

test('al pesar solo se mueve la diferencia', function () {
    $e = escenarioPedido();
    $queso = productoAlPeso($e);   // 10 kg

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, [
        'products' => [porCantidad($queso, 1.0)],
    ]))->assertSuccessful();

    // Ya se descontó 1 kg al crear: quedan 9.
    $orden = OrdersSales::latest('orderSales_id')->first();
    $linea = OrdersSalesDetail::where('orderSales_id', $orden->orderSales_id)->first();

    tenderoDe($e);

    pesar($e, $orden->orderSales_id, [
        ['orderDet_id' => $linea->orderDet_id, 'qty_final' => 1.18],
    ])->assertOk();

    // Y ahora solo los 0,18 que faltaban.
    $stock = (float) DB::table('products_business')
        ->where('busines_id', $e['businessId'])
        ->where('products_id', $queso)
        ->value('stock_weight');

    expect(round($stock, 3))->toBe(8.82);
});

test('un peso disparatado no se puede cerrar', function () {
    /*
     * El único contrapeso a que el peso lo ponga una sola persona. Sin tope,
     * un pedido de «$3.000 de queso» se cerraría con tres kilos de más y el
     * inventario se descuadraría sin que nadie lo note.
     */
    $e = escenarioPedido();
    $queso = productoAlPeso($e);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, [
        'products' => [porCantidad($queso, 1.0)],
    ]))->assertSuccessful();

    $orden = OrdersSales::latest('orderSales_id')->first();
    $linea = OrdersSalesDetail::where('orderSales_id', $orden->orderSales_id)->first();

    tenderoDe($e);

    $r = pesar($e, $orden->orderSales_id, [
        ['orderDet_id' => $linea->orderDet_id, 'qty_final' => 3.0],
    ]);

    $r->assertStatus(422);
    expect($r->json('message'))->toContain('pidió');

    // Y no se escribió nada: ni el peso, ni el inventario.
    expect($linea->fresh()->qty_final)->toBeNull()
        ->and((int) $orden->fresh()->weighing_state)->toBe(OrdersSales::POR_PESAR);
});

test('un pedido ya pesado no se vuelve a pesar', function () {
    // Idempotencia ante un reintento de red: a estas alturas el inventario ya
    // se movió, y pesarlo otra vez lo movería dos veces.
    $e = escenarioPedido();
    $queso = productoAlPeso($e);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, [
        'products' => [porCantidad($queso, 1.0)],
    ]))->assertSuccessful();

    $orden = OrdersSales::latest('orderSales_id')->first();
    $linea = OrdersSalesDetail::where('orderSales_id', $orden->orderSales_id)->first();

    tenderoDe($e);

    $cuerpo = [['orderDet_id' => $linea->orderDet_id, 'qty_final' => 1.05]];

    pesar($e, $orden->orderSales_id, $cuerpo)->assertOk();
    pesar($e, $orden->orderSales_id, $cuerpo)->assertStatus(409);

    $stock = (float) DB::table('products_business')
        ->where('busines_id', $e['businessId'])
        ->where('products_id', $queso)
        ->value('stock_weight');

    // 10 − 1 − 0,05, y no 10 − 1 − 0,05 − 0,05.
    expect(round($stock, 3))->toBe(8.95);
});

test('una app vieja que no sabe de pesos recibe un 422 que se entiende', function () {
    /*
     * La app publicada en las tiendas manda `{product_id, amount}` y nada
     * más. Inventarle un peso sería cobrarle al cliente algo que no pidió.
     */
    $e = escenarioPedido();
    $queso = productoAlPeso($e);

    $r = $this->postJson('/v1/orders/orders', cuerpoPedido($e, [
        'products' => [['product_id' => $queso, 'amount' => 1]],
    ]));

    $r->assertStatus(422);

    expect($r->json('message'))->toContain('Actualiza la app')
        ->and($r->json('product_ids'))->toBe([$queso])
        ->and(OrdersSales::count())->toBe(0);
});

test('nadie pesa el pedido de otra tienda', function () {
    $e = escenarioPedido();
    $queso = productoAlPeso($e);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, [
        'products' => [porCantidad($queso, 1.0)],
    ]))->assertSuccessful();

    $orden = OrdersSales::latest('orderSales_id')->first();
    $linea = OrdersSalesDetail::where('orderSales_id', $orden->orderSales_id)->first();

    // Otro tendero, de otra tienda.
    $otra = ['businessId' => DB::table('business')->insertGetId([
        'name' => 'La de Enfrente', 'qualification' => 0, 'state' => 1,
    ])];
    tenderoDe($otra);

    /*
     * 404 y no 403: responder «prohibido» sobre el número de otro confirmaría
     * que ese pedido existe, y probando números se sabría cuántos vende el
     * vecino.
     */
    pesar($otra, $orden->orderSales_id, [
        ['orderDet_id' => $linea->orderDet_id, 'qty_final' => 1.0],
    ])->assertStatus(404);
});

test('la cotización y el pedido calculan el peso igual', function () {
    /*
     * Si no coincidieran, el carrito enseñaría un total y `store` lo tumbaría
     * con el 409 de «el total cambió». Esa comprobación existe para proteger
     * al cliente, no para pelearse con la pantalla.
     */
    $e = escenarioPedido();
    $queso = productoAlPeso($e);

    $cotizacion = $this->postJson('/v1/orders/cotizar', [
        'busines_id' => $e['businessId'],
        'address_id' => $e['addressId'],
        'products'   => [porPlata($queso, 3000)],
    ])->assertOk();

    expect((float) $cotizacion->json('subtotal'))->toBe(3000.0);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, [
        'products'       => [porPlata($queso, 3000)],
        'expected_total' => $cotizacion->json('total'),
    ]))->assertSuccessful();
});
