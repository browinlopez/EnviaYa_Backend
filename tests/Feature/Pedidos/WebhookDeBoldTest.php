<?php

use App\Models\Order\OrdersSales;
use App\Models\Payment\Payment;
use App\Models\Payment\PaymentEvent;
use App\Models\Payment\PaymentIntent;
use Illuminate\Support\Facades\DB;

/**
 * EL AVISO DE BOLD: LO ÚNICO QUE CONVIERTE UN COBRO EN UN PEDIDO PAGADO.
 *
 * Con pago en línea el pedido se guarda ANTES de cobrar —la pasarela necesita
 * una referencia— y queda ESCONDIDO: no sale en la lista de la tienda, ni en la
 * del comprador, ni suena el aviso. Lo que lo saca a la luz es este webhook.
 *
 * Si no llega, o llega y se rechaza, la persona pagó y no tiene pedido. No es
 * una molestia: es dinero cobrado sin nada a cambio, y del lado de la tienda
 * nadie se entera de que hay algo que preparar.
 *
 * NO HABÍA NINGUNA PRUEBA DE ESTE CAMINO. `BOLD_WEBHOOK_SECRET` estaba vacío en
 * producción —y sin secreto el controlador rechaza TODO a propósito, porque una
 * firma HMAC sin clave es trivial de falsificar—, así que el camino entero
 * llevaba desde siempre sin ejercitarse.
 *
 * Estas pruebas firman el cuerpo igual que lo firma Bold. Cuando la variable
 * esté puesta en el servidor, esto es exactamente lo que va a ocurrir.
 */

/** Un pedido esperando el cobro, con su pago y su intento, como los crea la app. */
function pedidoEsperandoCobroDeBold(): array
{
    $e = escenarioDeTope(null, metodo: 2);

    DB::table('orderssales')->where('orderSales_id', $e['orderId'])->update([
        'state'         => 1,
        // Escondido hasta que el cobro se confirme.
        'payment_state' => 'pending_online',
    ]);

    $referencia = 'ref-' . uniqid();

    $intento = PaymentIntent::create([
        'orderSales_id'     => $e['orderId'],
        'provider'          => 'bold',
        'bold_reference_id' => $referencia,
        'amount'            => 60000,
        'currency'          => 'COP',
        'status'            => 'pending',
    ]);

    $pago = Payment::create([
        'orderSales_id'       => $e['orderId'],
        'methods_id'          => 2,
        'provider'            => 'bold',
        'provider_payment_id' => null,
        'amount'              => 60000,
        'total'               => 60000,
        'payment_status'      => 0,
        'status'              => 'pending',
    ]);

    return $e + compact('referencia', 'intento', 'pago');
}

/** Firma el cuerpo como lo hace Bold: base64 del cuerpo, HMAC-SHA256 con el secreto. */
function firmaDeBold(string $cuerpo, string $secreto): string
{
    return hash_hmac('sha256', base64_encode($cuerpo), $secreto);
}

/** Manda el aviso ya firmado, exactamente como llegaría de Bold. */
function avisoDeBold(array $payload, ?string $secreto = 'secreto-de-prueba')
{
    $cuerpo = json_encode($payload);

    $cabeceras = ['Content-Type' => 'application/json'];

    if ($secreto !== null) {
        $cabeceras['x-bold-signature'] = firmaDeBold($cuerpo, $secreto);
    }

    return test()->call('POST', '/v1/webhooks/bold', [], [], [], [
        'CONTENT_TYPE'          => 'application/json',
        'HTTP_X_BOLD_SIGNATURE' => $cabeceras['x-bold-signature'] ?? '',
    ], $cuerpo);
}

beforeEach(function () {
    config(['services.bold.webhook_secret' => 'secreto-de-prueba']);
});

/* ---------------------------------------------------------------------- */

it('un cobro aprobado saca el pedido a la luz', function () {
    $e = pedidoEsperandoCobroDeBold();

    avisoDeBold([
        'id'      => 'notif-1',
        'type'    => 'SALE_APPROVED',
        'subject' => 'tx-123',
        'data'    => ['metadata' => ['reference' => $e['referencia']]],
    ])->assertOk();

    $pedido = OrdersSales::find($e['orderId']);

    expect($pedido->payment_state)->toBe('paid')
        ->and((int) Payment::find($e['pago']->id ?? $e['pago']->payments_id)->payment_status)->toBe(1);
});

it('EN PRODUCCION sin llave se rechaza todo, aunque venga bien firmado', function () {
    /*
     * Una firma HMAC con clave vacia la calcula cualquiera, y esta ruta es la
     * que marca pedidos como pagados. Sin llave hay que rechazar todo y que se
     * note, en vez de aceptar cobros que nadie firmo.
     *
     * OJO CON ESTA PRUEBA: hay que fijar el entorno a mano. En pruebas Bold
     * firma con clave vacia a proposito —lo documenta— y el controlador lo
     * admite ahi; si no se pone `produccion`, esto deja de vigilar lo que dice
     * vigilar y pasa por el motivo equivocado, que es lo que estaba haciendo.
     */
    config([
        'services.bold.entorno' => 'produccion',
        'services.bold.webhook_secret' => '',
    ]);

    $e = pedidoEsperandoCobroDeBold();

    avisoDeBold([
        'id'      => 'notif-sin-secreto',
        'type'    => 'SALE_APPROVED',
        'subject' => 'tx-123',
        'data'    => ['metadata' => ['reference' => $e['referencia']]],
    ])->assertStatus(400);

    expect(OrdersSales::find($e['orderId'])->payment_state)->toBe('pending_online');
});

it('una firma que no cuadra no mueve nada', function () {
    $e = pedidoEsperandoCobroDeBold();

    avisoDeBold([
        'id'      => 'notif-falsa',
        'type'    => 'SALE_APPROVED',
        'subject' => 'tx-123',
        'data'    => ['metadata' => ['reference' => $e['referencia']]],
    ], 'otro-secreto-distinto')->assertStatus(400);

    expect(OrdersSales::find($e['orderId'])->payment_state)->toBe('pending_online');
});

it('el mismo aviso dos veces no cuenta dos pagos', function () {
    /*
     * Bold reenvia una notificacion si no recibe respuesta a tiempo. Sin
     * idempotencia, el reenvio volveria a anunciar el pedido a la tienda.
     */
    $e = pedidoEsperandoCobroDeBold();

    $aviso = [
        'id'      => 'notif-repetida',
        'type'    => 'SALE_APPROVED',
        'subject' => 'tx-123',
        'data'    => ['metadata' => ['reference' => $e['referencia']]],
    ];

    avisoDeBold($aviso)->assertOk();
    avisoDeBold($aviso)->assertOk()->assertJsonPath('duplicated', true);

    expect(PaymentEvent::where('payload->id', 'notif-repetida')->count())->toBe(1);
});

it('un cobro rechazado deja el pedido marcado como tal', function () {
    $e = pedidoEsperandoCobroDeBold();

    avisoDeBold([
        'id'      => 'notif-rechazo',
        'type'    => 'SALE_REJECTED',
        'subject' => 'tx-123',
        'data'    => ['metadata' => ['reference' => $e['referencia']]],
    ])->assertOk();

    expect(OrdersSales::find($e['orderId'])->payment_state)
        ->toBe(OrdersSales::PAGO_RECHAZADO);
});

it('un aviso de un pago que no existe queda registrado y responde 200', function () {
    /*
     * 200 y no error: ya quedo anotado en `payment_events`, y devolver un
     * fallo solo haria que Bold reintentara algo que nunca vamos a poder casar.
     */
    avisoDeBold([
        'id'      => 'notif-huerfana',
        'type'    => 'SALE_APPROVED',
        'subject' => 'tx-que-no-existe',
        'data'    => ['metadata' => ['reference' => 'ref-que-no-existe']],
    ])->assertOk()->assertJsonPath('matched', false);

    expect(PaymentEvent::where('payload->id', 'notif-huerfana')->exists())->toBeTrue();
});

it('todo aviso queda guardado, aunque no cambie nada', function () {
    // El registro de eventos es lo que permite reconstruir que paso el dia que
    // alguien reclame un cobro.
    $e = pedidoEsperandoCobroDeBold();

    avisoDeBold([
        'id'      => 'notif-informativa',
        'type'    => 'SALE_PENDING',
        'subject' => 'tx-123',
        'data'    => ['metadata' => ['reference' => $e['referencia']]],
    ])->assertOk();

    expect(PaymentEvent::where('payload->id', 'notif-informativa')->exists())->toBeTrue()
        // Un evento informativo no aprueba nada.
        ->and(OrdersSales::find($e['orderId'])->payment_state)->toBe('pending_online');
});

it('VOID_REJECTED no le quita el pago a un pedido que SI esta pagado', function () {
    /*
     * Son dos cosas opuestas que se leen parecido:
     *
     *   VOID_APPROVED → la anulacion se hizo. La venta se deshizo.
     *   VOID_REJECTED → la anulacion FALLO. La venta SIGUE APROBADA.
     *
     * El segundo estaba en la lista de rechazos. Pasaba esto: se intentaba
     * anular un cobro, Bold respondia que no se pudo, y el sistema le quitaba
     * el pago a un pedido pagado de verdad. El cliente con la plata debitada y
     * el pedido marcado como rechazado.
     */
    $e = pedidoEsperandoCobroDeBold();

    // Primero el cobro entra y el pedido queda pagado y a la vista.
    avisoDeBold([
        'id'      => 'notif-venta-ok',
        'type'    => 'SALE_APPROVED',
        'subject' => 'tx-123',
        'data'    => ['metadata' => ['reference' => $e['referencia']]],
    ])->assertOk();

    expect(OrdersSales::find($e['orderId'])->payment_state)->toBe('paid');

    // Y ahora llega el aviso de que una anulacion NO se pudo hacer.
    avisoDeBold([
        'id'      => 'notif-anulacion-fallida',
        'type'    => 'VOID_REJECTED',
        'subject' => 'tx-123',
        'data'    => ['metadata' => ['reference' => $e['referencia']]],
    ])->assertOk();

    // El pedido sigue pagado: la venta nunca se deshizo.
    expect(OrdersSales::find($e['orderId'])->payment_state)->toBe('paid');

    // Pero el intento fallido queda registrado, que para eso sirve el aviso.
    expect(PaymentEvent::where('event_type', 'VOID_REJECTED')->exists())->toBeTrue();
});

it('VOID_APPROVED si deshace la venta', function () {
    // El contrario del de arriba, para que la diferencia quede fijada.
    $e = pedidoEsperandoCobroDeBold();

    avisoDeBold([
        'id'      => 'notif-venta-ok-2',
        'type'    => 'SALE_APPROVED',
        'subject' => 'tx-123',
        'data'    => ['metadata' => ['reference' => $e['referencia']]],
    ])->assertOk();

    avisoDeBold([
        'id'      => 'notif-anulada',
        'type'    => 'VOID_APPROVED',
        'subject' => 'tx-123',
        'data'    => ['metadata' => ['reference' => $e['referencia']]],
    ])->assertOk();

    expect(OrdersSales::find($e['orderId'])->payment_state)
        ->toBe(OrdersSales::PAGO_RECHAZADO);
});

it('EN PRUEBAS se acepta la firma con clave vacia, que es como firma Bold', function () {
    /*
     * Lo dice la documentacion de Bold: «En modo pruebas la firma usa una
     * clave vacia» (https://developers.bold.co/webhook). En sandbox no hay
     * plata, asi que no reparten secretos.
     *
     * Antes esto no se contemplaba, y la consecuencia era que el boton «Probar
     * el webhook» del panel daba 400 SIEMPRE: nosotros firmabamos con nuestra
     * llave y Bold venia firmado con nada. El webhook era imposible de
     * ensayar, y es la pieza que decide si un pedido pagado aparece o se queda
     * escondido para siempre.
     */
    config(['services.bold.entorno' => 'prueba']);

    $e = pedidoEsperandoCobroDeBold();

    avisoDeBold([
        'id'      => 'notif-firmada-con-nada',
        'type'    => 'SALE_APPROVED',
        'subject' => 'tx-123',
        'data'    => ['metadata' => ['reference' => $e['referencia']]],
    ], secreto: '')->assertOk();

    expect(OrdersSales::find($e['orderId'])->payment_state)->toBe('paid');
});

it('EN PRUEBAS tambien se acepta la nuestra, porque no se sabe cual firma', function () {
    /*
     * La pagina de Bold enumera que llave firma el evento y NO nombra la de
     * «API de pagos en linea», que es la nuestra: solo las de Boton de pagos y
     * API Datafono. Mientras Bold lo aclare, en pruebas valen las dos, y asi
     * el ensayo sirve venga firmado con cualquiera.
     */
    config(['services.bold.entorno' => 'prueba']);

    $e = pedidoEsperandoCobroDeBold();

    avisoDeBold([
        'id'      => 'notif-firmada-con-la-nuestra',
        'type'    => 'SALE_APPROVED',
        'subject' => 'tx-123',
        'data'    => ['metadata' => ['reference' => $e['referencia']]],
    ], secreto: 'secreto-de-prueba')->assertOk();

    expect(OrdersSales::find($e['orderId'])->payment_state)->toBe('paid');
});

it('EN PRUEBAS una firma de otra llave sigue sin pasar', function () {
    // Admitir la clave vacia no es admitir cualquier cosa.
    config(['services.bold.entorno' => 'prueba']);

    $e = pedidoEsperandoCobroDeBold();

    avisoDeBold([
        'id'      => 'notif-llave-ajena',
        'type'    => 'SALE_APPROVED',
        'subject' => 'tx-123',
        'data'    => ['metadata' => ['reference' => $e['referencia']]],
    ], secreto: 'llave-de-otro')->assertStatus(400);

    expect(OrdersSales::find($e['orderId'])->payment_state)->toBe('pending_online');
});
