<?php

use App\Models\Order\OrdersSales;
use App\Models\Promotion;
use App\Services\RedencionDePromociones;
use Illuminate\Support\Facades\DB;

/**
 * EL TOPE DE USOS DE UNA PROMOCIÓN — Y LA REDENCIÓN QUE NO EXISTÍA.
 *
 * El tendero quiere poder decir «los primeros 50». Para contar usos hubo que
 * construir antes algo que faltaba entero: la tabla `orders_promotions` existe
 * desde 2025, con su índice único y sus relaciones declaradas en los dos
 * modelos, y NUNCA se había escrito en ella. El descuento se calculaba, se
 * aplicaba y se cobraba sin dejar rastro de qué promoción lo había causado, y
 * `OrdersSales::toApi()` devolvía `'promotions' => []` para todos los pedidos
 * de la historia.
 *
 * Lo que se fija acá es, por ese orden: que la constancia se escriba, que el
 * uso se gaste UNA sola vez por pedido —aunque el cliente reintente el pago—,
 * que una promoción agotada deje de rebajar, que dos pedidos simultáneos no
 * puedan pasarse del tope, y que cancelar devuelva el uso al cupo.
 */

/** Una promoción de la tienda que rebaja la mitad. Sin productos: alcanza a todo. */
function promocionDeLaTienda(int $businessId, array $extra = []): Promotion
{
    return Promotion::create(array_merge([
        'busines_id'          => $businessId,
        'description'         => 'Mitad de precio en todo',
        'tipo'                => Promotion::PORCENTAJE,
        'percentage_discount' => 0.5,
        'state'               => Promotion::ENVIADA,
        'start_date'          => now()->subDay(),
    ], $extra));
}

/* ---------------------------------------------------------------------- */

test('la promoción deja constancia del pedido y gasta un uso', function () {
    $e = escenarioPedido();
    $promo = promocionDeLaTienda($e['businessId'], ['max_usos' => 5]);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e))->assertSuccessful();

    $orden = DB::table('orderssales')->latest('orderSales_id')->first();

    // 3 x 10.000, la mitad.
    expect((float) $orden->discount)->toBe(15000.0);

    // La constancia, que es lo que no existía.
    $constancia = DB::table('orders_promotions')->first();

    expect($constancia)->not->toBeNull()
        ->and((int) $constancia->promotion_id)->toBe((int) $promo->promotion_id)
        ->and((int) $constancia->orderSales_id)->toBe((int) $orden->orderSales_id)
        ->and((int) $constancia->state)->toBe(RedencionDePromociones::APLICADA);

    expect((int) $promo->fresh()->usos)->toBe(1);
});

test('sin tope, la promoción cuenta usos pero no se agota nunca', function () {
    $e = escenarioPedido();
    $promo = promocionDeLaTienda($e['businessId']);   // max_usos queda en null

    $this->postJson('/v1/orders/orders', cuerpoPedido($e))->assertSuccessful();

    expect((int) $promo->fresh()->usos)->toBe(1)
        ->and($promo->fresh()->agotada())->toBeFalse();
});

test('una promoción agotada ya no rebaja nada', function () {
    /*
     * El filtro vive en `Promotion::vigente()`, que es el único predicado de
     * «se puede aplicar hoy» y lo usan los dos sitios que deciden: el carrito
     * y la vitrina. Por eso no hace falta tocar ninguno de los dos.
     */
    $e = escenarioPedido();
    promocionDeLaTienda($e['businessId'], ['max_usos' => 1, 'usos' => 1]);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e))->assertSuccessful();

    $orden = DB::table('orderssales')->latest('orderSales_id')->first();

    expect((float) $orden->discount)->toBe(0.0)
        ->and((float) $orden->total)->toBe(30000.0 + (float) $orden->domicilio)
        ->and(DB::table('orders_promotions')->count())->toBe(0);
});

test('una promoción programada para mañana todavía no rebaja', function () {
    /*
     * `start_date` estaba en la tabla desde el principio y NADIE lo miraba: se
     * escribía `now()` al publicar y ahí se quedaba. Leerlo es lo que permite
     * dejar armada el jueves la promoción del viernes.
     */
    $e = escenarioPedido();
    promocionDeLaTienda($e['businessId'], ['start_date' => now()->addDay()]);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e))->assertSuccessful();

    expect((float) DB::table('orderssales')->latest('orderSales_id')->first()->discount)->toBe(0.0);
});

test('el mismo pedido no gasta dos usos aunque se reintente el pago', function () {
    /*
     * Un reintento NO crea otro pedido: `ArmadoDelPedido` reutiliza el que
     * quedó a medias y vuelve a pasar por la redención. Sin el índice único de
     * `(orderSales_id, promotion_id)`, el mismo pedido gastaría un uso cada vez
     * que el cliente le da a pagar.
     */
    $e = escenarioPedido();
    $promo = promocionDeLaTienda($e['businessId'], ['max_usos' => 5]);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e))->assertSuccessful();

    $orden = OrdersSales::latest('orderSales_id')->first();
    $aplicadas = [['promotion_id' => (int) $promo->promotion_id, 'texto' => 'Mitad de precio en todo']];

    app(RedencionDePromociones::class)->canjear($orden, $aplicadas);
    app(RedencionDePromociones::class)->canjear($orden, $aplicadas);

    expect((int) $promo->fresh()->usos)->toBe(1)
        ->and(DB::table('orders_promotions')->count())->toBe(1);
});

test('cuando dos pedidos se pelean el último uso, solo uno lo gasta', function () {
    /*
     * El caso por el que el contador se incrementa con un UPDATE condicional
     * en vez de confiar en el filtro previo: entre que se calcula el carrito y
     * se guarda el pedido pasa tiempo —el cliente mira, duda, paga— y con una
     * promoción que funciona hay varias personas gastando el último uso a la
     * vez.
     *
     * Acá se provoca a propósito: el segundo pedido llega a la redención con
     * la promoción ya agotada, que es exactamente lo que vería el que llegó
     * tarde.
     */
    $e = escenarioPedido();
    $promo = promocionDeLaTienda($e['businessId'], ['max_usos' => 1, 'usos' => 1]);

    $otro = OrdersSales::create([
        'buyer_id'   => $e['buyerId'],
        'busines_id' => $e['businessId'],
        'methods_id' => $e['methodId'],
        'total'      => 30000,
        'sale_date'  => now(),
        'state'      => 1,
    ]);

    expect(fn () => app(RedencionDePromociones::class)->canjear($otro, [
        ['promotion_id' => (int) $promo->promotion_id, 'texto' => 'Mitad de precio en todo'],
    ]))->toThrow(RuntimeException::class, 'se acaba de agotar');

    expect((int) $promo->fresh()->usos)->toBe(1);
});

test('al cancelar el pedido, el uso vuelve al cupo', function () {
    /*
     * Una promoción de «los primeros 50» no puede gastarse en pedidos que
     * acabaron no existiendo: el tendero prometió cincuenta ventas rebajadas,
     * no cincuenta intentos.
     */
    $e = escenarioPedido();
    $promo = promocionDeLaTienda($e['businessId'], ['max_usos' => 3]);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e))->assertSuccessful();
    $orden = DB::table('orderssales')->latest('orderSales_id')->first();

    expect((int) $promo->fresh()->usos)->toBe(1);

    $this->putJson("/v1/orders/{$orden->orderSales_id}/cancel")->assertSuccessful();

    expect((int) $promo->fresh()->usos)->toBe(0);

    // La constancia NO se borra, se marca: saber que ese pedido llevó esa
    // promoción sigue explicando por qué se cobró lo que se cobró.
    $constancia = DB::table('orders_promotions')->first();

    expect($constancia)->not->toBeNull()
        ->and((int) $constancia->state)->toBe(RedencionDePromociones::DEVUELTA);
});

test('cancelar dos veces no descuenta el uso dos veces', function () {
    $e = escenarioPedido();
    $promo = promocionDeLaTienda($e['businessId'], ['max_usos' => 3, 'usos' => 0]);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e))->assertSuccessful();
    $orden = OrdersSales::latest('orderSales_id')->first();

    app(RedencionDePromociones::class)->devolver($orden);
    app(RedencionDePromociones::class)->devolver($orden);

    expect((int) $promo->fresh()->usos)->toBe(0);
});
