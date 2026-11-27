<?php

use App\Models\Order\OrdersSales;
use App\Queries\VentasDelNegocio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * UNA VENTA ES LO MISMO EN TODAS LAS PANTALLAS.
 *
 * El 26 de noviembre, a mediodía, la tienda tiene:
 *
 *   p1  entregado hoy        subtotal 20.000 − promo 2.000, domicilio 3.000
 *   p2  en camino hoy        subtotal 30.000 (todavía no es venta)
 *   p3  pagado en línea,     subtotal 40.000 (todavía no es venta)
 *       preparando hoy
 *   p4  cancelado hoy        subtotal 50.000
 *   m1  mostrador, efectivo, cobrado      7.000
 *   m2  mostrador, QR esperando el pago   9.000 (todavía no es venta)
 *   p5  en camino de AYER   (en curso, pero de otro día)
 *
 * Venta de hoy = 18.000 (p1, sin el domicilio) + 7.000 (m1) = 25.000, dos
 * ventas. La misma cifra en Pedidos, Ingresos, el Historial (Resumen
 * económico) y Cuánto te queda.
 */
function ventaUnicaEscenario(): array
{
    Carbon::setTestNow('2026-11-26 12:00:00');
    $e = escenarioDeTope(null);
    $buyer = DB::table('orderssales')->where('orderSales_id', $e['orderId'])->value('buyer_id');
    DB::table('orderssales')->where('orderSales_id', $e['orderId'])->delete();

    $pedido = fn (array $datos) => DB::table('orderssales')->insertGetId($datos + [
        'buyer_id' => $buyer, 'busines_id' => $e['businessId'], 'methods_id' => 1,
        'domicilio' => 0, 'discount' => 0, 'sale_date' => now(), 'payment_state' => 'pending_cash',
    ], 'orderSales_id');

    $pedido(['state' => OrdersSales::ENTREGADO, 'subtotal' => 20000, 'discount' => 2000, 'domicilio' => 3000, 'total' => 21000, 'delivery_date' => now()]);
    $pedido(['state' => OrdersSales::EN_CAMINO, 'subtotal' => 30000, 'total' => 30000]);
    $pedido(['state' => OrdersSales::PREPARANDO, 'subtotal' => 40000, 'total' => 40000, 'methods_id' => 2, 'payment_state' => 'paid']);
    $pedido(['state' => OrdersSales::CANCELADO, 'subtotal' => 50000, 'total' => 50000]);
    $pedido(['state' => OrdersSales::EN_CAMINO, 'subtotal' => 8000, 'total' => 8000, 'sale_date' => now()->subDay()]);

    DB::table('ventas_mostrador')->insert([
        ['busines_id' => $e['businessId'], 'total' => 7000, 'forma_de_pago' => 'efectivo', 'estado_pago' => 'pagada', 'vendida_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()],
        ['busines_id' => $e['businessId'], 'total' => 9000, 'forma_de_pago' => 'qr', 'estado_pago' => 'esperando', 'vendida_at' => now()->subMinutes(5), 'created_at' => now(), 'updated_at' => now()],
    ]);

    Sanctum::actingAs($e['tendero']);

    return $e;
}

afterEach(fn () => Carbon::setTestNow());

test('la definición: pedidos entregados sin domicilio y mostrador cobrado', function () {
    $e = ventaUnicaEscenario();

    expect(VentasDelNegocio::entre($e['businessId'], now()->startOfDay(), now()->endOfDay()))->toBe([
        'ventas' => 25000.0, 'ventas_app' => 18000.0, 'ventas_mostrador' => 7000.0,
        'cuantas' => 2, 'cuantas_app' => 1, 'cuantas_mostrador' => 1,
        'ticket_promedio' => 12500.0,
    ]);
});

test('la cabecera de Pedidos: ventas, pedidos de hoy y en curso como el tablero', function () {
    ventaUnicaEscenario();

    $s = $this->getJson('/v1/negocio/me')->assertOk()->json('stats');

    expect((float) $s['venta_hoy'])->toBe(25000.0)
        ->and((float) $s['venta_hoy_mostrador'])->toBe(7000.0)
        // p1, p2, p3 (sin el cancelado) + la venta cobrada del mostrador.
        ->and($s['hoy'])->toBe(4)
        ->and($s['hoy_mostrador'])->toBe(1)
        // p2 y p3 salen en el tablero de hoy; p5 es de ayer y va aparte.
        ->and($s['en_curso'])->toBe(2)
        ->and($s['en_curso_de_antes'])->toBe(1);
});

test('Ingresos cuenta lo mismo: hoy, cuántas y el bolsillo del mostrador', function () {
    ventaUnicaEscenario();

    $r = $this->getJson('/v1/negocio/ingresos')->assertOk();

    expect((float) $r->json('hoy'))->toBe(25000.0)
        ->and($r->json('ventas_hoy'))->toBe(2)
        ->and((float) $r->json('hoy_mostrador'))->toBe(7000.0);

    // El efectivo del mostrador va al mismo cajón que el de la app.
    expect(collect($r->json('por_medio_hoy'))->keyBy('medio')['Efectivo']['total'])->toEqual(25000);
});

test('el Historial (Resumen económico) y Cuánto te queda, también', function () {
    ventaUnicaEscenario();

    $t = $this->getJson('/v1/negocio/pedidos/historial?desde=2026-11-26&hasta=2026-11-26')
        ->assertOk()->json('totales');

    expect((float) $t['vendido'])->toBe(25000.0)
        ->and($t['ventas'])->toBe(2)
        ->and($t['ventas_en_mostrador'])->toBe(1);

    $c = $this->getJson('/v1/negocio/cuanto-te-queda?desde=2026-11-26&hasta=2026-11-26')
        ->assertOk()->json();

    expect((float) $c['ventas'])->toBe(25000.0)
        ->and((float) $c['ventas_mostrador'])->toBe(7000.0);
});

test('la flota dice cuántos pedidos de ESTA tienda lleva cada uno en camino', function () {
    $e = ventaUnicaEscenario();
    DB::table('orderssales')->where('state', OrdersSales::EN_CAMINO)->update(['domiciliary_id' => $e['domiId']]);

    // Uno de otra tienda en camino con el mismo domiciliario: ocupa su cupo
    // pero no es de esta tienda.
    $otra = DB::table('business')->insertGetId([
        'name' => 'Otra', 'qualification' => 0, 'state' => 1,
        'type' => DB::table('category_business')->value('id'),
    ], 'busines_id');
    DB::table('orderssales')->insert([
        'buyer_id' => DB::table('buyer')->value('buyer_id'), 'busines_id' => $otra, 'methods_id' => 1,
        'subtotal' => 1000, 'domicilio' => 0, 'total' => 1000, 'sale_date' => now(),
        'state' => OrdersSales::EN_CAMINO, 'payment_state' => 'pending_cash', 'domiciliary_id' => $e['domiId'],
    ]);

    $d = collect($this->getJson('/v1/negocio/domiciliarios')->assertOk()->json('domiciliaries'))->first();

    expect($d)->not->toBeNull()
        ->and($d['active_orders'])->toBe(3)
        ->and($d['en_camino_tuyos'])->toBe(2);
});
