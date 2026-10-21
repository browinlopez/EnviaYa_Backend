<?php

use App\Models\Order\OrdersSales;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * LA TIENDA CANCELA UN PEDIDO QUE NO PUEDE PREPARAR.
 *
 * No había por dónde. El único camino al estado 5 era `PUT /orders/{id}/cancel`,
 * que exige ser el comprador Y que el pedido no se haya aceptado todavía. El
 * tendero se da cuenta de que no tiene el producto justo DESPUÉS de aceptar
 * —cuando va al estante—, y a esas alturas no podía cancelar ni él ni el
 * cliente: el pedido se quedaba vivo, restando del inventario, con el cupo de
 * fiado comprometido y el cobro sin devolver.
 *
 * `pedidoCancelable()` vive en `tests/Pest.php`.
 */

/** El pedido ya aceptado por la tienda, que es cuando empieza el problema. */
function aceptadoPorLaTienda(array $t): void
{
    DB::table('orderssales')->where('orderSales_id', $t['id'])->update(['state' => 2]);
}

/* ===================================================================== */

test('la tienda cancela un pedido que ya había aceptado', function () {
    $t = pedidoCancelable();
    aceptadoPorLaTienda($t);

    Sanctum::actingAs($t['tendero']);

    $r = $this->putJson("/v1/negocio/pedidos/{$t['id']}/cancelar", [
        'motivo' => 'Se nos acabó el arroz',
    ])->assertOk();

    expect(OrdersSales::find($t['id'])->state)->toBe(5)
        ->and($r->json('motivo'))->toBe('Se nos acabó el arroz');

    // Y queda constancia de quién lo canceló, que es el tendero y no el cliente.
    $reporte = DB::table('status_history')->where('order_id', $t['id'])->first();

    expect($reporte)->not->toBeNull()
        ->and((int) $reporte->created_by)->toBe($t['tendero']->user_id)
        ->and($reporte->motivo)->toBe('Se nos acabó el arroz');
});

test('el motivo es OBLIGATORIO cuando cancela la tienda', function () {
    /*
     * Al comprador no se le exige —obligar a justificarse para cancelar lo
     * propio es una fricción fea—, pero acá el cliente no hizo nada y su
     * pedido desaparece: el motivo es lo único que le explica lo que pasó.
     */
    $t = pedidoCancelable();
    aceptadoPorLaTienda($t);

    Sanctum::actingAs($t['tendero']);

    $this->putJson("/v1/negocio/pedidos/{$t['id']}/cancelar")->assertStatus(422);
    $this->putJson("/v1/negocio/pedidos/{$t['id']}/cancelar", ['motivo' => 'no'])
        ->assertStatus(422);

    expect(OrdersSales::find($t['id'])->state)->toBe(2);
});

test('al cliente le llega el aviso CON el motivo', function () {
    $t = pedidoCancelable();
    aceptadoPorLaTienda($t);

    Sanctum::actingAs($t['tendero']);

    $this->putJson("/v1/negocio/pedidos/{$t['id']}/cancelar", [
        'motivo' => 'Se nos fue la luz y no podemos despachar',
    ])->assertOk();

    $aviso = DB::table('notifications')
        ->where('user_id', $t['comprador']->user_id)
        ->where('tipo', 'pedido_cancelado')
        ->first();

    expect($aviso)->not->toBeNull()
        ->and($aviso->message)->toContain('La tienda canceló')
        ->and($aviso->message)->toContain('Se nos fue la luz');
});

test('al tendero que cancela NO se le manda un aviso de su propio clic', function () {
    // La constancia ya quedó en `status_history`. Notificar a quien acaba de
    // pulsar el botón es ruido en su campana.
    $t = pedidoCancelable();
    aceptadoPorLaTienda($t);

    Sanctum::actingAs($t['tendero']);

    $this->putJson("/v1/negocio/pedidos/{$t['id']}/cancelar", ['motivo' => 'Sin producto'])
        ->assertOk();

    expect(DB::table('notifications')->where('user_id', $t['tendero']->user_id)->count())
        ->toBe(0);
});

test('un pedido YA DESPACHADO no lo cancela la tienda', function () {
    /*
     * A esas alturas hay alguien en la calle con la bolsa encima, y quién le
     * paga ese viaje es una decisión que nadie ha tomado. Un camino que deje a
     * un domiciliario a medio reparto sin saber qué se le debe es peor que no
     * tener camino.
     */
    $t = pedidoCancelable();
    DB::table('orderssales')->where('orderSales_id', $t['id'])->update(['state' => 3]);

    Sanctum::actingAs($t['tendero']);

    $this->putJson("/v1/negocio/pedidos/{$t['id']}/cancelar", ['motivo' => 'Ya no quiero'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'ya_despachado');

    expect(OrdersSales::find($t['id'])->state)->toBe(3);
});

test('una tienda NO cancela el pedido de otra tienda', function () {
    /*
     * Los identificadores son correlativos, así que esto se probaría con un
     * bucle. Responde 404 y no 403: decir «prohibido» sobre el número de otra
     * tienda confirmaría que ese pedido existe.
     */
    $t = pedidoCancelable();
    aceptadoPorLaTienda($t);

    $ajeno = tenderoConCatalogo();
    Sanctum::actingAs($ajeno['user']);

    $this->putJson("/v1/negocio/pedidos/{$t['id']}/cancelar", ['motivo' => 'Porque puedo'])
        ->assertStatus(404);

    expect(OrdersSales::find($t['id'])->state)->toBe(2);
});

test('cancelar dos veces no deshace dos veces', function () {
    /*
     * El comprador en su teléfono y el tendero en el panel es un caso de todos
     * los días. Sin el candado y la relectura, las dos cancelaciones pasarían
     * la comprobación de estado y las dos devolverían el inventario.
     */
    $t = pedidoCancelable();
    aceptadoPorLaTienda($t);

    Sanctum::actingAs($t['tendero']);

    $this->putJson("/v1/negocio/pedidos/{$t['id']}/cancelar", ['motivo' => 'Sin producto'])
        ->assertOk();

    $this->putJson("/v1/negocio/pedidos/{$t['id']}/cancelar", ['motivo' => 'Sin producto'])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'ya_cancelado');

    expect(DB::table('status_history')->where('order_id', $t['id'])->count())->toBe(1);
});

test('sin sesión de tienda no se cancela nada', function () {
    $t = pedidoCancelable();
    aceptadoPorLaTienda($t);

    // Entra el comprador, que por esta puerta no pasa: no es dueño de tienda.
    Sanctum::actingAs($t['comprador']);

    $this->putJson("/v1/negocio/pedidos/{$t['id']}/cancelar", ['motivo' => 'Déjame cancelar'])
        ->assertStatus(403);

    expect(OrdersSales::find($t['id'])->state)->toBe(2);
});
