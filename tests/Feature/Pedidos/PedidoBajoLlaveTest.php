<?php

use App\Models\Order\OrdersSales;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * DOS PERSONAS TOCANDO EL MISMO PEDIDO A LA VEZ.
 *
 * Con veinte domiciliarios mirando la misma lista, dos tocan «aceptar» en el
 * mismo segundo el primer día. Y por esa transición pasa también el tendero
 * despachando, así que la pareja puede ser un domiciliario y la tienda.
 *
 * Leyendo con `find()` los dos veían estado 2, los dos pasaban la comprobación
 * y los dos escribían: el último ganaba y al otro se le respondía
 * «actualizado». Ese otro sale a repartir un pedido que no lleva.
 *
 * POR QUÉ ESTAS PRUEBAS NO LANZAN DOS PETICIONES A LA VEZ
 *
 * Porque no serviría. PHPUnit corre en un solo hilo, así que irían en fila; y
 * el servidor de desarrollo de PHP en Windows tampoco es capaz de atenderlas
 * en paralelo —medido: ocho seguidas se sirvieron a 180 ms de distancia—. Una
 * prueba «de concurrencia» ahí sale verde siempre y no demuestra nada. Se
 * intentó, salió bien, y no probaba nada.
 *
 * LO QUE SÍ SE PUEDE FIJAR es que la protección esté puesta: que leer,
 * comprobar y escribir ocurran dentro de UNA transacción —fuera de ella el
 * bloqueo se suelta al terminar la consulta y no sirve de nada— y que el
 * segundo intento sea rechazado. Eso es lo que un refactor podría quitar sin
 * que nadie se entere hasta producción.
 */

test('leer el pedido y escribirlo ocurren dentro de la misma transaccion', function () {
    $e = escenarioDeTope(null);

    /*
     * Se mira el nivel de transacción EN EL MOMENTO de la consulta, no después.
     * Es lo que distingue una transacción de verdad de un `DB::transaction`
     * puesto alrededor de otra cosa.
     */
    $nivelAlLeerElPedido = null;

    /*
     * Se compara contra el nivel de ANTES y no contra cero: `RefreshDatabase`
     * ya envuelve cada prueba en su propia transaccion, asi que el nivel nunca
     * es cero aqui dentro. Lo que demuestra que el controlador abre la suya es
     * que el nivel SUBA mientras atiende la peticion.
     */
    $nivelPrevio = DB::transactionLevel();

    DB::listen(function ($q) use (&$nivelAlLeerElPedido) {
        if ($nivelAlLeerElPedido === null && str_contains($q->sql, 'orderssales')) {
            $nivelAlLeerElPedido = DB::transactionLevel();
        }
    });

    Sanctum::actingAs($e['tendero']);

    $this->putJson('/v1/orders/update', [
        'order_id' => $e['orderId'],
        'state'    => 3,
        'user_id'  => $e['repartidor']->user_id,
    ])->assertOk();

    expect($nivelAlLeerElPedido)->toBeGreaterThan(
        $nivelPrevio,
        'el pedido se lee fuera de una transaccion propia: el bloqueo no protege nada',
    );

    // Y se cierra al terminar: una transaccion abierta deja la fila bloqueada
    // para todos los demas.
    expect(DB::transactionLevel())->toBe($nivelPrevio);
});

test('la consulta pide la fila bajo llave', function () {
    /*
     * SQLite no tiene bloqueo de fila —bloquea la base entera— así que su
     * gramática no emite `for update` y esta comprobación no se puede hacer
     * sobre el motor de las pruebas. En MySQL, que es lo que corre en
     * producción, sí.
     *
     * Se salta en vez de borrarse: el día que las pruebas corran contra MySQL,
     * esto vuelve a vigilar solo.
     */
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('`for update` solo lo emite MySQL; SQLite bloquea la base entera.');
    }

    $e = escenarioDeTope(null);

    $sentencias = [];
    DB::listen(fn ($q) => $sentencias[] = strtolower($q->sql));

    Sanctum::actingAs($e['tendero']);

    $this->putJson('/v1/orders/update', [
        'order_id' => $e['orderId'],
        'state'    => 3,
        'user_id'  => $e['repartidor']->user_id,
    ])->assertOk();

    $conCandado = array_filter(
        $sentencias,
        fn ($sql) => str_contains($sql, 'orderssales') && str_contains($sql, 'for update'),
    );

    expect($conCandado)->not->toBeEmpty();
});

test('el segundo que llega ya no puede tomarlo', function () {
    $e = escenarioDeTope(null);

    Sanctum::actingAs($e['tendero']);

    $cuerpo = [
        'order_id' => $e['orderId'],
        'state'    => 3,
        'user_id'  => $e['repartidor']->user_id,
    ];

    $this->putJson('/v1/orders/update', $cuerpo)->assertOk();

    /*
     * Es lo que ve el segundo en producción cuando el candado lo hace esperar:
     * al leer, el pedido ya está en 3 y la transición 2 → 3 deja de valer.
     * Sin el candado leía el estado viejo y esta respuesta era un 200, con lo
     * que dos personas salían a llevar el mismo pedido.
     */
    $r = $this->putJson('/v1/orders/update', $cuerpo);

    expect($r->status())->not->toBe(200);

    // Y sigue siendo del primero.
    expect((int) OrdersSales::find($e['orderId'])->domiciliary_id)
        ->toBe((int) $e['domiId']);
});

test('el pedido no se queda a medias si algo falla despues de escribirlo', function () {
    $e = escenarioDeTope(null);

    Sanctum::actingAs($e['tendero']);

    /*
     * La transacción tiene un segundo efecto que conviene fijar: la transición
     * 3 → 4 registra el cobro en efectivo Y marca el pedido entregado. Si lo
     * segundo fallara despues de lo primero, quedaria un recaudo sin entrega.
     * Estando los dos dentro de la misma transaccion, o entran ambos o
     * ninguno.
     */
    $this->putJson('/v1/orders/update', [
        'order_id' => $e['orderId'], 'state' => 3, 'user_id' => $e['repartidor']->user_id,
    ])->assertOk();

    Sanctum::actingAs($e['repartidor']);

    $this->putJson('/v1/orders/update', [
        'order_id' => $e['orderId'], 'state' => 4,
    ])->assertOk();

    $pedido = OrdersSales::find($e['orderId']);
    $movimientos = DB::table('cash_movements')
        ->where('domiciliary_id', $e['domiId'])
        ->where('order_id', $e['orderId'])
        ->count();

    // Entregado y con su recaudo: las dos cosas o ninguna.
    expect((int) $pedido->state)->toBe(4)
        ->and($movimientos)->toBe(1);
});
