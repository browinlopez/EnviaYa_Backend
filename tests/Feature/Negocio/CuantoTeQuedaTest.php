<?php

use App\Models\Negocio\GastoDelNegocio;
use App\Services\CuentaDelNegocio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
 * «CUÁNTO TE QUEDA»: ventas − costo de lo vendido − comisión − gastos.
 */
function tiendaConVentas(): array
{
    Carbon::setTestNow('2026-11-24 12:00:00');
    $e = escenarioDeTope(null);

    $arroz = DB::table('products')->insertGetId(['name' => 'Arroz', 'state' => 1], 'products_id');
    $pony = DB::table('products')->insertGetId(['name' => 'Pony', 'state' => 1], 'products_id');
    $sal = DB::table('products')->insertGetId(['name' => 'Sal', 'state' => 1], 'products_id');

    foreach ([[$arroz, 2600, 2000], [$pony, 3200, null], [$sal, 1000, null]] as [$id, $precio, $costo]) {
        DB::table('products_business')->insert([
            'busines_id' => $e['businessId'], 'products_id' => $id,
            'price' => $precio, 'amount' => 50, 'costo' => $costo,
        ]);
    }

    // Entregado: 2 arroz (costo congelado 1.800) y 3 pony (sin costo
    // congelado; hoy cuesta 2.500) y 1 sal sin ningún costo.
    DB::table('orderssales')->where('orderSales_id', $e['orderId'])->update([
        'state' => 4, 'delivery_date' => now(),
        'subtotal' => 15800, 'discount' => 800, 'platform_fee' => 450,
    ]);
    DB::table('orderssales_detail')->insert([
        ['orderSales_id' => $e['orderId'], 'product_id' => $arroz, 'amount' => 2, 'unit_price' => 2600, 'unit_cost' => 1800],
        ['orderSales_id' => $e['orderId'], 'product_id' => $pony, 'amount' => 3, 'unit_price' => 3200, 'unit_cost' => null],
        ['orderSales_id' => $e['orderId'], 'product_id' => $sal, 'amount' => 1, 'unit_price' => 1000, 'unit_cost' => null],
    ]);
    DB::table('products_business')->where('products_id', $pony)->update(['costo' => 2500]);

    return $e + compact('arroz', 'pony', 'sal');
}

afterEach(fn () => Carbon::setTestNow());

it('saca la cuenta del mes: ventas, costo, comisión, gastos y lo que queda', function () {
    $e = tiendaConVentas();
    GastoDelNegocio::create([
        'busines_id' => $e['businessId'], 'tipo' => 'luz', 'nombre' => 'Luz',
        'monto' => 3000, 'frecuencia' => 'mensual', 'desde' => '2026-11-01',
    ]);

    $c = app(CuentaDelNegocio::class)->para($e['businessId'], 'mes', now());

    expect($c['ventas'])->toBe(15000.0)                  // 15.800 − 800
        ->and($c['costo_de_lo_vendido'])->toBe(11100.0)  // 2×1.800 + 3×2.500
        ->and($c['margen'])->toBe(3900.0)
        ->and($c['comision'])->toBe(450.0)
        ->and($c['gastos'])->toBe(3000.0)
        ->and($c['queda'])->toBe(450.0)
        ->and($c['sin_costo']['lineas'])->toBe(1)
        ->and($c['sin_costo']['productos'][0]['nombre'])->toBe('Sal');
});

it('en una semana, el fijo del mes cuenta por los días que caen en ella', function () {
    $e = tiendaConVentas();
    GastoDelNegocio::create([
        'busines_id' => $e['businessId'], 'tipo' => 'arriendo', 'nombre' => 'Arriendo',
        'monto' => 300000, 'frecuencia' => 'mensual', 'desde' => '2026-11-01',
    ]);

    $c = app(CuentaDelNegocio::class)->para($e['businessId'], 'semana', now());

    // Semana del 23 al 29 de noviembre: 7 de 30 días.
    expect($c['gastos'])->toBe(70000.0);
});

it('el valor de un mes concreto manda sobre el de siempre', function () {
    $e = tiendaConVentas();
    $g = GastoDelNegocio::create([
        'busines_id' => $e['businessId'], 'tipo' => 'luz', 'nombre' => 'Luz',
        'monto' => 80000, 'frecuencia' => 'mensual', 'desde' => '2026-11-01',
    ]);

    Sanctum::actingAs($e['tendero']);
    $this->putJson("/v1/negocio/gastos/{$g->gasto_id}", ['monto' => 95000, 'solo_este_mes' => true])
        ->assertOk()
        ->assertJsonPath('monto', 80000)
        ->assertJsonPath('monto_este_mes', 95000);

    $c = app(CuentaDelNegocio::class)->para($e['businessId'], 'mes', now());
    expect($c['gastos'])->toBe(95000.0);
});

it('los costos se listan con los más vendidos primero y se guardan en lote', function () {
    $e = tiendaConVentas();
    Sanctum::actingAs($e['tendero']);

    $r = $this->getJson('/v1/negocio/costos')->assertOk();
    expect($r->json('productos.0.nombre'))->toBe('Arroz')
        ->and($r->json('sin_costo'))->toBe(1);

    $this->putJson('/v1/negocio/costos', ['costos' => [['products_id' => $e['sal'], 'costo' => 600]]])
        ->assertOk();
    expect((float) DB::table('products_business')->where('products_id', $e['sal'])->value('costo'))->toBe(600.0);
});

it('un fijo de meses atrás no se borra al quitarlo: deja de contar desde este mes', function () {
    $e = tiendaConVentas();
    $g = GastoDelNegocio::create([
        'busines_id' => $e['businessId'], 'tipo' => 'internet', 'nombre' => 'Internet',
        'monto' => 60000, 'frecuencia' => 'mensual', 'desde' => '2026-09-01',
    ]);

    Sanctum::actingAs($e['tendero']);
    $this->deleteJson("/v1/negocio/gastos/{$g->gasto_id}")->assertOk();

    expect(GastoDelNegocio::find($g->gasto_id)->hasta->toDateString())->toBe('2026-10-31');
    $octubre = app(CuentaDelNegocio::class)->para($e['businessId'], 'mes', Carbon::parse('2026-10-15'));
    expect($octubre['gastos'])->toBe(60000.0);
});

it('un gasto nuevo se crea con el nombre del tipo si no se le da uno', function () {
    $e = tiendaConVentas();
    Sanctum::actingAs($e['tendero']);

    $this->postJson('/v1/negocio/gastos', ['tipo' => 'gas', 'monto' => 45000, 'frecuencia' => 'mensual'])
        ->assertCreated()
        ->assertJsonPath('nombre', 'Gas');
});
