<?php

use App\Http\Resources\Pedido\PedidoDeLaAppResource;
use App\Models\Order\OrdersSales;
use App\Services\CreditoDeTienda;
use App\Services\PagoEnLinea;
use Illuminate\Support\Facades\DB;

/**
 * CON CUÁNTO VA A PAGAR EL CLIENTE.
 *
 * Una compra de $12.000 pagada con un billete de $50.000 necesita $38.000 de
 * cambio, y eso hay que tenerlo ANTES de que el domiciliario salga. Sin el
 * dato, llega a la puerta, no tiene con qué devolver, y la venta se cae en el
 * último metro.
 *
 * Lo que se fija acá: que el dato solo tenga sentido en efectivo, que el
 * cambio se calcule y no se congele, que un cliente que se queda corto no
 * produzca un número negativo en la pantalla de nadie, y que el domiciliario
 * lo reciba.
 */

/** Un método de pago que NO es el efectivo (el 1). */
function metodoQueNoEsEfectivo(): int
{
    DB::table('payment_methods')->insert([
        'methods_id' => 6,
        'name' => 'Pago móvil',
        'state' => 1,
    ]);

    return 6;
}

/* ---------------------------------------------------------------------- */

test('se guarda con cuánto va a pagar cuando el pago es en efectivo', function () {
    $e = escenarioPedido();

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, ['cash_tendered' => 50000]))
        ->assertSuccessful();

    $orden = OrdersSales::latest('orderSales_id')->first();

    expect((float) $orden->cash_tendered)->toBe(50000.0);
});

test('el cambio se calcula contra el total y no se guarda congelado', function () {
    /*
     * El total puede moverse después de crear el pedido. Una cifra de cambio
     * guardada acabaría diciendo un número que ya no es verdad, que es peor
     * que no decir ninguno.
     */
    $e = escenarioPedido();

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, ['cash_tendered' => 50000]))
        ->assertSuccessful();

    $orden = OrdersSales::latest('orderSales_id')->first();

    expect($orden->change_due)->toBe(round(50000 - (float) $orden->total, 2));

    // Se mueve el total: el cambio lo sigue solo.
    $orden->total = 20000;
    $orden->save();

    expect($orden->fresh()->change_due)->toBe(30000.0);
});

test('si el cliente se queda corto, no se enseña un cambio negativo', function () {
    // «Lleva -$3.000 de cambio» no significa nada. Que no alcance se resuelve
    // en la puerta, no en un renglón.
    $e = escenarioPedido();

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, ['cash_tendered' => 1000]))
        ->assertSuccessful();

    $orden = OrdersSales::latest('orderSales_id')->first();

    expect($orden->change_due)->toBeNull()
        // Pero sí se guarda lo que dijo: el domiciliario tiene que poder
        // saber que avisó que llevaba poco.
        ->and((float) $orden->cash_tendered)->toBe(1000.0);
});

test('paga con lo justo se distingue de no haberlo dicho', function () {
    $e = escenarioPedido();

    $this->postJson('/v1/orders/orders', cuerpoPedido($e))->assertSuccessful();
    $sinDecir = OrdersSales::latest('orderSales_id')->first();

    expect($sinDecir->cash_tendered)->toBeNull()
        ->and($sinDecir->change_due)->toBeNull();

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, [
        'cash_tendered' => 30000 + 2000,   // subtotal + el domicilio de serie
    ]))->assertSuccessful();

    $exacto = OrdersSales::latest('orderSales_id')->first();

    // Los dos tienen `change_due` nulo, y por eso la pantalla mira el otro
    // campo para saber de cuál de los dos casos se trata.
    expect($exacto->cash_tendered)->not->toBeNull();
});

test('en un pago que no es efectivo, el dato no se guarda', function () {
    // No hay cambio que devolver en un pago que ya viene hecho. Guardarlo
    // sería dejar un dato que nadie puede usar y que confunde al leerlo.
    $e = escenarioPedido();

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, [
        'methods_id' => metodoQueNoEsEfectivo(),
        'cash_tendered' => 50000,
    ]))->assertSuccessful();

    expect(OrdersSales::latest('orderSales_id')->first()->cash_tendered)->toBeNull();
});

test('no mandarlo deja el pedido exactamente como antes', function () {
    // La red de seguridad: el campo es opcional y las versiones de la app que
    // no lo conocen tienen que seguir pidiendo igual.
    $e = escenarioPedido();

    $this->postJson('/v1/orders/orders', cuerpoPedido($e))->assertSuccessful();

    $orden = OrdersSales::latest('orderSales_id')->first();

    expect($orden->cash_tendered)->toBeNull()
        ->and((float) $orden->subtotal)->toBe(30000.0);
});

test('un número absurdo se rechaza antes de llegar a la base', function () {
    $e = escenarioPedido();

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, ['cash_tendered' => -5]))
        ->assertStatus(422);

    expect(OrdersSales::count())->toBe(0);
});

test('el domiciliario recibe con cuánto paga y cuánto cambio llevar', function () {
    $e = escenarioPedido();

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, ['cash_tendered' => 50000]))
        ->assertSuccessful();

    $orden = OrdersSales::latest('orderSales_id')->first();
    $ficha = (new PedidoDeLaAppResource($orden))->resolve();

    expect($ficha)->toHaveKeys(['cash_tendered', 'change_due'])
        ->and((float) $ficha['cash_tendered'])->toBe(50000.0)
        ->and($ficha['change_due'])->toBe(round(50000 - (float) $orden->total, 2));
});

/* ----------------------------- lo que el segundo barrido encontro -------- */

/**
 * EL CREDITO DE LA TIENDA NO ES UN PAGO CONTRA ENTREGA.
 *
 * Del barrido del 14 de noviembre de 2026: anadiendo el metodo 7 --el credito
 * de la tienda-- a `PagoEnLinea::CONTRA_ENTREGA` pasaban las 1365 pruebas.
 *
 * POR QUE NO LO VEIA NINGUNA: la prueba de «un pago que no es efectivo» usa
 * `metodoQueNoEsEfectivo()`, que inventa el metodo **6**. El 6 sigue quedando
 * fuera con el sabotaje puesto, asi que el caso del credito --el unico otro
 * medio que se paga en la puerta sin pasarela-- no lo pisaba nadie.
 *
 * EL DANO: `cash_tendered` es «con cuanto va a pagar el cliente», y de ahi
 * sale lo que el domiciliario ve en su pantalla: «paga con $50.000, lleva
 * $38.000 de cambio». En un pedido a credito no hay nada que cobrar --la
 * tienda le cobra al comprador por fuera-- asi que esa instruccion le diria al
 * domiciliario que recoja plata que no le corresponde.
 */
test('un pedido a credito de la tienda no guarda con cuanto paga', function () {
    $e = escenarioPedido();

    DB::table('payment_methods')->insertOrIgnore([
        'methods_id' => CreditoDeTienda::METODO,
        'name' => 'Crédito de la tienda',
        'state' => 1,
    ]);

    /*
     * EL CUPO SE MONTA DE VERDAD, y no es decoracion.
     *
     * La primera version de esta prueba dejaba el credito sin asignar y
     * envolvia la comprobacion en un `if ($orden && ...)`. El pedido se
     * rechazaba por cupo, el `if` no entraba y la prueba pasaba con DOS
     * aserciones --las del control-- sin haber mirado nunca un pedido a
     * credito. Una prueba que se salta su propia comprobacion cuando el
     * escenario no cuaja no es una prueba: es un hueco que se ve verde.
     */
    DB::table('business')->where('busines_id', $e['businessId'])
        ->update(['credit_debt_cap' => 100000]);
    DB::table('business_user_affiliations')->insert([
        'user_id' => $e['user']->user_id,
        'busines_id' => $e['businessId'],
    ]);
    app(CreditoDeTienda::class)
        ->asignar($e['businessId'], (int) $e['user']->user_id, 50000);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, [
        'methods_id' => CreditoDeTienda::METODO,
        'cash_tendered' => 50000,
    ]))->assertSuccessful();

    $orden = OrdersSales::latest('orderSales_id')->first();

    // Y que el pedido sea DE VERDAD a credito, no otro que ande por ahi.
    expect((int) $orden->methods_id)->toBe(CreditoDeTienda::METODO);
    expect($orden->cash_tendered)->toBeNull();

    /* CONTROL: que el campo SI se guarde cuando toca. Sin esto, «no se guarda»
       tambien seria cierto con un `cash_tendered` que nunca se guardara, y la
       prueba pasaria estando ciega. */
    $e2 = escenarioPedido();
    $this->postJson('/v1/orders/orders', cuerpoPedido($e2, [
        'cash_tendered' => 50000,
    ]))->assertSuccessful();

    expect((float) OrdersSales::latest('orderSales_id')->first()->cash_tendered)->toBe(50000.0);
});

test('solo el efectivo se cobra en la puerta', function () {
    /*
     * CON LITERALES. De estas listas sale como se cobra cada pedido, asi que
     * meter un medio en la lista equivocada es cobrar mal.
     */
    expect(PagoEnLinea::CONTRA_ENTREGA)->toBe([1]);
    expect(PagoEnLinea::CON_PASARELA)->toBe([2, 4, 5]);

    // Y las tres listas no se solapan: un medio se cobra de UNA manera.
    $contra = PagoEnLinea::CONTRA_ENTREGA;
    $pasarela = PagoEnLinea::CON_PASARELA;
    $credito = PagoEnLinea::CREDITO_DE_TIENDA;

    expect(array_intersect($contra, $pasarela))->toBe([]);
    expect(array_intersect($contra, $credito))->toBe([]);
    expect(array_intersect($pasarela, $credito))->toBe([]);
});
