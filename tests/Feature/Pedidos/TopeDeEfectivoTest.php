<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * CUÁNTO EFECTIVO PUEDE LLEVAR ENCIMA UN DOMICILIARIO.
 *
 * Desde que existe la custodia, su saldo crece con cada entrega contra entrega
 * y sólo baja cuando consigna y alguien se lo confirma. Nada lo detenía: al
 * final del día podía andar con un millón de pesos en el bolsillo, y eso es un
 * riesgo para él antes que para nadie.
 *
 * El tope lo pone el NEGOCIO que despacha, no la plataforma: el riesgo lo
 * asume quien le entrega el pedido, y una droguería de barrio no tiene el
 * mismo apetito que un supermercado con veinte repartidores.
 *
 * Se valida en el SERVIDOR porque por la transición 2 → 3 pasan los dos
 * caminos —el tendero despachando y el domiciliario tomando el pedido de su
 * lista—. En el cliente, cualquiera de los dos se la saltaría.
 */

/** Le pone efectivo encima sin tener que simular una entrega entera. */
function conEfectivoEncima(int $domiId, float $cuanto): void
{
    DB::table('cash_movements')->insert([
        'domiciliary_id' => $domiId,
        'type'       => 'ajuste',
        'amount'     => $cuanto,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function despacharPedido(array $e): \Illuminate\Testing\TestResponse
{
    Sanctum::actingAs($e['tendero']);

    return test()->putJson('/v1/orders/update', [
        'order_id' => $e['orderId'],
        'state'    => 3,
        'user_id'  => $e['repartidor']->user_id,
    ]);
}

function estadoDe(int $orderId): int
{
    return (int) DB::table('orderssales')->where('orderSales_id', $orderId)->value('state');
}

/* ---------------------------------------------------------------------- */

test('sin tope se despacha como siempre', function () {
    $e = escenarioDeTope(null);
    conEfectivoEncima($e['domiId'], 900000);

    /*
     * `null` es «sin tope», que es como funcionaba antes de esto. La columna
     * nace vacía a propósito: activarlo tiene que ser una decisión y no algo
     * que aparece solo tras la migración y deja pedidos sin poder despachar.
     */
    despacharPedido($e)->assertOk();

    expect(estadoDe($e['orderId']))->toBe(3);
});

test('con margen suficiente pasa', function () {
    $e = escenarioDeTope(100000);
    conEfectivoEncima($e['domiId'], 30000);

    // 30.000 encima + 60.000 del pedido = 90.000, por debajo de 100.000.
    despacharPedido($e)->assertOk();

    expect(estadoDe($e['orderId']))->toBe(3);
});

test('si el pedido lo pasara del tope, no se despacha', function () {
    $e = escenarioDeTope(50000);

    $r = despacharPedido($e)->assertStatus(422);

    /*
     * El motivo va aparte del mensaje: la app y el panel lo usan para
     * distinguir este rechazo del de «no disponible» y del de «cupo lleno»,
     * que se resuelven de formas distintas —consignar, llamarlo, esperar—.
     */
    expect($r->json('reason'))->toBe('cash_limit_reached')
        ->and((float) $r->json('cash_now'))->toBe(0.0)
        ->and((float) $r->json('cash_after'))->toBe(60000.0)
        ->and((float) $r->json('cash_limit'))->toBe(50000.0);

    // Y el pedido se queda donde estaba, sin domiciliario.
    expect(estadoDe($e['orderId']))->toBe(2);
    expect(DB::table('orderssales')->where('orderSales_id', $e['orderId'])->value('domiciliary_id'))
        ->toBeNull();
});

test('justo en el tope todavía cabe', function () {
    $e = escenarioDeTope(60000);

    /*
     * La comparación es `>`, no `>=`: quedar exactamente en el tope es
     * cumplirlo. Con `>=`, un tope de 60.000 rechazaría un pedido de 60.000 y
     * nadie entendería por qué.
     */
    despacharPedido($e)->assertOk();
});

test('un pedido ya pagado no cuenta contra el tope', function () {
    // Método 2: lo pagó por la app antes de que saliera.
    $e = escenarioDeTope(50000, metodo: 2, total: 900000);
    conEfectivoEncima($e['domiId'], 49000);

    /*
     * No le pone un peso más en el bolsillo. Bloquearlo por el saldo sería
     * castigarlo por deber un dinero de otros pedidos, y dejaría al negocio
     * sin poder despachar algo que ya cobró.
     */
    despacharPedido($e)->assertOk();
});

test('consignar le devuelve margen', function () {
    $e = escenarioDeTope(100000);
    conEfectivoEncima($e['domiId'], 45000);

    // 45.000 + 60.000 = 105.000: se pasa por cinco mil.
    despacharPedido($e)->assertStatus(422);

    // El equipo le confirma la consignación y el libro baja.
    conEfectivoEncima($e['domiId'], -45000);

    /*
     * El mismo pedido, ahora sí. Es el camino de salida del bloqueo: sin él,
     * el mensaje «tiene que consignar antes» sería mentira.
     */
    despacharPedido($e)->assertOk();
    expect(estadoDe($e['orderId']))->toBe(3);
});

test('el tope de un negocio no es el del otro', function () {
    $estricto = escenarioDeTope(20000);
    despacharPedido($estricto)->assertStatus(422);

    /*
     * El saldo del domiciliario es UNO —cobra para varias tiendas— pero el
     * tope lo pone cada negocio por separado.
     */
    $generoso = escenarioDeTope(500000);
    despacharPedido($generoso)->assertOk();
});

test('el tendero pone y quita el tope de su negocio', function () {
    $e = escenarioDeTope(null);

    Sanctum::actingAs($e['tendero']);

    test()->putJson('/v1/negocio/me', ['name' => 'Tienda', 'max_courier_cash' => 80000])->assertOk();

    expect((float) DB::table('business')->where('busines_id', $e['businessId'])->value('max_courier_cash'))
        ->toBe(80000.0);

    /*
     * Vacío vuelve a «sin tope». Cero NO es lo mismo: significa «no le
     * despaches ningún pedido contra entrega», que es otra decisión y tiene
     * que poder tomarse.
     */
    test()->putJson('/v1/negocio/me', ['name' => 'Tienda', 'max_courier_cash' => ''])->assertOk();

    expect(DB::table('business')->where('busines_id', $e['businessId'])->value('max_courier_cash'))
        ->toBeNull();
});

test('un tope en cero bloquea todo el efectivo', function () {
    $e = escenarioDeTope(0);

    $r = despacharPedido($e)->assertStatus(422);
    expect($r->json('reason'))->toBe('cash_limit_reached');

    // Y sigue pudiendo despachar lo que ya está pagado: el cero es sobre el
    // efectivo, no sobre el domiciliario.
    $pagado = escenarioDeTope(0, metodo: 2);
    despacharPedido($pagado)->assertOk();
});

test('la lista del tendero dice cuánto lleva encima cada uno', function () {
    $e = escenarioDeTope(100000);
    conEfectivoEncima($e['domiId'], 35000);

    Sanctum::actingAs($e['tendero']);

    $r = test()->getJson('/v1/negocio/domiciliarios')->assertOk();

    /*
     * El tendero lo necesita ANTES de pulsar despachar. Sin el dato lo
     * intenta, recibe un 422 y no entiende por qué: la fila le decía que esa
     * persona estaba disponible.
     */
    expect((float) $r->json('domiciliaries.0.cash_on_hand'))->toBe(35000.0)
        ->and((float) $r->json('domiciliaries.0.cash_limit'))->toBe(100000.0)
        ->and((float) $r->json('domiciliaries.0.cash_room'))->toBe(65000.0);
});
