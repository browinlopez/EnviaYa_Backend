<?php

use App\Models\Notification;
use App\Models\Order\OrdersSales;
use App\Services\RecordatorioDeProgramados;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
 * LOS PROGRAMADOS, A LOS DOS Y DOS VECES; Y EL CÓDIGO PARA RECOGER.
 *
 * Media hora antes y un cuarto de hora antes le llega un aviso a la tienda y
 * otro al cliente. Y un pedido para recoger solo se entrega con el código que
 * el cliente ve en su app: la prueba de que quien está en el mostrador es el
 * dueño del pedido.
 */
function pedidoParaRecordar(array $cambios = []): array
{
    $e = escenarioDeTope(null);
    DB::table('orderssales')->where('orderSales_id', $e['orderId'])->update($cambios);
    $pedido = OrdersSales::find($e['orderId']);

    return $e + ['pedido' => $pedido, 'comprador' => $pedido->buyer->user];
}

function recordatoriosDe($usuario, string $tipo): int
{
    return Notification::where('user_id', $usuario->user_id)->where('tipo', $tipo)->count();
}

beforeEach(fn () => Queue::fake());

it('avisa a la tienda y al cliente a los 30 y a los 15 minutos, una vez cada uno', function () {
    $e = pedidoParaRecordar([
        'is_scheduled' => 1, 'pickup' => 1, 'state' => 2, 'domiciliary_id' => null,
        'delivery_date' => now()->addMinutes(25), 'pin_entrega' => '427',
    ]);

    expect(RecordatorioDeProgramados::recordar())->toBe(1)
        ->and(recordatoriosDe($e['tendero'], 'pedido_programado_pronto'))->toBe(1)
        ->and(recordatoriosDe($e['comprador'], 'recordatorio_programado'))->toBe(1)
        ->and(RecordatorioDeProgramados::recordar())->toBe(0);

    // Once minutos después falta menos de un cuarto de hora: el segundo aviso.
    $this->travel(11)->minutes();
    expect(RecordatorioDeProgramados::recordar())->toBe(1)
        ->and(recordatoriosDe($e['tendero'], 'pedido_programado_15'))->toBe(1)
        ->and(recordatoriosDe($e['comprador'], 'recordatorio_programado_15'))->toBe(1)
        ->and(RecordatorioDeProgramados::recordar())->toBe(0);

    // Al cliente que recoge, el código va en el aviso.
    expect(Notification::where('user_id', $e['comprador']->user_id)
        ->where('tipo', 'recordatorio_programado_15')->value('message'))->toContain('427');
});

it('si se programó con menos de 15 minutos, sale un solo aviso y no dos', function () {
    $e = pedidoParaRecordar([
        'is_scheduled' => 1, 'state' => 2, 'delivery_date' => now()->addMinutes(10),
    ]);

    expect(RecordatorioDeProgramados::recordar())->toBe(1)
        ->and(recordatoriosDe($e['comprador'], 'recordatorio_programado'))->toBe(0)
        ->and(recordatoriosDe($e['comprador'], 'recordatorio_programado_15'))->toBe(1)
        ->and(RecordatorioDeProgramados::recordar())->toBe(0);
});

it('el cliente ve su código para recoger y la tienda no', function () {
    $e = pedidoParaRecordar(['pickup' => 1, 'state' => 2, 'domiciliary_id' => null, 'pin_entrega' => '305']);

    Sanctum::actingAs($e['comprador']);
    $this->getJson("/v1/orders/{$e['orderId']}/pin")
        ->assertOk()
        ->assertJsonPath('data.pin', '305')
        ->assertJsonPath('data.para_recoger', true);

    Sanctum::actingAs($e['tendero']);
    $this->getJson("/v1/negocio/pedidos/{$e['orderId']}/pin")
        ->assertOk()
        ->assertJsonPath('data.pin', null);
});

it('la tienda no entrega un pedido para recoger sin el código correcto, y no se bloquea', function () {
    $e = pedidoParaRecordar([
        'pickup' => 1, 'state' => 2, 'domiciliary_id' => null,
        'pin_entrega' => '305', 'ready_at' => now(),
    ]);
    Sanctum::actingAs($e['tendero']);

    $this->putJson('/v1/orders/update', ['order_id' => $e['orderId'], 'state' => 4])
        ->assertStatus(422)->assertJsonPath('reason', 'pin_requerido');

    foreach (range(1, 6) as $_) {
        $this->putJson('/v1/orders/update', ['order_id' => $e['orderId'], 'state' => 4, 'pin' => '999'])
            ->assertStatus(422)->assertJsonPath('reason', 'pin_incorrecto');
    }
    expect((int) OrdersSales::find($e['orderId'])->state)->toBe(2);

    $this->putJson('/v1/orders/update', ['order_id' => $e['orderId'], 'state' => 4, 'pin' => '305'])
        ->assertOk();
    expect((int) OrdersSales::find($e['orderId'])->state)->toBe(4);
});
