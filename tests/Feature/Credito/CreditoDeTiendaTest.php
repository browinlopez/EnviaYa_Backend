<?php

use App\Models\Operacion\Settlement;
use App\Models\Order\OrdersSales;
use App\Models\Rol;
use App\Models\User;
use App\Services\CobroContraEntrega;
use App\Services\CreditoDeTienda;
use App\Services\LiquidacionService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * EL CRÉDITO QUE LA TIENDA LE DA A SUS CLIENTES.
 *
 * Las reglas las decidió el negocio:
 *  · cupo ROTATIVO por cliente: se gasta comprando y se libera con abonos;
 *  · si el pedido cuesta más que lo disponible, se BLOQUEA;
 *  · solo a clientes afiliados;
 *  · la suma de cupos de una tienda no pasa su tope, y con una deuda arrastrada
 *    igual o mayor al tope la tienda no vende a crédito;
 *  · en la liquidación el total a crédito (productos + domicilio) se le
 *    descuenta a la tienda, y si el corte da negativo se ARRASTRA al siguiente.
 *
 * El ejemplo que dio el negocio es la prueba central: compra de 10.000 con
 * domicilio de 2.000 → 12.000 a crédito que se le descuentan a la tienda.
 */

/** Tienda con tendero, un producto y un comprador afiliado. */
function credEscenario(?float $tope = 100000, bool $afiliado = true): array
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);

    $tendero = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId(['user_id' => $tendero->user_id, 'state' => 1], 'owner_id');

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'Tienda del fiado', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
        'latitude' => 11.0, 'longitude' => -74.8, 'credit_debt_cap' => $tope,
    ], 'busines_id');
    DB::table('owner_busines')->insert(['owner_id' => $ownerId, 'busines_id' => $negocio, 'state' => 1]);

    $comprador = User::factory()->create(['rol' => 1]);
    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $comprador->user_id, 'qualification' => 0, 'belongs_to_complex' => 0, 'state' => 1,
    ], 'buyer_id');

    if ($afiliado) {
        DB::table('business_user_affiliations')->insert(['user_id' => $comprador->user_id, 'busines_id' => $negocio]);
    }

    $producto = DB::table('products')->insertGetId(['name' => 'Arroz', 'state' => 1], 'products_id');
    DB::table('products_business')->insert([
        'busines_id' => $negocio, 'products_id' => $producto, 'price' => 5000, 'amount' => 20,
    ]);

    $direccion = DB::table('user_address')->insertGetId([
        'user_id' => $comprador->user_id, 'address' => 'Calle 1',
        'latitude' => 11.001, 'longitude' => -74.801, 'state' => 1,
    ], 'address_id');

    DB::table('payment_methods')->insertOrIgnore([
        ['methods_id' => 1, 'name' => 'Efectivo', 'state' => 1],
        ['methods_id' => 7, 'name' => 'Crédito de la tienda', 'state' => 1],
    ]);

    return compact('tendero', 'negocio', 'comprador', 'buyerId', 'producto', 'direccion');
}

function credPedir(array $e, int $unidades, int $metodo = CreditoDeTienda::METODO)
{
    Sanctum::actingAs($e['comprador']);

    return test()->postJson('/v1/orders/orders', [
        'user_id'    => $e['comprador']->user_id,
        'busines_id' => $e['negocio'],
        'address_id' => $e['direccion'],
        'products'   => [['product_id' => $e['producto'], 'amount' => $unidades]],
        'methods_id' => $metodo,
    ]);
}

function credDisponible(array $e): float
{
    return app(CreditoDeTienda::class)->cupo($e['negocio'], (int) $e['comprador']->user_id)['disponible'];
}

/** Un pedido ya entregado, con las cifras exactas del ejemplo. */
function credEntregado(array $e, int $metodo, float $subtotal, float $domicilio, string $fecha): int
{
    return DB::table('orderssales')->insertGetId([
        'buyer_id' => $e['buyerId'], 'busines_id' => $e['negocio'], 'methods_id' => $metodo,
        'subtotal' => $subtotal, 'domicilio' => $domicilio, 'total' => $subtotal + $domicilio,
        'discount' => 0, 'platform_fee' => round($subtotal * 0.03, 2),
        'sale_date' => $fecha . ' 10:00:00', 'state' => 4, 'payment_state' => 'paid',
    ], 'orderSales_id');
}

/* ------------------------------------------------------------- asignar -- */

it('el tendero le da cupo a un cliente afiliado dentro de su tope', function () {
    $e = credEscenario(tope: 100000);
    Sanctum::actingAs($e['tendero']);

    $this->putJson("/v1/negocio/creditos/{$e['comprador']->user_id}", ['credit_limit' => 50000])
        ->assertOk()
        ->assertJsonPath('cupo.disponible', 50000)
        ->assertJsonPath('resumen.por_asignar', 50000);
});

it('no se le da crédito a quien no está afiliado', function () {
    $e = credEscenario(afiliado: false);
    Sanctum::actingAs($e['tendero']);

    $this->putJson("/v1/negocio/creditos/{$e['comprador']->user_id}", ['credit_limit' => 10000])
        ->assertStatus(422);

    expect(DB::table('store_credits')->count())->toBe(0);
});

it('la suma de cupos no pasa el tope de la tienda', function () {
    $e = credEscenario(tope: 60000);
    $otro = User::factory()->create(['rol' => 1]);
    DB::table('business_user_affiliations')->insert(['user_id' => $otro->user_id, 'busines_id' => $e['negocio']]);

    Sanctum::actingAs($e['tendero']);
    $this->putJson("/v1/negocio/creditos/{$e['comprador']->user_id}", ['credit_limit' => 40000])->assertOk();

    // 40.000 + 30.000 = 70.000 > 60.000: le quedan 20.000 para este.
    $this->putJson("/v1/negocio/creditos/{$otro->user_id}", ['credit_limit' => 30000])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'Tu tope para dar crédito es $60.000 y ya asignaste $40.000 a otros clientes: a este le puedes dar hasta $20.000.']);

    $this->putJson("/v1/negocio/creditos/{$otro->user_id}", ['credit_limit' => 20000])->assertOk();
});

it('sin tope la tienda no puede dar crédito', function () {
    $e = credEscenario(tope: null);
    Sanctum::actingAs($e['tendero']);

    $this->putJson("/v1/negocio/creditos/{$e['comprador']->user_id}", ['credit_limit' => 10000])
        ->assertStatus(422);
});

/* ------------------------------------------------------------- comprar -- */

it('comprar a crédito gasta el cupo y deja el pedido a crédito', function () {
    $e = credEscenario();
    app(CreditoDeTienda::class)->asignar($e['negocio'], (int) $e['comprador']->user_id, 50000);

    $r = credPedir($e, 2)->assertCreated();
    $pedido = OrdersSales::find($r->json('order.order_id') ?? $r->json('order.orderSales_id'));
    $total = (float) $pedido->total;

    expect($pedido->payment_state)->toBe(OrdersSales::A_CREDITO)
        ->and(credDisponible($e))->toBe(round(50000 - $total, 2));
});

it('si el pedido cuesta más que el crédito disponible, se bloquea entero', function () {
    $e = credEscenario();
    app(CreditoDeTienda::class)->asignar($e['negocio'], (int) $e['comprador']->user_id, 8000);

    // 2 × 5.000 = 10.000 más domicilio: no alcanza con 8.000.
    credPedir($e, 2)->assertStatus(422);

    expect(DB::table('orderssales')->count())->toBe(0)
        ->and((int) DB::table('products_business')->where('busines_id', $e['negocio'])->value('amount'))->toBe(20)
        ->and(credDisponible($e))->toBe(8000.0);
});

it('el mensaje dice cuánto crédito tiene y cuánto cuesta el pedido', function () {
    $e = credEscenario();
    app(CreditoDeTienda::class)->asignar($e['negocio'], (int) $e['comprador']->user_id, 8000);

    $mensaje = credPedir($e, 2)->assertStatus(422)->json('message');

    expect($mensaje)->toStartWith('Tu crédito disponible en esta tienda es $8.000 y el pedido cuesta $');
});

it('sin afiliación no se compra a crédito aunque tenga cupo', function () {
    $e = credEscenario();
    app(CreditoDeTienda::class)->asignar($e['negocio'], (int) $e['comprador']->user_id, 50000);
    DB::table('business_user_affiliations')->where('user_id', $e['comprador']->user_id)->delete();

    credPedir($e, 1)->assertStatus(422);
    expect(DB::table('orderssales')->count())->toBe(0);
});

it('cancelar un pedido a crédito devuelve el cupo', function () {
    $e = credEscenario();
    app(CreditoDeTienda::class)->asignar($e['negocio'], (int) $e['comprador']->user_id, 50000);

    $id = credPedir($e, 2)->assertCreated()->json('order.order_id');
    expect(credDisponible($e))->toBeLessThan(50000.0);

    $this->putJson("/v1/orders/{$id}/cancel")->assertOk();
    expect(credDisponible($e))->toBe(50000.0);

    // Cancelar dos veces no devuelve dos veces.
    app(CreditoDeTienda::class)->reversar(OrdersSales::find($id));
    expect(credDisponible($e))->toBe(50000.0);
});

it('el abono del cliente libera el cupo, pero no más de lo que debe', function () {
    $e = credEscenario();
    app(CreditoDeTienda::class)->asignar($e['negocio'], (int) $e['comprador']->user_id, 50000);
    credPedir($e, 2)->assertCreated();
    $usado = app(CreditoDeTienda::class)->cupo($e['negocio'], (int) $e['comprador']->user_id)['usado'];

    Sanctum::actingAs($e['tendero']);
    $this->postJson("/v1/negocio/creditos/{$e['comprador']->user_id}/abonos", ['amount' => $usado + 1])
        ->assertStatus(422);

    $this->postJson("/v1/negocio/creditos/{$e['comprador']->user_id}/abonos", ['amount' => $usado])
        ->assertCreated()
        ->assertJsonPath('cupo.disponible', 50000);
});

it('al entregar un pedido a crédito queda pagado y el domiciliario no debe nada', function () {
    $e = credEscenario();
    $id = credEntregado($e, CreditoDeTienda::METODO, 10000, 2000, now()->toDateString());
    DB::table('orderssales')->where('orderSales_id', $id)->update(['payment_state' => OrdersSales::A_CREDITO]);

    $pedido = OrdersSales::find($id);
    app(CobroContraEntrega::class)->registrar($pedido);

    expect($pedido->fresh()->payment_state)->toBe('paid')
        ->and((float) $pedido->fresh()->cash_due)->toBe(0.0)
        ->and(DB::table('cash_movements')->count())->toBe(0)
        // No es plata que entró a la caja de la plataforma.
        ->and(DB::table('payments')->where('orderSales_id', $id)->count())->toBe(0);
});

/* --------------------------------------------------------- liquidación -- */

it('EL EJEMPLO: 10.000 + 2.000 a crédito se le descuentan a la tienda', function () {
    $e = credEscenario();
    $hoy = now()->toDateString();

    credEntregado($e, 1, 20000, 2000, $hoy);                        // efectivo
    credEntregado($e, CreditoDeTienda::METODO, 10000, 2000, $hoy);  // crédito

    $liq = app(LiquidacionService::class)->generar('business', $e['negocio'], $hoy, $hoy);

    // 30.000 − 900 (3 %) − 12.000 (crédito) = 17.100
    expect((float) $liq->gross)->toBe(30000.0)
        ->and((float) $liq->platform_fee)->toBe(900.0)
        ->and((float) $liq->credit_sales)->toBe(12000.0)
        ->and((float) $liq->net_payable)->toBe(17100.0);
});

it('un día solo a crédito deja la tienda en rojo y se arrastra al corte siguiente', function () {
    $e = credEscenario();
    $ayer = now()->subDay()->toDateString();
    $hoy  = now()->toDateString();

    credEntregado($e, CreditoDeTienda::METODO, 10000, 2000, $ayer);
    $rojo = app(LiquidacionService::class)->generar('business', $e['negocio'], $ayer, $ayer);

    // 10.000 − 300 − 12.000 = −2.300: la tienda le debe a la plataforma.
    expect((float) $rojo->net_payable)->toBe(-2300.0)
        ->and(app(CreditoDeTienda::class)->deudaDeLaTienda($e['negocio']))->toBe(2300.0);

    credEntregado($e, 1, 20000, 2000, $hoy);
    $siguiente = app(LiquidacionService::class)->generar('business', $e['negocio'], $hoy, $hoy);

    // 20.000 − 600 = 19.400, menos los 2.300 arrastrados = 17.100.
    expect((float) $siguiente->carried_in)->toBe(-2300.0)
        ->and($siguiente->carried_from_id)->toBe($rojo->id)
        ->and((float) $siguiente->net_payable)->toBe(17100.0)
        ->and(app(CreditoDeTienda::class)->deudaDeLaTienda($e['negocio']))->toBe(0.0);
});

it('el rojo se arrastra UNA vez, y anular el corte que lo absorbió lo libera', function () {
    $e = credEscenario();
    $d1 = now()->subDays(2)->toDateString();
    $d2 = now()->subDay()->toDateString();
    $d3 = now()->toDateString();

    credEntregado($e, CreditoDeTienda::METODO, 10000, 2000, $d1);
    app(LiquidacionService::class)->generar('business', $e['negocio'], $d1, $d1);

    credEntregado($e, 1, 20000, 2000, $d2);
    $absorbe = app(LiquidacionService::class)->generar('business', $e['negocio'], $d2, $d2);

    credEntregado($e, 1, 10000, 2000, $d3);
    $tercero = app(LiquidacionService::class)->generar('business', $e['negocio'], $d3, $d3);
    expect((float) $tercero->carried_in)->toBe(0.0);

    // Anulado el que lo absorbió, la deuda vuelve a estar pendiente.
    $absorbe->update(['state' => Settlement::ANULADA]);
    expect(app(CreditoDeTienda::class)->deudaDeLaTienda($e['negocio']))->toBe(2300.0);
});

it('con la deuda arrastrada en el tope, la tienda deja de vender a crédito', function () {
    $e = credEscenario(tope: 2000);
    app(CreditoDeTienda::class)->asignar($e['negocio'], (int) $e['comprador']->user_id, 2000);

    $ayer = now()->subDay()->toDateString();
    credEntregado($e, CreditoDeTienda::METODO, 10000, 2000, $ayer);
    app(LiquidacionService::class)->generar('business', $e['negocio'], $ayer, $ayer); // −2.300 ≥ 2.000

    Sanctum::actingAs($e['comprador']);
    $this->getJson("/v1/creditos/tienda/{$e['negocio']}")
        ->assertOk()
        ->assertJsonPath('puede_usar', false)
        ->assertJsonPath('motivo', 'Por ahora esta tienda no está vendiendo a crédito.');
});

/* ------------------------------------------------------------- alcance -- */

it('un comprador no puede fijar cupos: las rutas son de la tienda', function () {
    $e = credEscenario();
    Sanctum::actingAs($e['comprador']);

    $this->putJson("/v1/negocio/creditos/{$e['comprador']->user_id}", ['credit_limit' => 999999])
        ->assertStatus(403);

    expect(DB::table('store_credits')->count())->toBe(0);
});

it('la ganancia del domiciliario cuenta también lo que entregó a crédito', function () {
    /*
     * La ganancia se sumaba desde `payments`, la caja de la plataforma, y un
     * pedido a crédito no deja fila ahí. Visto en el emulador: dos entregas
     * de $1.500 y la app decía $1.500.
     */
    $e = credEscenario();
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);
    $repartidor = User::factory()->create(['rol' => 3]);
    $domiId = DB::table('domiciliary')->insertGetId(['user_id' => $repartidor->user_id, 'available' => 1, 'qualification' => 0, 'state' => 1], 'domiciliary_id');

    foreach ([1, CreditoDeTienda::METODO] as $metodo) {
        $id = credEntregado($e, $metodo, 10000, 2000, now()->toDateString());
        DB::table('orderssales')->where('orderSales_id', $id)->update([
            'domiciliary_id' => $domiId, 'domiciliary_fee' => 1500, 'delivery_date' => now(),
        ]);
    }

    Sanctum::actingAs($repartidor);
    $r = $this->postJson('/v1/domiciliaries/incomeDomiciliary', ['domiciliary_id' => $domiId])->assertOk();

    expect((float) $r->json('total_income'))->toBe(3000.0)
        ->and((float) $r->json('weekly_current_total'))->toBe(3000.0);
});
