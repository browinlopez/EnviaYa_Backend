<?php

use App\Models\Order\OrdersSales;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * EL CLIENTE NO PONE EL PRECIO, NI EL ESTADO, NI SI YA PAGÓ
 *
 * Es la invariante más importante de este backend y **no estaba fijada por
 * ninguna prueba**. Se cumple —`ArmadoDelPedido` construye el pedido con
 * cifras que calcula `EconomiaDelPedido`, y `state` y `payment_state` los
 * deriva del método de pago— pero lo único que lo sostiene es que ese archivo
 * no pase el cuerpo de la petición entero.
 *
 * Y el modelo no ayuda: `payment_state` está en `$fillable` a propósito,
 * porque el webhook de la pasarela lo necesita para marcar el cobro. O sea
 * que un `OrdersSales::create($request->validated())` en cualquier refactor
 * dejaría a cualquiera con un pedido gratis, y hasta hoy ninguna prueba se
 * habría puesto roja.
 *
 * Lo que se intenta aquí es exactamente lo que intentaría alguien con la
 * aplicación abierta y el tráfico a la vista: mandar en el cuerpo los campos
 * que deciden dinero y ver si el servidor se los cree.
 */
function intentarInyectar(array $extra): array
{
    $e = escenarioPedido();

    $precio = (float) DB::table('products_business')
        ->where('busines_id', $e['businessId'])
        ->where('products_id', $e['productId'])
        ->value('price');

    $r = test()->postJson('/v1/orders/orders', cuerpoPedido($e, $extra))
        ->assertCreated();

    $fila = DB::table('orderssales')
        ->where('orderSales_id', $r->json('order.order_id'))
        ->first();

    return ['fila' => $fila, 'precio' => $precio, 'respuesta' => $r];
}

it('mandar el total en el cuerpo no cambia el total', function () {
    $x = intentarInyectar([
        'total' => 1,
        'subtotal' => 1,
        'domicilio' => 0,
        'discount' => 99999,
    ]);

    /*
     * Tres unidades al precio del servidor más el domicilio. No se afirma un
     * número redondo: se afirma que el total SALE DEL PRECIO, que es la
     * propiedad.
     */
    expect((float) $x['fila']->subtotal)->toBe($x['precio'] * 3);
    expect((float) $x['fila']->total)->toBeGreaterThan($x['precio'] * 3 - 0.01);
    expect((float) $x['fila']->total)->not->toBe(1.0);
    expect((float) $x['fila']->discount)->toBe(0.0);
});

it('mandar payment_state = paid no deja un pedido pagado sin pagar', function () {
    /*
     * EL CAMPO MÁS PELIGROSO DE TODOS, y el que el modelo deja `fillable`
     * porque lo necesita el webhook. Un pedido con `payment_state = paid` es
     * un pedido que la tienda prepara y nadie cobró.
     */
    $x = intentarInyectar(['payment_state' => 'paid']);

    // Efectivo: nace esperando el cobro contra entrega, no pagado.
    expect($x['fila']->payment_state)->toBe('pending_cash');
    expect($x['fila']->payment_state)->not->toBe('paid');
});

it('mandar el estado no salta la cola del tablero', function () {
    /*
     * `state = 4` es «entregado». Si se pudiera poner al crear, el pedido
     * nacería cerrado: la tienda no lo vería nunca y el comprobante se
     * emitiría por algo que nadie preparó.
     */
    $x = intentarInyectar(['state' => 4]);

    expect((int) $x['fila']->state)->toBe(1);
});

it('mandar la comision o la tarifa del domiciliario no las cambia', function () {
    $x = intentarInyectar([
        'domiciliary_fee' => 0,
        'platform_fee' => 0,
        'delivery_subsidy' => 99999,
    ]);

    /*
     * No se afirma un valor: se afirma que NO es el que mandó el cliente. La
     * comisión sale de los ajustes de la plataforma y la rebaja del domicilio
     * de las reglas del negocio; los dos pueden cambiar sin tocar esto.
     */
    expect((float) $x['fila']->delivery_subsidy)->not->toBe(99999.0);

    $suma = (float) $x['fila']->domiciliary_fee + (float) $x['fila']->platform_fee;
    expect($suma)->toBeGreaterThan(0.0);
});

it('mandar un domiciliario no se lo asigna', function () {
    /*
     * Asignarse un repartidor al crear el pedido sería saltarse el reparto
     * entero: el pedido entraría ya «tomado» por alguien que la tienda no
     * eligió.
     */
    /*
     * SE CREA EL DOMICILIARIO AQUÍ, y eso costó una vuelta: la primera versión
     * usaba `DB::table('domiciliary')->value(...)` y en este escenario no hay
     * ninguno, así que inyectaba `null` y el `toBeNull()` de abajo pasaba sin
     * haber intentado nada. Probada contra el refactor peligroso, era la única
     * de las críticas que NO se ponía roja.
     */
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);
    $repartidor = User::factory()->create(['rol' => 3]);
    $domi = DB::table('domiciliary')->insertGetId([
        'user_id' => $repartidor->user_id, 'available' => 1,
        'qualification' => 0, 'state' => 1,
    ], 'domiciliary_id');

    expect($domi)->toBeGreaterThan(0);

    $x = intentarInyectar(['domiciliary_id' => $domi]);

    expect($x['fila']->domiciliary_id)->toBeNull();
});

it('mandar un cupon por su id no aplica el descuento', function () {
    /*
     * El descuento se gana escribiendo el CÓDIGO, que es lo que se valida y
     * lo que gasta un uso. Mandar el `coupon_id` a secas sería cobrarlo sin
     * pasar por ahí.
     */
    $cupon = DB::table('coupons')->insertGetId([
        'code' => 'INYECTADO', 'type' => 'percent', 'value' => 50,
        'uses_count' => 0, 'state' => 1,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $x = intentarInyectar(['coupon_id' => $cupon]);

    expect($x['fila']->coupon_id)->toBeNull();
    expect((float) $x['fila']->discount)->toBe(0.0);
    expect((int) DB::table('coupons')->where('id', $cupon)->value('uses_count'))->toBe(0);
});

it('mandar stock_descontado no evita que baje el inventario', function () {
    /*
     * La marca que dice «a este pedido ya se le restó el inventario». Puesta
     * desde fuera, el pedido saldría sin descontar nada: la tienda vendería
     * lo que no tiene.
     */
    $e = escenarioPedido();

    $antes = (int) DB::table('products_business')
        ->where('busines_id', $e['businessId'])
        ->where('products_id', $e['productId'])
        ->value('amount');

    test()->postJson('/v1/orders/orders', cuerpoPedido($e, ['stock_descontado' => true]))
        ->assertCreated();

    $despues = (int) DB::table('products_business')
        ->where('busines_id', $e['businessId'])
        ->where('products_id', $e['productId'])
        ->value('amount');

    expect($despues)->toBe($antes - 3);
});

it('el pedido se crea a nombre de quien tiene la sesion, no del buyer_id que manden', function () {
    /*
     * El `user_id` del cuerpo lo valida la petición, pero el comprador sale de
     * la sesión. Mandar el `buyer_id` de otra persona crearía un pedido a su
     * nombre —con su dirección y su historial—.
     */
    $e = escenarioPedido();

    $otro = DB::table('buyer')->insertGetId([
        'user_id' => User::factory()->create(['rol' => 1])->user_id,
        'qualification' => 0, 'state' => 1,
    ], 'buyer_id');

    $r = test()->postJson('/v1/orders/orders', cuerpoPedido($e, ['buyer_id' => $otro]))
        ->assertCreated();

    $fila = DB::table('orderssales')
        ->where('orderSales_id', $r->json('order.order_id'))->first();

    expect((int) $fila->buyer_id)->toBe($e['buyerId']);
    expect((int) $fila->buyer_id)->not->toBe((int) $otro);
});

it('el modelo deja payment_state fillable, y por eso hace falta todo lo de arriba', function () {
    /*
     * Esto no es una prueba de comportamiento: es la razón de que este archivo
     * exista, escrita como afirmación para que no se pierda.
     *
     * `payment_state` está en `$fillable` a propósito —el webhook de la
     * pasarela lo necesita para marcar el cobro—, así que el modelo NO protege
     * de una asignación masiva. Lo único que protege es que `ArmadoDelPedido`
     * construya el pedido campo por campo. El día que alguien escriba
     * `OrdersSales::create($request->validated())`, las pruebas de arriba son
     * lo que lo para.
     */
    $fillable = (new OrdersSales)->getFillable();

    expect($fillable)->toContain('payment_state');
    expect($fillable)->toContain('total');
    expect($fillable)->toContain('state');
    expect($fillable)->toContain('domiciliary_id');

    /*
     * `stock_descontado` es el ÚNICO de los peligrosos que el modelo sí para,
     * porque no está en la lista. Medido, y conviene saberlo: de los ocho
     * campos que estas pruebas intentan inyectar, siete dependen de que
     * `ArmadoDelPedido` construya el pedido campo por campo. Solo uno está
     * protegido por el modelo.
     */
    expect($fillable)->not->toContain('stock_descontado');
});
