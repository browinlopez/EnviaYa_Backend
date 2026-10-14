<?php

use App\Models\Order\OrdersSales;
use App\Models\Rol;
use App\Models\User;
use App\Services\BoldService;
use App\Services\PagoEnLinea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * EL COBRO CON PASARELA, QUE NO COBRÓ NUNCA.
 *
 * Tres fallos encadenados, los tres invisibles, encontrados probando el pago
 * por QR en el emulador:
 *
 *   1. `cobrar()` usaba `$buyer` y `$address`, dos variables que NO EXISTEN en
 *      el método —quedaron al extraerlo de `OrderController::store`—. Como la
 *      app nunca manda `payer`, esa rama se ejecuta siempre: toda compra con
 *      tarjeta o QR moría ahí.
 *   2. `cobrar()` devolvía solo el intent, así que el `$payment` —con el QR, el
 *      enlace y el estado— se perdía. En el controlador, `isset($payment)`
 *      miraba una variable que no se asignaba jamás, y `action` viajaba en null
 *      SIEMPRE. Aunque Bold devolviera un QR perfecto, la app no lo veía.
 *   3. Y cuando no había forma de pagar, el pedido se creaba igual. La tienda
 *      veía entrar un pedido que nadie había pagado.
 *
 * Ninguno rompía una prueba ni dejaba una línea en el registro: el `catch` de
 * arriba los convertía a todos en «Error al crear la orden».
 */
function pedidoListoParaCobrar(): OrdersSales
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $user = User::factory()->create([
        'rol' => 1,
        'phone' => '3001112233',
        'name' => 'Vecina de Prueba',
    ]);
    $buyerId = DB::table('buyer')->insertGetId(['user_id' => $user->user_id, 'state' => 1]);

    $paisId = DB::table('countries')->insertGetId(['name' => 'Colombia', 'iso_code' => 'CO']);
    $depId = DB::table('departments')->insertGetId(['name' => 'Atlántico', 'country_id' => $paisId]);
    $muniId = DB::table('municipalities')->insertGetId([
        'name' => 'Barranquilla', 'department_id' => $depId,
    ]);

    $addressId = DB::table('user_address')->insertGetId([
        'user_id' => $user->user_id,
        'address' => 'Carrera 41G #80-12',
        'municipality_id' => $muniId,
        'state' => 1,
    ], 'address_id');

    $id = DB::table('orderssales')->insertGetId([
        'buyer_id' => $buyerId, 'address_id' => $addressId,
        'total' => 18500, 'subtotal' => 12500, 'domicilio' => 6000,
        'sale_date' => now(), 'state' => 1,
    ], 'orderSales_id');

    return OrdersSales::find($id);
}

/** Una petición como la que manda la app: SIN `payer`. Ese es el punto. */
function peticionDeQr(): Request
{
    return new Request(['methods_id' => 5]);
}

function boldQueDevuelveQr(?string $qr = 'iVBORw0KGgoAAAA'): BoldService
{
    $bold = Mockery::mock(BoldService::class);

    $bold->shouldReceive('createIntent')->andReturn([
        'payload' => ['status' => 'ACTIVE', 'test' => true],
    ]);

    $bold->shouldReceive('makePayment')->andReturn([
        'transaction_id' => 'SROTWBLSKNG',
        'status' => $qr ? 'running' : 'rejected',
        'next_actions' => $qr ? ['qr_payload' => $qr, 'expires_at' => time() + 600] : null,
    ]);

    return $bold;
}

/* ===================================================================== */

test('el pagador se arma con el comprador del PEDIDO, no con variables que no existen', function () {
    /*
     * `$buyer->user` sobre una variable indefinida es una excepción en PHP 8.
     * Si vuelve a colarse, esto revienta acá en vez de en el teléfono de un
     * cliente que ya tiene el carrito lleno.
     */
    $order = pedidoListoParaCobrar();

    $cobro = app(PagoEnLinea::class)->cobrar(
        $order,
        [['product_id' => 1, 'amount' => 5]],
        [1 => 2500],
        peticionDeQr(),
        boldQueDevuelveQr(),
    );

    expect($cobro)->not->toBeNull();
});

test('devuelve el pago, no solo el intent: es donde viaja el QR', function () {
    /*
     * Sin esto, `action` sale en null en TODAS las respuestas y la app enseña
     * «la pasarela no devolvió forma de pagar» incluso con el QR en la mano.
     */
    $order = pedidoListoParaCobrar();

    $cobro = app(PagoEnLinea::class)->cobrar(
        $order,
        [['product_id' => 1, 'amount' => 5]],
        [1 => 2500],
        peticionDeQr(),
        boldQueDevuelveQr(),
    );

    expect($cobro->payment)->not->toBeNull()
        ->and($cobro->payment->qr_payload)->toBe('iVBORw0KGgoAAAA')
        ->and($cobro->intent)->not->toBeNull()
        ->and($cobro->intent->bold_reference_id)->toStartWith('ORD-');
});

test('sin forma de pagar el pago queda sin QR: el pedido no puede darse por bueno', function () {
    /*
     * El controlador mira exactamente esto para decidir si deshace la
     * transacción. Un pedido sin cobro es un pedido que la tienda prepara y
     * nadie paga.
     */
    $order = pedidoListoParaCobrar();

    $cobro = app(PagoEnLinea::class)->cobrar(
        $order,
        [['product_id' => 1, 'amount' => 5]],
        [1 => 2500],
        peticionDeQr(),
        boldQueDevuelveQr(qr: null),
    );

    $hayComoPagar = $cobro->payment
        && ($cobro->payment->qr_payload || $cobro->payment->redirect_url || $cobro->payment->payment_status);

    expect($hayComoPagar)->toBeFalsy();
});

/** Una tienda con un producto, propia: no se depende del fixture de otro archivo. */
function tiendaParaCobro(): array
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $comprador = User::factory()->create(['rol' => 1]);

    DB::table('buyer')->insert([
        'user_id' => $comprador->user_id, 'qualification' => 0,
        'belongs_to_complex' => 0, 'state' => 1,
    ]);

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');

    $negocio = DB::table('business')->insertGetId([
        'name' => 'Tienda del cobro', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
        'latitude' => 11.0, 'longitude' => -74.8,
    ], 'busines_id');

    $producto = DB::table('products')->insertGetId(['name' => 'Arroz', 'state' => 1], 'products_id');
    DB::table('products_business')->insert([
        'busines_id' => $negocio, 'products_id' => $producto, 'price' => 3000, 'amount' => 10,
    ]);

    $direccion = DB::table('user_address')->insertGetId([
        'user_id' => $comprador->user_id, 'address' => 'Calle 1',
        'latitude' => 11.001, 'longitude' => -74.801, 'state' => 1,
    ], 'address_id');

    DB::table('payment_methods')->insertOrIgnore([
        ['methods_id' => 1, 'name' => 'Efectivo', 'state' => 1],
        ['methods_id' => 2, 'name' => 'Tarjeta', 'state' => 1],
    ]);

    Laravel\Sanctum\Sanctum::actingAs($comprador);

    return compact('comprador', 'negocio', 'producto', 'direccion');
}

test('si el cobro no sale, NO queda pedido', function () {
    /*
     * Lo pidió quien conoce el negocio, y tiene toda la razón: un pedido sin
     * cobro es lo peor de los dos mundos. La tienda ve entrar algo que nadie
     * ha pagado y se pone a prepararlo, y el cliente se queda con un pedido a
     * medias que él no pidió así.
     *
     * Antes se creaba igual y se le decía «tu pedido quedó registrado; elige
     * efectivo o inténtalo de nuevo». Ahora la transacción se deshace entera.
     */
    $t = tiendaParaCobro();

    // La pasarela no deja NADA: ni QR, ni enlace, ni aprobación, ni curso.
    test()->mock(PagoEnLinea::class, fn ($m) => $m->shouldReceive('cobrar')->andReturn(
        (object) [
            'intent' => null,
            'payment' => (object) [
                'qr_payload' => null,
                'redirect_url' => null,
                'payment_status' => 0,
                'status' => 'rejected',
            ],
        ],
    ));

    $antes = DB::table('orderssales')->count();

    test()->postJson('/v1/orders/orders', [
        'user_id' => $t['comprador']->user_id,
        'busines_id' => $t['negocio'],
        'address_id' => $t['direccion'],
        'products' => [['product_id' => $t['producto'], 'amount' => 2]],
        'methods_id' => 2,
        'payment_method' => ['type' => 'CARD'],
    ])->assertStatus(422);

    expect(DB::table('orderssales')->count())->toBe($antes);

    // Y el inventario tampoco se toca: no hubo venta.
    $quedan = DB::table('products_business')
        ->where('busines_id', $t['negocio'])->where('products_id', $t['producto'])
        ->value('amount');

    expect((int) $quedan)->toBe(10);
});

test('PSE manda el banco y guarda el enlace al que hay que llevar al cliente', function () {
    /*
     * PSE no tiene otra forma de pagarse: Bold devuelve un `redirect_url` al
     * banco y ahí es donde la persona aprueba. Si ese enlace no se guarda o no
     * se abre, el cliente elige su banco, toca «Pagar» y se queda con un
     * pedido creado sin haber pagado nada.
     *
     * Se comprueban las dos mitades: que se le manda a Bold el banco con la
     * forma que exige —`bank_code` ENTERO, no texto— y que el enlace que
     * devuelve acaba guardado en el pago.
     */
    $order = pedidoListoParaCobrar();

    $enviado = null;
    $bold = Mockery::mock(BoldService::class);
    $bold->shouldReceive('createIntent')->andReturn(['payload' => ['status' => 'ACTIVE']]);
    $bold->shouldReceive('makePayment')->andReturnUsing(function ($body) use (&$enviado) {
        $enviado = $body;

        return [
            'transaction_id' => 'SJLXK0J4S4F',
            'status' => 'running',
            'next_actions' => ['redirect_url' => 'https://checkout.bold.co/payment/PSE-X/SJLXK0J4S4F'],
        ];
    });

    $peticion = new Request([
        'methods_id' => 4,
        'payment_method' => ['bank_code' => 1007, 'bank_name' => 'BANCOLOMBIA'],
    ]);

    $cobro = app(PagoEnLinea::class)->cobrar(
        $order,
        [['product_id' => 1, 'amount' => 5]],
        [1 => 2500],
        $peticion,
        $bold,
    );

    expect($enviado['payment_method']['name'])->toBe('PSE')
        ->and($enviado['payment_method']['bank_code'])->toBe(1007)
        ->and($enviado['payment_method']['bank_code'])->toBeInt()
        ->and($enviado['payment_method']['bank_name'])->toBe('BANCOLOMBIA');

    // Y el enlace, guardado: es por donde la app lleva a la persona al banco.
    expect($cobro->payment->redirect_url)
        ->toBe('https://checkout.bold.co/payment/PSE-X/SJLXK0J4S4F');
});

test('consultar el estado NO borra el QR que el cliente tiene delante', function () {
    /*
     * El sondeo corre cada 4 segundos mientras el código está en pantalla. Leía
     * `next_actions.qr.payload` —la forma VIEJA; Bold manda `qr_payload`— así
     * que siempre salía null, y ese null se guardaba ENCIMA del QR bueno. El
     * primer sondeo borraba la única forma de pagar que había.
     */
    $order = pedidoListoParaCobrar();

    // El medio de pago tiene clave foránea; la base de pruebas nace vacía.
    DB::table('payment_methods')->insertOrIgnore([
        ['methods_id' => 5, 'name' => 'Pago por QR', 'state' => 1],
    ]);

    $pago = App\Models\Payment\Payment::create([
        'orderSales_id' => $order->orderSales_id,
        'methods_id' => 5,
        'provider' => 'bold',
        'provider_payment_id' => 'TX-QR-1',
        'amount' => 18500, 'subtotal' => 12500, 'total' => 18500, 'domicilio' => 6000,
        'payment_status' => 0,
        'status' => 'running',
        'qr_payload' => 'iVBORw0KGgoQR_BUENO',
    ]);

    App\Models\Payment\PaymentIntent::create([
        'orderSales_id' => $order->orderSales_id,
        'provider' => 'bold',
        'bold_reference_id' => 'ORD-QR-1',
        'amount' => 18500,
        'currency' => 'COP',
        'status' => 'ACTIVE',
    ]);

    // Bold responde el estado SIN next_actions, que es lo normal al consultar.
    $bold = Mockery::mock(BoldService::class);
    $bold->shouldReceive('checkPayment')->andReturn([
        'status' => 'running',
        'transaction_id' => 'TX-QR-1',
    ]);

    app(App\Http\Controllers\Payment\PaymentController::class)
        ->checkStatus('ORD-QR-1', $bold);

    // El QR sigue ahí: sin noticia, se conserva lo que ya se sabía.
    expect($pago->fresh()->qr_payload)->toBe('iVBORw0KGgoQR_BUENO');
});

/**
 * Un pedido escondido esperando el cobro, con su pago y su intento.
 *
 * Es el estado en que nace toda compra en línea: existe en la base porque la
 * pasarela necesita una referencia, pero no sale en ninguna lista.
 */
function cobroEnCursoDeBold(string $referencia, int $metodo = 5): array
{
    $order = pedidoListoParaCobrar();

    DB::table('payment_methods')->insertOrIgnore([
        ['methods_id' => $metodo, 'name' => 'Pago en linea', 'state' => 1],
    ]);

    DB::table('orderssales')->where('orderSales_id', $order->orderSales_id)->update([
        'methods_id' => $metodo,
        'payment_state' => OrdersSales::ESPERANDO_PAGO,
    ]);

    $pago = App\Models\Payment\Payment::create([
        'orderSales_id' => $order->orderSales_id,
        'methods_id' => $metodo,
        'provider' => 'bold',
        'provider_payment_id' => 'TX-' . $referencia,
        'amount' => 18500, 'subtotal' => 12500, 'total' => 18500, 'domicilio' => 6000,
        'payment_status' => 0,
        'status' => 'running',
    ]);

    App\Models\Payment\PaymentIntent::create([
        'orderSales_id' => $order->orderSales_id,
        'provider' => 'bold',
        'bold_reference_id' => $referencia,
        'amount' => 18500,
        'currency' => 'COP',
        'status' => 'ACTIVE',
    ]);

    return ['order' => $order->fresh(), 'pago' => $pago];
}

test('un cobro rechazado CIERRA el pedido: no se queda esperando para siempre', function () {
    /*
     * Esto dejaba el pedido en `pending_online` con CUALQUIER estado que no
     * fuera aprobado. Pero Bold da `REJECTED`, `FAILED` y `VOIDED` como
     * FINALES: de ahí no se mueve nadie.
     *
     * O sea que un cobro rechazado dejaba el pedido escondido eternamente
     * —invisible para la tienda, para el comprador y para el domiciliario—
     * esperando una confirmación que ya se sabía que no iba a llegar. Y hoy
     * este es el único camino que corre de verdad, porque webhook de Bold no
     * llega ninguno todavía.
     */
    $e = cobroEnCursoDeBold('ORD-RECHAZO-1');

    $bold = Mockery::mock(BoldService::class);
    $bold->shouldReceive('checkPayment')->andReturn([
        'status' => 'rejected',
        'transaction_id' => 'TX-ORD-RECHAZO-1',
    ]);

    app(App\Http\Controllers\Payment\PaymentController::class)
        ->checkStatus('ORD-RECHAZO-1', $bold);

    expect($e['order']->fresh()->payment_state)->toBe(OrdersSales::PAGO_RECHAZADO)
        ->and((int) $e['pago']->fresh()->payment_status)->toBe(0);
});

test('lo que sigue en curso NO se cierra: PSE puede tardar', function () {
    // `PENDING` es el de PSE y `PROCESSING` el general. Los dos siguen vivos.
    $e = cobroEnCursoDeBold('ORD-PENDIENTE-1', metodo: 4);

    $bold = Mockery::mock(BoldService::class);
    $bold->shouldReceive('checkPayment')->andReturn([
        'status' => 'pending',
        'transaction_id' => 'TX-ORD-PENDIENTE-1',
    ]);

    app(App\Http\Controllers\Payment\PaymentController::class)
        ->checkStatus('ORD-PENDIENTE-1', $bold);

    expect($e['order']->fresh()->payment_state)->toBe(OrdersSales::ESPERANDO_PAGO);
});

test('al aprobar por sondeo se ANUNCIA el pedido, no solo se marca pagado', function () {
    /*
     * Escribía `payment_state = 'paid'` a mano y se saltaba
     * `ConfirmacionDePago`. El pedido aparecía en las listas —`scopeConfirmados`
     * lo deja pasar— pero SIN el anuncio: ni websocket a la pantalla abierta,
     * ni push al que cerró la app. La tienda tenía un pedido pagado delante y
     * nada le avisaba de que había algo que preparar.
     *
     * Y es justo el caso que más lo necesita: quien paga por QR o PSE suele
     * tener la app cerrada cuando el banco confirma.
     */
    Illuminate\Support\Facades\Event::fake([App\Events\OrderCreated::class]);

    $e = cobroEnCursoDeBold('ORD-APROBADO-1');

    $bold = Mockery::mock(BoldService::class);
    $bold->shouldReceive('checkPayment')->andReturn([
        'status' => 'approved',
        'transaction_id' => 'TX-ORD-APROBADO-1',
    ]);

    app(App\Http\Controllers\Payment\PaymentController::class)
        ->checkStatus('ORD-APROBADO-1', $bold);

    expect($e['order']->fresh()->payment_state)->toBe('paid')
        ->and((int) $e['pago']->fresh()->payment_status)->toBe(1);

    Illuminate\Support\Facades\Event::assertDispatched(App\Events\OrderCreated::class);
});

test('la caducidad del QR se LEE, no se inventan 10 minutos', function () {
    /*
     * `expires_at` viene en NANOSEGUNDOS. El código fijaba 10 minutos a mano
     * con solo VER el campo, sin leerlo: da lo mismo mientras Bold no cambie
     * su valor por defecto, pero es el reloj que ve quien está pagando.
     *
     * Pasárselo crudo a Carbon daría una fecha en el año cincuenta mil, así
     * que se normaliza por tamaño.
     */
    $e = cobroEnCursoDeBold('ORD-QR-CADUCA');

    $caducidad = now()->addMinutes(7)->startOfSecond();

    $bold = Mockery::mock(BoldService::class);
    $bold->shouldReceive('checkPayment')->andReturn([
        'status' => 'running',
        'transaction_id' => 'TX-ORD-QR-CADUCA',
        'next_actions' => [
            'qr_payload' => 'iVBORw0KGgoQR',
            'expires_at' => $caducidad->getTimestamp() * 1_000_000_000,
        ],
    ]);

    app(App\Http\Controllers\Payment\PaymentController::class)
        ->checkStatus('ORD-QR-CADUCA', $bold);

    expect($e['pago']->fresh()->qr_expires_at->timestamp)->toBe($caducidad->timestamp);
});
