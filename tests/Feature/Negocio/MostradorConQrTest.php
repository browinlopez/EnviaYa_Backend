<?php

use App\Services\CuentaDelNegocio;
use App\Services\LiquidacionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/*
 * COBRAR EN EL MOSTRADOR CON QR BRE-B, POR BOLD.
 *
 * El vecino escanea, paga desde su banco y la venta se anota sola. La plata
 * entra a la cuenta de la plataforma y la tienda la recibe en su
 * liquidación, menos lo que cobra la pasarela.
 */
function tiendaParaQr(): array
{
    Carbon::setTestNow('2026-11-24 12:00:00');
    config(['services.bold.webhook_secret' => 'secreto-de-prueba']);
    $e = escenarioDeTope(null);

    $pony = DB::table('products')->insertGetId(['name' => 'Pony Malta', 'state' => 1], 'products_id');
    DB::table('products_business')->insert([
        'busines_id' => $e['businessId'], 'products_id' => $pony, 'price' => 3000,
        'amount' => 10, 'sale_unit' => 'unidad', 'stock_weight' => 0, 'costo' => 2000,
    ]);

    Sanctum::actingAs($e['tendero']);

    return $e + compact('pony');
}

/** Bold contesta: intención creada, pago con su QR, y luego el estado pedido. */
function boldDeMentira(string $estado = 'PROCESSING'): void
{
    Http::fake([
        '*/v1/payment-intent' => Http::response(['payload' => ['status' => 'CREATED']], 201),
        '*/v1/payment/*' => Http::response(['payload' => ['status' => $estado]]),
        '*/v1/payment' => Http::response(['payload' => [
            'transaction_id' => 'TX-1',
            'next_actions' => ['qr_payload' => 'iVBORw0KGgoQR', 'expires_in' => 600],
        ]]),
    ]);
}

function cobrarConQr(array $e, int $cuantos = 2)
{
    return test()->postJson('/v1/negocio/ventas-mostrador', [
        'lineas' => [['products_id' => $e['pony'], 'amount' => $cuantos]],
        'forma_de_pago' => 'qr',
    ]);
}

afterEach(fn () => Carbon::setTestNow());

it('devuelve el QR y la venta nace esperando: todavía no cuenta', function () {
    $e = tiendaParaQr();
    boldDeMentira();

    $r = cobrarConQr($e)->assertCreated();

    expect($r->json('data.estado_pago'))->toBe('esperando')
        ->and($r->json('data.qr_payload'))->toBe('iVBORw0KGgoQR');

    $c = app(CuentaDelNegocio::class)->para($e['businessId'], 'mes', now());
    expect($c['ventas_mostrador'])->toBe(0.0);

    // La referencia avisa que es del mostrador.
    expect(DB::table('ventas_mostrador')->value('bold_referencia'))->toStartWith('MOS-');
});

it('cuando Bold aprueba, queda pagada y entra en la cuenta con la comisión de la pasarela', function () {
    $e = tiendaParaQr();
    boldDeMentira('APPROVED');

    $id = cobrarConQr($e)->json('data.venta_id');
    $this->getJson("/v1/negocio/ventas-mostrador/{$id}/pago")
        ->assertOk()
        ->assertJsonPath('data.estado_pago', 'pagada');

    $c = app(CuentaDelNegocio::class)->para($e['businessId'], 'mes', now());
    // 2 × 3.000 = 6.000; la pasarela se queda el 2,89 %: 173,40.
    expect($c['ventas_mostrador'])->toBe(6000.0)
        ->and($c['comision'])->toBe(173.4);
});

it('el aviso de Bold también la confirma, aunque la pantalla esté cerrada', function () {
    $e = tiendaParaQr();
    boldDeMentira();

    cobrarConQr($e)->assertCreated();
    $ref = DB::table('ventas_mostrador')->value('bold_referencia');

    $cuerpo = json_encode([
        'id' => 'aviso-1', 'type' => 'SALE_APPROVED', 'subject' => 'TX-1',
        'data' => ['metadata' => ['reference' => $ref]],
    ]);
    $this->call('POST', '/v1/webhooks/bold', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_BOLD_SIGNATURE' => hash_hmac('sha256', base64_encode($cuerpo), 'secreto-de-prueba'),
    ], $cuerpo)->assertOk();

    expect(DB::table('ventas_mostrador')->value('estado_pago'))->toBe('pagada');
});

it('si no paga y se cancela, la venta no cuenta y el inventario vuelve', function () {
    $e = tiendaParaQr();
    boldDeMentira();

    $id = cobrarConQr($e, 3)->json('data.venta_id');
    expect((int) DB::table('products_business')->where('products_id', $e['pony'])->value('amount'))->toBe(7);

    $this->postJson("/v1/negocio/ventas-mostrador/{$id}/cancelar")->assertOk();

    expect(DB::table('ventas_mostrador')->value('estado_pago'))->toBe('cancelada')
        ->and((int) DB::table('products_business')->where('products_id', $e['pony'])->value('amount'))->toBe(10);
});

it('si Bold falla, no queda venta a medias ni inventario descontado', function () {
    $e = tiendaParaQr();
    Http::fake(['*' => Http::response(['payload' => ['status_code' => 'PA_001']], 400)]);

    cobrarConQr($e)->assertStatus(422);

    expect(DB::table('ventas_mostrador')->where('estado_pago', 'pagada')->count())->toBe(0)
        ->and((int) DB::table('products_business')->where('products_id', $e['pony'])->value('amount'))->toBe(10);
});

it('la liquidación le paga a la tienda lo cobrado con QR, menos la pasarela, aunque no haya pedidos', function () {
    $e = tiendaParaQr();
    boldDeMentira('APPROVED');
    // El pedido del escenario no está entregado: solo hay mostrador.
    $id = cobrarConQr($e)->json('data.venta_id');
    $this->getJson("/v1/negocio/ventas-mostrador/{$id}/pago")->assertOk();

    $liq = app(LiquidacionService::class)->generar('business', $e['businessId'], '2026-11-01', '2026-11-30');

    expect((float) $liq->counter_qr)->toBe(5826.6)       // 6.000 − 173,40
        ->and((float) $liq->net_payable)->toBe(5826.6)
        ->and((int) DB::table('ventas_mostrador')->value('settlement_id'))->toBe($liq->id);
});
