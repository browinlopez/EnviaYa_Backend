<?php

use App\Jobs\EnviarAvisoPush;
use App\Models\Order\OrdersSales;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/**
 * QUE EL COMPRADOR SE ENTERE DE SU PROPIO PEDIDO, CON LA APP CERRADA.
 *
 * EL FALLO QUE ESTO CAZA, y que estuvo vivo desde el primer día:
 *
 * Los avisos de pedido se guardaban en la campana y se anunciaban por
 * websocket. El websocket solo llega si la app está ABIERTA y conectada, así
 * que quien cerraba la app no se enteraba de que le habían aceptado el pedido,
 * ni de que el domiciliario iba en camino, ni de que había llegado. Y de las
 * cuatro transiciones, TRES no avisaban al comprador ni por websocket: sólo
 * existía la de cancelación.
 *
 * Lo que se fija acá es que cada paso del pedido encole su push. Sin estas
 * pruebas, el día que alguien reordene `updateStatus` los avisos desaparecen en
 * silencio y no hay nada que falle: la app funciona igual, sólo que muda.
 */

/** El escenario compartido, más el comprador, que el ayudante no devuelve. */
function pedidoConGente(): array
{
    $e = escenarioDeTope(null);
    $pedido = OrdersSales::find($e['orderId']);

    return $e + [
        'comprador' => $pedido->buyer->user,
        'pedido'    => $pedido,
    ];
}

beforeEach(function () {
    Queue::fake();
});

it('cuando la tienda acepta, al comprador le suena el telefono', function () {
    $e = pedidoConGente();

    // El ayudante deja el pedido ya aceptado; para esta transición hace falta
    // que esté recién hecho.
    DB::table('orderssales')->where('orderSales_id', $e['orderId'])->update(['state' => 1]);

    Sanctum::actingAs($e['tendero']);

    $this->putJson('/v1/orders/update', [
        'order_id' => $e['orderId'],
        'state'    => 2,
    ])->assertOk();

    Queue::assertPushed(
        EnviarAvisoPush::class,
        fn ($job) => (int) $job->userId === (int) $e['comprador']->user_id
            && $job->datos['tipo'] === 'pedido_aceptado',
    );
});

it('cuando sale el domiciliario se avisa a los DOS, y a cada uno lo suyo', function () {
    $e = pedidoConGente();

    Sanctum::actingAs($e['tendero']);

    $this->putJson('/v1/orders/update', [
        'order_id' => $e['orderId'],
        'state'    => 3,
        'user_id'  => $e['repartidor']->user_id,
    ])->assertOk();

    // El repartidor: le acaban de asignar un trabajo.
    Queue::assertPushed(
        EnviarAvisoPush::class,
        fn ($job) => (int) $job->userId === (int) $e['repartidor']->user_id
            && $job->datos['tipo'] === 'entrega_asignada',
    );

    /*
     * Y el comprador, que es lo que faltaba: antes de esto, por esta transición
     * sólo se avisaba al repartidor y el comprador se quedaba mirando «la
     * tienda está preparando» mientras el domiciliario llegaba a su puerta.
     */
    Queue::assertPushed(
        EnviarAvisoPush::class,
        fn ($job) => (int) $job->userId === (int) $e['comprador']->user_id
            && $job->datos['tipo'] === 'pedido_en_camino',
    );
});

it('al entregar se avisa al comprador', function () {
    $e = pedidoConGente();

    DB::table('orderssales')->where('orderSales_id', $e['orderId'])->update([
        'state'          => 3,
        'domiciliary_id' => $e['domiId'],
    ]);

    Sanctum::actingAs($e['repartidor']);

    $this->putJson('/v1/orders/update', [
        'order_id' => $e['orderId'],
        'state'    => 4,
    ])->assertOk();

    Queue::assertPushed(
        EnviarAvisoPush::class,
        fn ($job) => (int) $job->userId === (int) $e['comprador']->user_id
            && $job->datos['tipo'] === 'pedido_entregado',
    );
});

it('el aviso lleva el pedido, para que tocarlo abra ese pedido y no la lista', function () {
    $e = pedidoConGente();

    DB::table('orderssales')->where('orderSales_id', $e['orderId'])->update(['state' => 1]);

    Sanctum::actingAs($e['tendero']);

    $this->putJson('/v1/orders/update', ['order_id' => $e['orderId'], 'state' => 2])->assertOk();

    /*
     * Los datos van como CADENAS a propósito: los dos proveedores exigen que
     * el bloque de datos sea texto plano, y un entero se rechaza en el envío.
     * Es el tipo de detalle que sólo se descubre en producción.
     */
    Queue::assertPushed(
        EnviarAvisoPush::class,
        fn ($job) => ($job->datos['order_id'] ?? null) === (string) $e['orderId'],
    );
});

it('el titulo dice lo que pasa, no la marca', function () {
    $e = pedidoConGente();

    DB::table('orderssales')->where('orderSales_id', $e['orderId'])->update(['state' => 1]);

    Sanctum::actingAs($e['tendero']);

    $this->putJson('/v1/orders/update', ['order_id' => $e['orderId'], 'state' => 2])->assertOk();

    /*
     * En la barra de notificaciones lo único que se lee entero es el título, y
     * ahí se compite con veinte apps. «VeciPa'Ya» no dice nada que el icono no
     * diga ya.
     */
    Queue::assertPushed(
        EnviarAvisoPush::class,
        fn ($job) => $job->titulo === 'Pedido aceptado'
            && str_contains($job->cuerpo, 'Tienda'),
    );
});

it('una promocion no se envia dos veces', function () {
    /*
     * `PromocionesDeLaTienda` manda el push en BLOQUE, con todos los tokens de
     * una vez y con el nombre de la tienda como título. Como además guarda el
     * aviso con `Avisos::para`, que desde ahora también empuja, había que
     * apagar uno de los dos caminos: sin `push: false` cada cliente afiliado
     * recibiría la misma promoción dos veces seguidas.
     */
    $codigo = file_get_contents(app_path('Services/PromocionesDeLaTienda.php'));

    expect($codigo)->toContain("push: false");
});

/* =========================================================================
   QUE LA TIENDA SE ENTERE DE QUE LE ENTRO UN PEDIDO

   Es el aviso mas grave de todos los que faltaban. Un comprador sin aviso se
   impacienta, pero su pedido se prepara igual; un TENDERO sin aviso no prepara
   nada y el pedido se queda quieto. Solo salia por websocket, o sea que con el
   telefono en el bolsillo no llegaba.
   ======================================================================== */

it('un pedido nuevo le suena al dueño de la tienda', function () {
    $e = pedidoConGente();

    \App\Services\AvisoDePedidoNuevo::anunciar($e['pedido']);

    $duenio = \Illuminate\Support\Facades\DB::table('owner')
        ->join('owner_busines', 'owner.owner_id', '=', 'owner_busines.owner_id')
        ->where('owner_busines.busines_id', $e['businessId'])
        ->value('owner.user_id');

    Queue::assertPushed(
        EnviarAvisoPush::class,
        fn ($job) => (int) $job->userId === (int) $duenio
            && $job->datos['tipo'] === 'pedido_nuevo',
    );
});

it('el aviso del pedido nuevo dice cuanto es, sin abrir nada', function () {
    $e = pedidoConGente();

    \App\Services\AvisoDePedidoNuevo::anunciar($e['pedido']);

    /*
     * El importe va en el propio aviso a proposito: es lo que deja decidir si
     * vale la pena dejar lo que se esta haciendo sin entrar a mirar.
     */
    Queue::assertPushed(
        EnviarAvisoPush::class,
        fn ($job) => str_contains($job->cuerpo, '#' . $e['orderId'])
            && str_contains($job->cuerpo, '$'),
    );
});

it('los dos caminos por los que nace un pedido avisan igual', function () {
    /*
     * Un pedido pasa a existir para la tienda de dos formas: contra entrega al
     * crearlo, y con tarjeta cuando la pasarela confirma —que puede ser
     * minutos despues, con la app de todos cerrada—. Si cada sitio lo hiciera
     * por su cuenta, uno acabaria sin el push y el fallo solo aparecerian en
     * la mitad de los pedidos.
     */
    foreach ([
        'app/Http/Controllers/Order/OrderController.php',
        'app/Services/ConfirmacionDePago.php',
    ] as $archivo) {
        expect(file_get_contents(base_path($archivo)))
            ->toContain('AvisoDePedidoNuevo::anunciar');
    }
});
