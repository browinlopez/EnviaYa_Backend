<?php

use App\Models\Conjunto\ComplexStaff;
use App\Models\Order\OrdersSales;
use App\Models\User;
use App\Queries\VentasDelNegocio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * TUS VENTAS POR NEGOCIO: cada negocio del dueño, con la MISMA venta que
 * Ingresos, la suma de todos, y nunca un negocio de otro dueño.
 *
 * El 26 de noviembre a mediodía el dueño tiene:
 *
 *   Tienda      p1 entregado hoy: subtotal 20.000 − promo 2.000 (+ domicilio)
 *               p2 en camino (todavía no es venta)
 *               m1 mostrador cobrado 7.000; m2 QR esperando 9.000 (no es venta)
 *   Droguería   p3 entregado hoy: subtotal 15.000
 *               p4 entregado el 1 de noviembre: 5.000 (otro periodo)
 *
 * Y un vecino, dueño de OTRA tienda, vendió 99.000 hoy: no aparece.
 */
function dosNegociosDelMismoDueno(): array
{
    Carbon::setTestNow('2026-11-26 12:00:00');
    $e = escenarioDeTope(null);
    DB::table('orderssales')->where('orderSales_id', $e['orderId'])->delete();
    $buyer = DB::table('buyer')->value('buyer_id');
    $ownerId = DB::table('owner')->where('user_id', $e['tendero']->user_id)->value('owner_id');

    $tipoDrogueria = DB::table('category_business')->insertGetId(['name' => 'Droguería'], 'id');
    $drogueria = DB::table('business')->insertGetId([
        'name' => 'Droguería del Norte', 'qualification' => 0, 'state' => 1, 'type' => $tipoDrogueria,
    ], 'busines_id');
    DB::table('owner_busines')->insert(['owner_id' => $ownerId, 'busines_id' => $drogueria, 'state' => 1]);

    $pedido = fn (int $negocio, array $datos) => DB::table('orderssales')->insertGetId($datos + [
        'buyer_id' => $buyer, 'busines_id' => $negocio, 'methods_id' => 1, 'domicilio' => 0,
        'discount' => 0, 'sale_date' => now(), 'payment_state' => 'pending_cash',
        'state' => OrdersSales::ENTREGADO,
    ], 'orderSales_id');

    $pedido($e['businessId'], ['subtotal' => 20000, 'discount' => 2000, 'domicilio' => 3000, 'total' => 21000]);
    $pedido($e['businessId'], ['subtotal' => 30000, 'total' => 30000, 'state' => OrdersSales::EN_CAMINO]);
    $pedido($drogueria, ['subtotal' => 15000, 'total' => 15000]);
    $pedido($drogueria, ['subtotal' => 5000, 'total' => 5000, 'sale_date' => '2026-11-01 10:00:00']);

    DB::table('ventas_mostrador')->insert([
        ['busines_id' => $e['businessId'], 'total' => 7000, 'forma_de_pago' => 'efectivo', 'estado_pago' => 'pagada', 'vendida_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()],
        ['busines_id' => $e['businessId'], 'total' => 9000, 'forma_de_pago' => 'qr', 'estado_pago' => 'esperando', 'vendida_at' => now()->subMinutes(5), 'created_at' => now(), 'updated_at' => now()],
    ]);

    // El vecino: otro dueño, otra tienda, ventas de hoy.
    $vecino = User::factory()->create(['rol' => 2]);
    $ownerVecino = DB::table('owner')->insertGetId(['user_id' => $vecino->user_id, 'state' => 1], 'owner_id');
    $deEnfrente = DB::table('business')->insertGetId([
        'name' => 'La de Enfrente', 'qualification' => 0, 'state' => 1,
        'type' => DB::table('category_business')->value('id'),
    ], 'busines_id');
    DB::table('owner_busines')->insert(['owner_id' => $ownerVecino, 'busines_id' => $deEnfrente, 'state' => 1]);
    $pedido($deEnfrente, ['subtotal' => 99000, 'total' => 99000]);

    return $e + ['drogueria' => $drogueria, 'deEnfrente' => $deEnfrente, 'vecino' => $vecino];
}

afterEach(fn () => Carbon::setTestNow());

test('junta los negocios del dueño con la misma venta que Ingresos, y la suma', function () {
    $e = dosNegociosDelMismoDueno();
    Sanctum::actingAs($e['tendero']);

    $r = $this->getJson('/v1/negocio/mis-negocios/ventas?desde=2026-11-26&hasta=2026-11-26')->assertOk();

    $porId = collect($r->json('negocios'))->keyBy('busines_id');

    expect($r->json('periodo'))->toBe(['desde' => '2026-11-26', 'hasta' => '2026-11-26'])
        ->and($porId)->toHaveCount(2)
        ->and($porId[$e['businessId']])->toMatchArray([
            'nombre' => 'Tienda', 'ventas_app' => 18000, 'ventas_mostrador' => 7000, 'total' => 25000, 'pedidos' => 2,
        ])
        ->and($porId[$e['drogueria']])->toMatchArray([
            'nombre' => 'Droguería del Norte', 'ventas_app' => 15000, 'ventas_mostrador' => 0, 'total' => 15000, 'pedidos' => 1,
        ])
        ->and($r->json('total'))->toBe([
            'ventas_app' => 33000, 'ventas_mostrador' => 7000, 'total' => 40000, 'pedidos' => 3,
        ]);

    // Cada fila es la cifra de Ingresos de ese negocio, la misma definición.
    $dia = [now()->startOfDay(), now()->endOfDay()];
    expect((float) $porId[$e['businessId']]['total'])
        ->toBe(VentasDelNegocio::entre($e['businessId'], ...$dia)['ventas'])
        ->and((float) $this->getJson('/v1/negocio/ingresos', ['X-Negocio' => (string) $e['businessId']])->json('hoy'))
        ->toBe(25000.0);
});

test('nunca trae un negocio de otro dueño, aunque se pida con su X-Negocio', function () {
    $e = dosNegociosDelMismoDueno();
    Sanctum::actingAs($e['tendero']);

    $r = $this->getJson('/v1/negocio/mis-negocios/ventas')->assertOk();
    expect(collect($r->json('negocios'))->pluck('busines_id')->all())
        ->not->toContain($e['deEnfrente'])
        // Lo del mes de sus dos negocios; los 99.000 del vecino, no.
        ->and($r->json('total.total'))->toEqual(45000);

    // Con la cabecera del negocio de otro, la puerta dice que no.
    $this->getJson('/v1/negocio/mis-negocios/ventas', ['X-Negocio' => (string) $e['deEnfrente']])
        ->assertForbidden();

    // Con la de SU droguería activa, igual junta los dos: no mira el activo.
    $r = $this->getJson('/v1/negocio/mis-negocios/ventas', ['X-Negocio' => (string) $e['drogueria']])->assertOk();
    expect($r->json('negocios'))->toHaveCount(2);

    // Y el vecino ve solo lo suyo.
    Sanctum::actingAs($e['vecino']);
    $v = $this->getJson('/v1/negocio/mis-negocios/ventas')->assertOk();
    expect(collect($v->json('negocios'))->pluck('busines_id')->all())->toBe([$e['deEnfrente']])
        ->and($v->json('total.total'))->toEqual(99000);
});

test('el periodo: sin fechas, el mes en curso; con fechas, solo ese rango', function () {
    $e = dosNegociosDelMismoDueno();
    Sanctum::actingAs($e['tendero']);

    $mes = $this->getJson('/v1/negocio/mis-negocios/ventas')->assertOk();
    expect($mes->json('periodo'))->toBe(['desde' => '2026-11-01', 'hasta' => '2026-11-26'])
        // El del 1 de noviembre entra en el mes.
        ->and($mes->json('total.total'))->toEqual(45000);

    $primero = $this->getJson('/v1/negocio/mis-negocios/ventas?desde=2026-11-01&hasta=2026-11-01')->assertOk();
    expect($primero->json('total'))->toEqual([
        'ventas_app' => 5000, 'ventas_mostrador' => 0, 'total' => 5000, 'pedidos' => 1,
    ]);

    $this->getJson('/v1/negocio/mis-negocios/ventas?desde=2026-11-20&hasta=2026-11-01')
        ->assertStatus(422)->assertJsonValidationErrors('hasta');
    $this->getJson('/v1/negocio/mis-negocios/ventas?desde=2024-01-01&hasta=2026-11-01')
        ->assertStatus(422)->assertJsonValidationErrors('hasta');
});

test('los otros papeles no entran', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    Sanctum::actingAs($c['user']);
    $this->getJson('/v1/negocio/mis-negocios/ventas')->assertForbidden();

    $e = dosNegociosDelMismoDueno();
    Sanctum::actingAs($e['repartidor']);
    $this->getJson('/v1/negocio/mis-negocios/ventas')->assertForbidden();
});

test('sin sesión, 401', function () {
    $this->getJson('/v1/negocio/mis-negocios/ventas')->assertUnauthorized();
});
