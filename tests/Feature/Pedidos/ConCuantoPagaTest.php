<?php

use App\Models\Order\OrdersSales;
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
        'name'       => 'Pago móvil',
        'state'      => 1,
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
        'methods_id'    => metodoQueNoEsEfectivo(),
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
    $ficha = app(\App\Services\PedidoParaLaApp::class)->formatear($orden);

    expect($ficha)->toHaveKeys(['cash_tendered', 'change_due'])
        ->and((float) $ficha['cash_tendered'])->toBe(50000.0)
        ->and($ficha['change_due'])->toBe(round(50000 - (float) $orden->total, 2));
});
