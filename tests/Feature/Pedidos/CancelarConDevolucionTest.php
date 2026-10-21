<?php

use App\Models\Order\OrdersSales;
use App\Models\Payment\Payment;
use App\Models\Rol;
use App\Models\User;
use App\Services\BoldService;
use App\Services\DevolucionDelPedido;
use Carbon\Carbon;
use App\Models\Marketing\Coupon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
/*
 * `pedidoCancelable()` vive en `tests/Pest.php`: lo comparten estas pruebas y
 * las de la cancelación desde la tienda, y Pest solo carga los archivos que va
 * a correr.
 */

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

test('quien cancelo se guarda como numero y no como fecha', function () {
    /*
     * ESTA PRUEBA EXISTE POR UN ERROR QUE PASÓ TODA LA SUITE.
     *
     * `status_history.created_by` se declaró en 2025 como `timestamp`, y lo
     * que se guarda ahí es el identificador de quien hizo el cambio. SQLite
     * acepta cualquier cosa en una columna de fecha, así que la prueba de
     * arriba —la que comprueba que el reporte lleva el identificador
     * correcto— pasaba en verde. MySQL con `STRICT_TRANS_TABLES` no:
     *
     *     Incorrect datetime value: '7' for column 'created_by'
     *
     * Y ese INSERT va dentro de la transacción de la cancelación, así que al
     * fallar se deshacía todo: el pedido seguía vivo y el comprador recibía un
     * error. En producción `status_history` tenía cero filas.
     *
     * Por eso esto no mira el valor sino el TIPO de la columna, que es lo
     * único que SQLite y MySQL cuentan igual.
     */
    foreach (['created_by', 'updated_by'] as $columna) {
        $tipo = Schema::getColumnType('status_history', $columna);

        expect($tipo)->not->toContain('time')
            ->and($tipo)->not->toContain('date');
    }
});

test('el uso del cupon vuelve al cupo al cancelar', function () {
    /*
     * Al cancelar se devolvía el inventario, el cupo de fiado y los usos de
     * las promociones —las tres cosas, una debajo de la otra— y el cupón se
     * quedaba gastado en un pedido que acabó no existiendo. Con un cupón de un
     * solo uso, eso es quitárselo a quien lo tenía por algo que no fue culpa
     * suya.
     */
    $e = escenarioPedido();

    Coupon::create([
        'code'      => 'UNICO',
        'type'      => 'fixed',
        'value'     => 5000,
        'max_uses'  => 1,
        'starts_at' => now()->subDay()->toDateString(),
        'ends_at'   => now()->addDay()->toDateString(),
    ]);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, ['coupon_code' => 'UNICO']))
        ->assertSuccessful();

    $pedido = DB::table('orderssales')->latest('orderSales_id')->first();

    expect((int) DB::table('coupons')->where('code', 'UNICO')->value('uses_count'))->toBe(1);

    $this->putJson("/v1/orders/{$pedido->orderSales_id}/cancel", ['motivo' => 'Me arrepentí'])
        ->assertOk();

    expect((int) DB::table('coupons')->where('code', 'UNICO')->value('uses_count'))->toBe(0);

    // La constancia del canje NO se borra: se marca. Sigue explicando por qué
    // ese pedido costó lo que costó.
    $canje = DB::table('coupon_redemptions')->where('order_id', $pedido->orderSales_id)->first();

    expect($canje)->not->toBeNull()
        ->and((int) $canje->state)->toBe(0);
});

test('el cupon devuelto no le gasta a nadie su tope por persona', function () {
    /*
     * `max_uses_per_user` se calculaba contando filas de `coupon_redemptions`.
     * Marcar el canje en vez de borrarlo habría dejado al cliente sin su único
     * uso por un pedido que la tienda no pudo preparar: el contador tiene que
     * saltarse los devueltos.
     */
    $e = escenarioPedido();

    Coupon::create([
        'code'              => 'UNAVEZ',
        'type'              => 'fixed',
        'value'             => 5000,
        'max_uses_per_user' => 1,
        'starts_at'         => now()->subDay()->toDateString(),
        'ends_at'           => now()->addDay()->toDateString(),
    ]);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, ['coupon_code' => 'UNAVEZ']))
        ->assertSuccessful();

    $pedido = DB::table('orderssales')->latest('orderSales_id')->first();

    $this->putJson("/v1/orders/{$pedido->orderSales_id}/cancel", ['motivo' => 'Cancelado'])
        ->assertOk();

    // Y puede volver a usarlo, porque no lo usó.
    $this->postJson('/v1/orders/orders', cuerpoPedido($e, ['coupon_code' => 'UNAVEZ']))
        ->assertSuccessful();

    expect((int) DB::table('coupons')->where('code', 'UNAVEZ')->value('uses_count'))->toBe(1);
});
