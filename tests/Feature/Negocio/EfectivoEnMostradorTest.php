<?php

use App\Models\Operacion\CashMovement;
use App\Models\Operacion\Settlement;
use App\Models\Rol;
use App\Models\User;
use App\Services\CustodiaDeEfectivo;
use App\Services\LiquidacionService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * EL EFECTIVO QUE EL DOMICILIARIO LE ENTREGA A LA TIENDA EN EL MOSTRADOR.
 *
 *  1. Baja lo que el domiciliario tiene encima, como una consignación.
 *  2. La tienda solo puede recibir lo que él cobró en pedidos SUYOS: la bolsa
 *     es una para todas las tiendas, y la plata de la vecina no es suya.
 *  3. El corte de la tienda lo descuenta UNA vez: ya tiene esa plata.
 */
function escenarioDeEfectivo(): array
{
    $t = tenderoConCatalogo();
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);
    $u = User::factory()->create(['rol' => 3]);
    $domi = DB::table('domiciliary')->insertGetId([
        'user_id' => $u->user_id, 'available' => 1, 'qualification' => 0, 'state' => 1,
    ], 'domiciliary_id');

    // Reparte para las dos tiendas.
    foreach ([$t['mio'], $t['vecino']] as $negocio) {
        DB::table('business_domiciliary')->insert(['busines_id' => $negocio, 'domiciliary_id' => $domi, 'state' => 1]);
    }

    return [...$t, 'domi' => $domi];
}

/** Un pedido entregado en efectivo, con su recaudo apuntado. */
function entregaEnEfectivo(int $negocio, int $domi, float $subtotal): int
{
    // El medio de pago «Efectivo»: la base de pruebas arranca vacía.
    if (!DB::table('payment_methods')->where('methods_id', 1)->exists()) {
        DB::table('payment_methods')->insert(['methods_id' => 1, 'name' => 'Efectivo', 'state' => 1]);
    }

    $id = DB::table('orderssales')->insertGetId([
        'busines_id' => $negocio, 'domiciliary_id' => $domi, 'state' => 4, 'methods_id' => 1,
        'subtotal' => $subtotal, 'domicilio' => 0, 'domiciliary_fee' => 0, 'discount' => 0,
        'total' => $subtotal, 'cash_due' => $subtotal, 'sale_date' => now()->subDay(),
    ], 'orderSales_id');

    CashMovement::create([
        'domiciliary_id' => $domi, 'type' => CashMovement::RECAUDO, 'amount' => $subtotal, 'order_id' => $id,
    ]);

    return $id;
}

test('la tienda recibe el efectivo de sus pedidos y baja lo que él tiene encima', function () {
    $e = escenarioDeEfectivo();
    entregaEnEfectivo($e['mio'], $e['domi'], 30000);
    entregaEnEfectivo($e['vecino'], $e['domi'], 50000);
    Sanctum::actingAs($e['user']);

    $suyo = collect($this->getJson('/v1/negocio/efectivo')->assertOk()->json('domiciliarios'))
        ->firstWhere('domiciliary_id', $e['domi']);
    expect($suyo['saldo'])->toBe(80000)->and($suyo['pendiente'])->toBe(30000);

    $this->postJson("/v1/negocio/efectivo/{$e['domi']}/entregas", ['amount' => 20000, 'notes' => 'Billetes'])
        ->assertCreated()
        ->assertJsonPath('pendiente', 10000)
        ->assertJsonPath('saldo', 60000);
});

test('no puede recibir la plata que él cobró para otra tienda', function () {
    $e = escenarioDeEfectivo();
    entregaEnEfectivo($e['mio'], $e['domi'], 30000);
    entregaEnEfectivo($e['vecino'], $e['domi'], 50000);
    Sanctum::actingAs($e['user']);

    // Tiene 80.000 encima, pero de esta tienda solo 30.000.
    $this->postJson("/v1/negocio/efectivo/{$e['domi']}/entregas", ['amount' => 40000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    expect(app(CustodiaDeEfectivo::class)->saldo($e['domi']))->toBe(80000.0);
});

test('un domiciliario que no es de la tienda no se puede tocar', function () {
    $e = escenarioDeEfectivo();
    DB::table('business_domiciliary')->where('busines_id', $e['mio'])->delete();
    entregaEnEfectivo($e['mio'], $e['domi'], 30000);
    Sanctum::actingAs($e['user']);

    $this->postJson("/v1/negocio/efectivo/{$e['domi']}/entregas", ['amount' => 1000])->assertNotFound();
});

test('el corte de la tienda descuenta lo recibido en el mostrador una sola vez', function () {
    $e = escenarioDeEfectivo();
    entregaEnEfectivo($e['mio'], $e['domi'], 30000);
    app(CustodiaDeEfectivo::class)->recibirEnMostrador($e['mio'], $e['domi'], 30000, $e['user']->user_id);

    $servicio = app(LiquidacionService::class);
    $desde = now()->subDays(5)->toDateString();
    $hasta = now()->toDateString();

    $corte = $servicio->generar('business', $e['mio'], $desde, $hasta);
    // Le tocaban 30.000 por la venta, pero ya los tiene en la mano.
    expect((float) $corte->counter_cash)->toBe(30000.0)
        ->and((float) $corte->net_payable)->toBe(0.0);

    // Anulado, la entrega queda libre para el corte siguiente.
    $corte->update(['state' => Settlement::ANULADA]);
    entregaEnEfectivo($e['mio'], $e['domi'], 10000);
    $otro = $servicio->generar('business', $e['mio'], $desde, $hasta);
    expect((float) $otro->counter_cash)->toBe(30000.0);

    // Y un tercer corte no la vuelve a descontar.
    entregaEnEfectivo($e['mio'], $e['domi'], 5000);
    $tercero = $servicio->generar('business', $e['mio'], $desde, $hasta);
    expect((float) $tercero->counter_cash)->toBe(0.0);
});

test('lo que ya consigno a la plataforma no le queda pendiente con la tienda', function () {
    $e = escenarioDeEfectivo();
    entregaEnEfectivo($e['mio'], $e['domi'], 50000);
    Sanctum::actingAs($e['user']);

    // Consignó todo a VeciPa'Ya: su bolsa queda en cero.
    $custodia = app(CustodiaDeEfectivo::class);
    $custodia->confirmarDeposito($custodia->declararDeposito($e['domi'], ['amount' => 50000]), $e['user']->user_id);

    // Antes la tienda seguía viendo 50.000 pendientes y se los reclamaba.
    $suyo = collect($this->getJson('/v1/negocio/efectivo')->assertOk()->json('domiciliarios'))
        ->firstWhere('domiciliary_id', $e['domi']);
    expect($suyo['pendiente'])->toEqual(0);

    // Y lo que cobre después para la vecina no se vuelve plata de esta tienda.
    $this->travel(5)->minutes();
    entregaEnEfectivo($e['vecino'], $e['domi'], 20000);
    $suyo = collect($this->getJson('/v1/negocio/efectivo')->json('domiciliarios'))
        ->firstWhere('domiciliary_id', $e['domi']);
    expect($suyo['pendiente'])->toEqual(0);
});
