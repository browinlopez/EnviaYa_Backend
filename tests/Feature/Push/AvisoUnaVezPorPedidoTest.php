<?php

use App\Models\User;
use App\Services\Avisos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * Lo que en la vida de un pedido pasa una vez se avisa una vez: cinco
 * «Tu pedido fue entregado» del mismo pedido en la campana eran ruido.
 */
beforeEach(fn () => Queue::fake());

test('el mismo aviso de entrega para el mismo pedido no se repite', function () {
    $u = User::factory()->create();

    Avisos::para($u->user_id, 'pedido_entregado', 'Entregado', ['order_id' => 204]);
    Avisos::para($u->user_id, 'pedido_entregado', 'Entregado', ['order_id' => 204]);
    Avisos::para($u->user_id, 'pedido_entregado', 'Entregado', ['order_id' => 205]);

    expect(DB::table('notifications')->where('user_id', $u->user_id)->count())->toBe(2);
});

test('lo que sí puede repetirse se sigue avisando', function () {
    $u = User::factory()->create();

    Avisos::para($u->user_id, 'pedido_soltado', 'Lo soltaron', ['order_id' => 204]);
    Avisos::para($u->user_id, 'pedido_soltado', 'Lo soltaron', ['order_id' => 204]);
    Avisos::para($u->user_id, 'mensaje_nuevo', 'Hola', ['chat_id' => 7]);
    Avisos::para($u->user_id, 'mensaje_nuevo', 'Hola', ['chat_id' => 7]);

    expect(DB::table('notifications')->where('user_id', $u->user_id)->count())->toBe(4);
});
