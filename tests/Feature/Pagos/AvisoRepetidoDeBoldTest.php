<?php

use App\Events\OrderCreated;
use App\Models\Order\OrdersSales;
use App\Models\Payment\Payment;
use App\Models\Payment\PaymentEvent;
use App\Models\Rol;
use App\Models\User;
use App\Services\ConfirmacionDePago;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * EL MISMO AVISO DE BOLD DOS VECES NO COBRA DOS VECES
 *
 * Bold reenvía una notificación cuando una entrega tarda, y esta ruta es la que
 * marca pedidos como pagados. El guardia de idempotencia preguntaba
 * `PaymentEvent::where('payload->id', $id)->exists()`, y eso tenía tres cosas:
 *
 *  · **un escaneo completo.** Medido con `EXPLAIN`: `type=ALL`, `key=NINGUNA`.
 *    No hay índice posible sobre una ruta dentro de un JSON, así que cada aviso
 *    recorría todos los avisos anteriores — y esa tabla crece con cada cobro;
 *  · **comprobar-luego-insertar.** Dos entregas simultáneas pasan las dos la
 *    comprobación antes de que ninguna haya insertado;
 *  · **y se saltaba entero sin `id`** (`if ($notificationId && ...)`).
 *
 * QUÉ SALVABA ESO, dicho sin exagerar: no el guardia. Lo salvaba que
 * `ConfirmacionDePago::confirmar()` es idempotente a propósito —mira
 * `esperandoPago()` ANTES de marcar pagado, así que una segunda pasada no
 * vuelve a anunciar—. La última prueba de este archivo es la que fija eso,
 * porque es la que de verdad impide el daño y está a un `if` de perderse.
 */
/*
 * Los ayudantes de este archivo llevan nombres largos a proposito: Pest carga
 * TODOS los archivos de pruebas en el mismo proceso, asi que un
 * `pedidoEsperandoPago()` choca con el que ya existe en
 * `tests/Feature/Pedidos/PedidoSoloSiSePagaTest.php` y el error es un
 * `Cannot redeclare`, no un aviso. Es la trampa que documenta `tests/Pest.php`.
 */
function pedidoEsperandoAlBanco(): array
{
    foreach ([1 => 'comprador', 2 => 'tendero'] as $id => $n) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $n, 'guard_name' => 'web']);
    }

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'La del cobro', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    $tendero = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId([
        'user_id' => $tendero->user_id, 'state' => 1,
    ], 'owner_id');
    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $negocio, 'state' => 1,
    ]);

    $comprador = User::factory()->create(['rol' => 1]);
    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1,
    ], 'buyer_id');

    DB::table('payment_methods')->insertOrIgnore([
        'methods_id' => 2, 'name' => 'Tarjeta', 'state' => 1,
    ]);

    $orderId = DB::table('orderssales')->insertGetId([
        'buyer_id' => $buyerId, 'busines_id' => $negocio, 'methods_id' => 2,
        'subtotal' => 20000, 'domicilio' => 3000, 'total' => 23000,
        'sale_date' => now(), 'state' => 1,
        // Esperando a que el banco conteste: es el estado en el que el aviso
        // de Bold hace nacer el pedido para la tienda.
        'payment_state' => OrdersSales::ESPERANDO_PAGO,
    ], 'orderSales_id');

    $pago = Payment::create([
        'orderSales_id' => $orderId, 'methods_id' => 2, 'provider' => 'bold',
        'provider_payment_id' => 'TX-REPETIDO', 'amount' => 23000,
        'subtotal' => 20000, 'total' => 23000, 'domicilio' => 3000,
        'payment_status' => 0, 'status' => 'running',
    ]);

    return ['orderId' => (int) $orderId, 'pago' => $pago];
}

/** Un aviso firmado como lo firma Bold en pruebas: HMAC sobre el base64. */
function avisoDeBoldFirmado(array $cuerpo): array
{
    $crudo = json_encode($cuerpo);
    $secreto = (string) config('services.bold.webhook_secret', '');

    return [$crudo, hash_hmac('sha256', base64_encode($crudo), $secreto)];
}

function mandarAvisoDeBold(array $cuerpo): TestResponse
{
    [$crudo, $firma] = avisoDeBoldFirmado($cuerpo);

    return test()->call(
        'POST', '/v1/webhooks/bold', [], [], [],
        ['HTTP_X_BOLD_SIGNATURE' => $firma, 'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json'],
        $crudo
    );
}

function cuerpoDeAprobacionDeBold(string $id = 'NOTIF-1'): array
{
    return [
        'id' => $id,
        'type' => 'SALE_APPROVED',
        'subject' => 'TX-REPETIDO',
        'data' => ['payment_id' => 'TX-REPETIDO', 'metadata' => ['reference' => 'TX-REPETIDO']],
    ];
}

beforeEach(function () {
    config(['services.bold.entorno' => 'pruebas', 'services.bold.webhook_secret' => '']);
});

it('el mismo aviso dos veces deja un solo evento y un solo anuncio', function () {
    Event::fake([OrderCreated::class]);

    $e = pedidoEsperandoAlBanco();

    mandarAvisoDeBold(cuerpoDeAprobacionDeBold())->assertOk();

    $primera = PaymentEvent::count();
    expect($primera)->toBe(1);
    expect(OrdersSales::find($e['orderId'])->payment_state)->toBe('paid');

    // Y otra vez, byte por byte igual: es lo que hace Bold al reintentar.
    $r = mandarAvisoDeBold(cuerpoDeAprobacionDeBold())->assertOk();

    expect($r->json('duplicated'))->toBeTrue();
    expect(PaymentEvent::count())->toBe(1);

    /*
     * UN SOLO ANUNCIO. Es el daño que de verdad importa: dos anuncios son dos
     * pedidos en la pantalla de la tienda y dos avisos al teléfono del
     * tendero por un pedido que existe una vez.
     */
    Event::assertDispatchedTimes(OrderCreated::class, 1);
});

it('un aviso SIN id tambien se descarta, que antes no', function () {
    /*
     * El guardia era `if ($notificationId && ...)`: sin llave, se procesaba
     * cada vez que llegara. La llave de reserva es el hash del cuerpo crudo.
     *
     * UN HALLAZGO DE PROBAR ESTA RED ROMPIENDOLA: si se devuelve ese
     * `$notificationId &&` al controlador, esta prueba **sigue pasando**. No es
     * un hueco, es el diseño funcionando — el segundo insert choca contra el
     * indice unico y el `catch` devuelve el mismo `duplicated`. La comprobacion
     * previa es el camino rapido; la GARANTIA es la base. Lo que si pone roja
     * esta prueba es quitar el indice, y eso lo cubre la prueba de abajo.
     */
    Event::fake([OrderCreated::class]);

    pedidoEsperandoAlBanco();

    $sinId = cuerpoDeAprobacionDeBold();
    unset($sinId['id']);

    mandarAvisoDeBold($sinId)->assertOk();
    expect(PaymentEvent::count())->toBe(1);

    $r = mandarAvisoDeBold($sinId)->assertOk();

    expect($r->json('duplicated'))->toBeTrue();
    expect(PaymentEvent::count())->toBe(1);

    // La llave que se guardó es el hash, no un nulo.
    expect(PaymentEvent::first()->notification_id)->toStartWith('sha256:');

    Event::assertDispatchedTimes(OrderCreated::class, 1);
});

it('dos avisos DISTINTOS si entran los dos', function () {
    /*
     * La contrapartida imprescindible: una idempotencia que descarte de más
     * esconde cobros. Dos notificaciones con llave distinta son dos avisos.
     */
    pedidoEsperandoAlBanco();

    mandarAvisoDeBold(cuerpoDeAprobacionDeBold('NOTIF-1'))->assertOk();
    mandarAvisoDeBold(cuerpoDeAprobacionDeBold('NOTIF-2'))->assertOk();

    expect(PaymentEvent::count())->toBe(2);
});

it('la llave tiene indice unico, para que no dependa de la comprobacion', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('El indice se comprueba en el flujo de MySQL.');
    }

    $unicos = collect(DB::select('show index from payment_events'))
        ->where('Column_name', 'notification_id')
        ->where('Non_unique', 0);

    expect($unicos)->not->toBeEmpty();

    /*
     * Y QUE DE VERDAD RECHACE, no solo que exista: es la diferencia entre
     * comprobar y garantizar. Si dos entregas simultáneas pasan la
     * comprobación, la base para a la segunda.
     */
    PaymentEvent::create([
        'provider' => 'bold', 'notification_id' => 'MISMA',
        'event_type' => 'x', 'reference_id' => 'r', 'payload' => [], 'received_at' => now(),
    ]);

    expect(fn () => PaymentEvent::create([
        'provider' => 'bold', 'notification_id' => 'MISMA',
        'event_type' => 'x', 'reference_id' => 'r', 'payload' => [], 'received_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('confirmar un pago dos veces no anuncia dos veces, y es lo que hoy nos salva', function () {
    /*
     * ESTA ES LA PRUEBA QUE PROTEGE EL RESTO.
     *
     * Toda la discusión del índice único es defensa en profundidad: un
     * duplicado que se colara tampoco haría daño, porque `confirmar()` mira
     * `esperandoPago()` ANTES de marcar pagado y en la segunda pasada ya no
     * está esperando. Eso NO estaba fijado por ninguna prueba, así que estaba
     * a un refactor de desaparecer —y el día que desapareciera, el síntoma
     * sería un pedido anunciado dos veces, no un error—.
     */
    Event::fake([OrderCreated::class]);

    $e = pedidoEsperandoAlBanco();
    $pedido = OrdersSales::find($e['orderId']);

    expect(ConfirmacionDePago::confirmar($pedido))->toBeTrue();
    expect(ConfirmacionDePago::confirmar($pedido->fresh()))->toBeFalse();

    Event::assertDispatchedTimes(OrderCreated::class, 1);
});
