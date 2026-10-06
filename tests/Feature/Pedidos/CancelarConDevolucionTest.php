<?php

use App\Models\Order\OrdersSales;
use App\Models\Payment\Payment;
use App\Models\Rol;
use App\Models\User;
use App\Services\BoldService;
use App\Services\DevolucionDelPedido;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * CANCELAR UN PEDIDO: EL REPORTE, LOS AVISOS Y LA PLATA.
 *
 * Lo que faltaba:
 *
 *   · `status_history` llevaba desde siempre en la base con CERO filas. La
 *     tabla estaba y nadie escribía en ella, así que un pedido cancelado no
 *     dejaba constancia de quién, cuándo ni por qué.
 *   · El aviso solo le llegaba al comprador. El tendero se enteraba por el
 *     canal del pedido —que sirve si tiene la pantalla abierta— y si no, se
 *     ponía a preparar un pedido cancelado.
 *   · Y la plata no se tocaba: se devolvía el inventario y el cupo de fiado, y
 *     el cobro se quedaba en Bold sin que nadie lo supiera.
 */
function pedidoCancelable(int $metodo = 1, bool $cobrado = false): array
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);

    $comprador = User::factory()->create(['rol' => 1]);
    $tendero = User::factory()->create(['rol' => 2]);

    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $comprador->user_id, 'state' => 1,
    ], 'buyer_id');

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'Tienda de la prueba', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
        'latitude' => 11.0, 'longitude' => -74.8,
    ], 'busines_id');

    // La cadena real: user → owner → owner_busines → business.
    $ownerId = DB::table('owner')->insertGetId([
        'user_id' => $tendero->user_id, 'state' => 1,
    ], 'owner_id');
    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $negocio, 'state' => 1,
    ]);

    DB::table('payment_methods')->insertOrIgnore([
        ['methods_id' => 1, 'name' => 'Efectivo', 'state' => 1],
        ['methods_id' => 2, 'name' => 'Tarjeta', 'state' => 1],
        ['methods_id' => 5, 'name' => 'Pago por QR', 'state' => 1],
    ]);

    $id = DB::table('orderssales')->insertGetId([
        'buyer_id' => $buyerId, 'busines_id' => $negocio,
        'methods_id' => $metodo,
        'total' => 18500, 'subtotal' => 12500, 'domicilio' => 6000,
        'sale_date' => now(), 'state' => 1,
    ], 'orderSales_id');

    if ($cobrado) {
        Payment::create([
            'orderSales_id' => $id,
            'methods_id' => $metodo,
            'provider' => 'bold',
            'provider_payment_id' => 'TX-COBRADO',
            'amount' => 18500, 'subtotal' => 12500, 'total' => 18500, 'domicilio' => 6000,
            'payment_status' => 1,
            'status' => 'approved',
            'payment_date' => now(),
        ]);
    }

    Sanctum::actingAs($comprador);

    return compact('comprador', 'tendero', 'negocio', 'id');
}

/* ===================================================================== */

test('cancelar deja el reporte: quien, cuando y por que', function () {
    $t = pedidoCancelable();

    test()->putJson("/v1/orders/{$t['id']}/cancel", [
        'motivo' => 'Me equivoqué de dirección',
    ])->assertOk();

    $reporte = DB::table('status_history')->where('order_id', $t['id'])->first();

    expect($reporte)->not->toBeNull()
        ->and($reporte->motivo)->toBe('Me equivoqué de dirección')
        ->and((int) $reporte->status_history)->toBe(5)
        ->and((int) $reporte->created_by)->toBe($t['comprador']->user_id);
});

test('sin motivo tambien queda reporte, no un hueco', function () {
    /*
     * Obligar a justificarse para cancelar es una fricción fea, pero el
     * reporte sin motivo es un hueco. Se guarda uno por defecto.
     */
    $t = pedidoCancelable();

    test()->putJson("/v1/orders/{$t['id']}/cancel")->assertOk();

    expect(DB::table('status_history')->where('order_id', $t['id'])->value('motivo'))
        ->toBe('Cancelado por el comprador');
});

test('la tienda se entera, no solo el comprador', function () {
    /*
     * Antes el aviso era solo para el comprador. El tendero se enteraba por el
     * canal del pedido, que sirve si tiene la pantalla abierta; si no, se pone
     * a preparar algo cancelado y lo descubre al salir a entregarlo.
     */
    $t = pedidoCancelable();

    test()->putJson("/v1/orders/{$t['id']}/cancel", ['motivo' => 'Ya no lo necesito'])
        ->assertOk();

    $avisos = DB::table('notifications')->where('tipo', 'pedido_cancelado')->get();

    expect($avisos->pluck('user_id')->all())
        ->toContain($t['comprador']->user_id)
        ->toContain($t['tendero']->user_id);

    $deLaTienda = $avisos->firstWhere('user_id', $t['tendero']->user_id);
    expect($deLaTienda->message)->toContain('No lo prepares');
});

test('un pedido en efectivo no genera devolucion', function () {
    // No hay nada cobrado: inventar una devolución sería inventar una deuda.
    $t = pedidoCancelable(metodo: 1);

    $r = test()->putJson("/v1/orders/{$t['id']}/cancel")->assertOk();

    expect($r->json('devolucion'))->toBeNull();
});

test('con tarjeta cobrada el mismo dia se ANULA, que es lo bueno', function () {
    /*
     * Anular el mismo día antes de las 9 p. m. hace que la plata no llegue a
     * salir de la cuenta del cliente. Es muy distinto de un reembolso, que
     * tarda días.
     *
     * LA HORA SE CONGELA, no se consulta. Con el reloj de verdad esta prueba
     * pasaba o fallaba según a qué hora se lanzara la suite —y falló de noche,
     * que es justo cuando nadie está mirando—. Una prueba que depende del
     * reloj no prueba nada, avisa a destiempo.
     */
    Carbon::setTestNow(Carbon::today()->setTime(10, 0));

    $t = pedidoCancelable(metodo: 2, cobrado: true);

    $bold = Mockery::mock(BoldService::class);
    $bold->shouldReceive('anular')->once()->with('TX-COBRADO')->andReturn(['ok' => true]);
    $bold->shouldNotReceive('devolver');
    app()->instance(BoldService::class, $bold);

    $r = test()->putJson("/v1/orders/{$t['id']}/cancel", ['motivo' => 'Cancelado'])
        ->assertOk();

    expect($r->json('devolucion'))->toBe('anulada')
        ->and($r->json('message'))->toContain('Anulamos el cobro');

    $pago = Payment::where('orderSales_id', $t['id'])->first();
    expect($pago->refund_status)->toBe('anulada')
        ->and($pago->refunded_at)->not->toBeNull();

    Carbon::setTestNow();
});

test('pasadas las 9 de la noche ya no se anula: se pide reembolso', function () {
    /*
     * El límite es de Bold, no nuestro. Pasada esa hora el cobro ya salió al
     * banco y lo único que queda es pedir que lo devuelvan, lo cual tarda días
     * y además Bold tiene que aprobarlo. Por eso queda `solicitada` y no
     * `devuelta`: todavía no es un hecho.
     */
    Carbon::setTestNow(Carbon::today()->setTime(22, 30));

    $t = pedidoCancelable(metodo: 2, cobrado: true);

    $bold = Mockery::mock(BoldService::class);
    $bold->shouldNotReceive('anular');
    $bold->shouldReceive('devolver')->once()->andReturn(['ok' => true]);
    app()->instance(BoldService::class, $bold);

    $r = test()->putJson("/v1/orders/{$t['id']}/cancel", ['motivo' => 'Cancelado'])
        ->assertOk();

    expect($r->json('devolucion'))->toBe('solicitada')
        ->and($r->json('message'))->toContain('días hábiles');

    Carbon::setTestNow();
});

test('con QR queda MARCADA A MANO: Bold no la sabe hacer sola', function () {
    /*
     * Ni `void` ni `refund` admiten QR o PSE. Fingir que se devolvió seria
     * peor que decir que falta hacerlo: la plata se quedaria en Bold y nadie
     * volveria a mirarla.
     */
    $t = pedidoCancelable(metodo: 5, cobrado: true);

    $bold = Mockery::mock(BoldService::class);
    $bold->shouldNotReceive('anular');
    $bold->shouldNotReceive('devolver');
    app()->instance(BoldService::class, $bold);

    $r = test()->putJson("/v1/orders/{$t['id']}/cancel", ['motivo' => 'Cancelado'])
        ->assertOk();

    expect($r->json('devolucion'))->toBe('manual');

    expect(Payment::where('orderSales_id', $t['id'])->value('refund_status'))
        ->toBe('manual');
});

test('si Bold no responde, la cancelacion sigue en pie', function () {
    /*
     * Lo contrario dejaria al cliente sin pedido Y sin cancelacion. El pago
     * queda `pendiente` —no `rechazada`: no sabemos que Bold dijera que no,
     * solo que no pudimos preguntarle— y alguien lo retoma.
     */
    $t = pedidoCancelable(metodo: 2, cobrado: true);

    $bold = Mockery::mock(BoldService::class);
    $bold->shouldReceive('anular')->andThrow(new \Exception('Bold caido'));
    $bold->shouldReceive('devolver')->andThrow(new \Exception('Bold caido'));
    app()->instance(BoldService::class, $bold);

    test()->putJson("/v1/orders/{$t['id']}/cancel", ['motivo' => 'Cancelado'])
        ->assertOk();

    expect(OrdersSales::find($t['id'])->state)->toBe(5)
        ->and(Payment::where('orderSales_id', $t['id'])->value('refund_status'))
        ->toBe('pendiente');
});

test('no se devuelve dos veces el mismo cobro', function () {
    $t = pedidoCancelable(metodo: 2, cobrado: true);

    Payment::where('orderSales_id', $t['id'])->update(['refund_status' => 'anulada']);

    $bold = Mockery::mock(BoldService::class);
    $bold->shouldNotReceive('anular');
    $bold->shouldNotReceive('devolver');
    app()->instance(BoldService::class, $bold);

    expect(app(DevolucionDelPedido::class)->devolver(OrdersSales::find($t['id']), 'otra vez'))
        ->toBe('anulada');
});
