<?php

use App\Models\Notification;
use App\Models\Order\OrdersSales;
use App\Services\RecordatorioDeProgramados;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
 * LOS PEDIDOS PARA RECOGER Y LOS PROGRAMADOS, CON SUS AVISOS.
 *
 *  · Para recoger: la tienda dice «ya está listo» y al cliente le llega
 *    «ya puedes pasar»; cuando pasa, la tienda lo entrega ella (no hay
 *    domiciliario que lo cierre).
 *  · Programados: media hora antes se le recuerda a la tienda.
 */
function pedidoDeTope(array $cambios = []): array
{
    $e = escenarioDeTope(null);
    DB::table('orderssales')->where('orderSales_id', $e['orderId'])->update($cambios);
    $pedido = OrdersSales::find($e['orderId']);

    return $e + ['pedido' => $pedido, 'comprador' => $pedido->buyer->user];
}

beforeEach(function () {
    Queue::fake();
});

it('«ya está listo» le avisa al cliente que pase a recogerlo', function () {
    $e = pedidoDeTope(['pickup' => 1, 'state' => 2, 'domiciliary_id' => null]);

    Sanctum::actingAs($e['tendero']);
    $this->postJson("/v1/negocio/pedidos/{$e['orderId']}/listo")->assertOk();

    expect(OrdersSales::find($e['orderId'])->ready_at)->not->toBeNull();
    expect(Notification::where('user_id', $e['comprador']->user_id)
        ->where('tipo', 'pedido_listo_para_recoger')->count())->toBe(1);

    // Tocarlo dos veces no avisa dos veces.
    $this->postJson("/v1/negocio/pedidos/{$e['orderId']}/listo")->assertOk();
    expect(Notification::where('user_id', $e['comprador']->user_id)
        ->where('tipo', 'pedido_listo_para_recoger')->count())->toBe(1);
});

it('un pedido a domicilio no se marca «listo para recoger»', function () {
    $e = pedidoDeTope(['pickup' => 0, 'state' => 2]);

    Sanctum::actingAs($e['tendero']);
    $this->postJson("/v1/negocio/pedidos/{$e['orderId']}/listo")->assertStatus(422);
});

it('la tienda entrega en la mano un pedido para recoger', function () {
    $e = pedidoDeTope(['pickup' => 1, 'state' => 2, 'domiciliary_id' => null]);

    Sanctum::actingAs($e['tendero']);
    $this->putJson('/v1/orders/update', [
        'order_id' => $e['orderId'],
        'state' => 4,
    ])->assertOk();

    expect((int) OrdersSales::find($e['orderId'])->state)->toBe(4);
    expect(Notification::where('user_id', $e['comprador']->user_id)
        ->where('tipo', 'pedido_entregado')->exists())->toBeTrue();
});

it('aceptar un pedido para recoger no llama a los domiciliarios', function () {
    $e = pedidoDeTope(['pickup' => 1, 'state' => 1, 'domiciliary_id' => null]);

    Sanctum::actingAs($e['tendero']);
    $this->putJson('/v1/orders/update', [
        'order_id' => $e['orderId'],
        'state' => 2,
    ])->assertOk();

    expect(Notification::where('tipo', 'pedido_para_tomar')->exists())->toBeFalse();
});

it('media hora antes le recuerda a la tienda el pedido programado, una vez', function () {
    $e = pedidoDeTope([
        'is_scheduled' => 1,
        'pickup' => 1,
        'state' => 2,
        'domiciliary_id' => null,
        'delivery_date' => now()->addMinutes(25),
    ]);

    expect(RecordatorioDeProgramados::recordar())->toBe(1);
    expect(Notification::where('user_id', $e['tendero']->user_id)
        ->where('tipo', 'pedido_programado_pronto')->count())->toBe(1);

    expect(RecordatorioDeProgramados::recordar())->toBe(0);
});

it('no recuerda los que todavía faltan horas', function () {
    pedidoDeTope([
        'is_scheduled' => 1,
        'state' => 2,
        'delivery_date' => now()->addHours(5),
    ]);

    expect(RecordatorioDeProgramados::recordar())->toBe(0);
});
