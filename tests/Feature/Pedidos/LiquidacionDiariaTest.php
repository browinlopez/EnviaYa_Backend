<?php

use App\Models\Operacion\Settlement;
use App\Models\Rol;
use App\Models\User;
use App\Services\LiquidacionService;
use Illuminate\Support\Facades\DB;

/**
 * El corte diario.
 *
 * Tres cosas cambian de golpe respecto a lo que había:
 *
 *  1. La plataforma retiene comisión al negocio. Antes `platform_fee` salía
 *     como residuo de una resta y para un negocio daba SIEMPRE 0.
 *  2. Al domiciliario se le descuenta el efectivo que recaudó, así que su
 *     saldo puede quedar en rojo. Antes solo se le sumaba.
 *  3. Un pedido con el pago rechazado deja de entrar: se le estaba
 *     transfiriendo al negocio dinero que nadie llegó a cobrar.
 */

function pedidoLiquidable(array $datos = []): array
{
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $repartidor = User::factory()->create(['rol' => 3]);
    $domiId = DB::table('domiciliary')->insertGetId([
        'user_id' => $repartidor->user_id, 'available' => 1,
        'qualification' => 0, 'state' => 1,
    ], 'domiciliary_id');

    $comprador = User::factory()->create(['rol' => 1]);
    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $comprador->user_id, 'qualification' => 0,
        'belongs_to_complex' => 0, 'state' => 1,
    ]);

    $businessId = DB::table('business')->insertGetId([
        'name' => 'Tienda', 'qualification' => 0, 'state' => 1,
    ]);

    $orderId = DB::table('orderssales')->insertGetId(array_merge([
        'buyer_id' => $buyerId, 'busines_id' => $businessId,
        'domiciliary_id' => $domiId,
        'subtotal' => 30000, 'domicilio' => 2000, 'total' => 32000,
        'discount' => 0, 'domiciliary_fee' => 500,
        'platform_fee' => 900, 'cash_due' => 0,
        'sale_date' => now(), 'state' => 4, 'payment_state' => 'paid',
    ], $datos), 'orderSales_id');

    return compact('businessId', 'domiId', 'orderId');
}

/* ---------------------------------------------------------------------- */

test('al negocio se le retiene la comision congelada', function () {
    $e = pedidoLiquidable();

    $liq = app(LiquidacionService::class)
        ->generar('business', $e['businessId'], now()->toDateString(), now()->toDateString());

    // 30.000 de venta, 900 de comisión, 29.100 a transferir. Antes esto daba
    // 30.000 y la plataforma no cobraba nada.
    expect((float) $liq->gross)->toBe(30000.0)
        ->and((float) $liq->platform_fee)->toBe(900.0)
        ->and((float) $liq->net_payable)->toBe(29100.0);
});

test('un descuento de cupon se resta antes de la comision', function () {
    $e = pedidoLiquidable(['discount' => 10000, 'platform_fee' => 600, 'total' => 22000]);

    $liq = app(LiquidacionService::class)
        ->generar('business', $e['businessId'], now()->toDateString(), now()->toDateString());

    // 30.000 − 10.000 de cupón − 600 de comisión.
    expect((float) $liq->net_payable)->toBe(19400.0);
});

test('el domiciliario que recaudo efectivo queda debiendo', function () {
    // Lo que cambia el sentido del corte: cobra 32.000 y gana 500.
    $e = pedidoLiquidable(['cash_due' => 32000, 'methods_id' => null]);

    $liq = app(LiquidacionService::class)
        ->generar('domiciliary', $e['domiId'], now()->toDateString(), now()->toDateString());

    expect((float) $liq->net_payable)->toBe(-31500.0);
});

test('sin efectivo de por medio el domiciliario cobra su comision', function () {
    $e = pedidoLiquidable(['cash_due' => 0]);

    $liq = app(LiquidacionService::class)
        ->generar('domiciliary', $e['domiId'], now()->toDateString(), now()->toDateString());

    expect((float) $liq->net_payable)->toBe(500.0);
});

test('un pedido con el pago rechazado no entra al corte', function () {
    // Se le estaba transfiriendo al negocio dinero que nadie cobró.
    pedidoLiquidable(['payment_state' => 'rejected']);
    $e = pedidoLiquidable(['payment_state' => 'rejected']);

    expect(fn () => app(LiquidacionService::class)
        ->generar('business', $e['businessId'], now()->toDateString(), now()->toDateString()))
        ->toThrow(RuntimeException::class);
});

test('un pedido esperando pago en linea tampoco', function () {
    $e = pedidoLiquidable(['payment_state' => 'pending_online']);

    expect(fn () => app(LiquidacionService::class)
        ->generar('business', $e['businessId'], now()->toDateString(), now()->toDateString()))
        ->toThrow(RuntimeException::class);
});

test('el comando diario genera borradores para los dos lados', function () {
    pedidoLiquidable(['sale_date' => now()->subDay()]);

    $this->artisan('liquidaciones:diarias')->assertSuccessful();

    expect(Settlement::where('type', 'business')->count())->toBe(1)
        ->and(Settlement::where('type', 'domiciliary')->count())->toBe(1)
        // En borrador: un error de cálculo aprobado solo se descubre cuando el
        // dinero ya salió.
        ->and(Settlement::first()->state)->toBe(Settlement::BORRADOR);
});

test('correr el comando dos veces no duplica el corte', function () {
    pedidoLiquidable(['sale_date' => now()->subDay()]);

    $this->artisan('liquidaciones:diarias')->assertSuccessful();
    $this->artisan('liquidaciones:diarias')->assertSuccessful();

    expect(Settlement::count())->toBe(2); // uno por lado, no cuatro
});

test('un dia sin pedidos no es un fallo', function () {
    $this->artisan('liquidaciones:diarias')->assertSuccessful();

    expect(Settlement::count())->toBe(0);
});
