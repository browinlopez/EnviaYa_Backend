<?php

use App\Jobs\EnviarAvisoPush;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

// El cliente arma su lista, el negocio la ajusta y confirma, y se cobra lo confirmado.
function preordenDelCliente(): array
{
    $e = escenarioPedido();
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);
    $tendero = User::factory()->create(['rol' => 2, 'name' => 'Dueño']);
    $ownerId = DB::table('owner')->insertGetId(['user_id' => $tendero->user_id, 'state' => 1], 'owner_id');
    DB::table('owner_busines')->insert(['owner_id' => $ownerId, 'busines_id' => $e['businessId'], 'state' => 1]);

    $tarjeta = [
        'type' => 'preorder', 'preorder_id' => 1795538813258, 'de' => 'cliente', 'status' => 'pending',
        'business' => ['business_id' => $e['businessId'], 'name' => 'Tienda del Pedido'],
        'items' => [['product_id' => $e['productId'], 'name' => 'Arroz', 'quantity' => 3, 'price' => 10000]],
        'total' => 30000,
    ];

    return [...$e, 'tendero' => $tendero, 'tarjeta' => $tarjeta];
}

function mandarPreorden(array $e, array $tarjeta, User $quien, User $a): TestResponse
{
    Sanctum::actingAs($quien);

    return test()->postJson('/v1/chats/send-message', [
        'user_id' => $quien->user_id,
        'recipient_id' => $a->user_id,
        'busines_id' => $e['businessId'],
        'content' => json_encode($tarjeta),
    ]);
}

function confirmarComoNegocio(array $e, int $mensaje, array $cambios = []): TestResponse
{
    Sanctum::actingAs($e['tendero']);

    return test()->postJson('/v1/chats/updateMessage', [
        'message_id' => $mensaje,
        'content' => array_replace([...$e['tarjeta'], 'status' => 'confirmed'], $cambios),
    ]);
}

beforeEach(fn () => Queue::fake());

test('el cliente manda su lista y al negocio le llega «Te pidió»', function () {
    $e = preordenDelCliente();

    mandarPreorden($e, $e['tarjeta'], $e['user'], $e['tendero'])->assertCreated();

    Queue::assertPushed(EnviarAvisoPush::class, fn ($job) => (int) $job->userId === (int) $e['tendero']->user_id
        && str_starts_with($job->cuerpo, 'Te pidió: 1 producto'));
});

test('nadie manda una tarjeta en nombre del otro lado', function () {
    $e = preordenDelCliente();

    mandarPreorden($e, $e['tarjeta'], $e['tendero'], $e['user'])->assertForbidden();
    mandarPreorden($e, [...$e['tarjeta'], 'de' => 'negocio'], $e['user'], $e['tendero'])->assertForbidden();
});

test('el negocio ajusta y confirma, y al cliente le llega que ya puede pagar', function () {
    $e = preordenDelCliente();
    $id = mandarPreorden($e, $e['tarjeta'], $e['user'], $e['tendero'])->json('data.message_id');

    $items = [[...$e['tarjeta']['items'][0], 'quantity' => 2, 'price' => 8000]];
    confirmarComoNegocio($e, $id, ['items' => $items])->assertOk();

    $guardada = json_decode(DB::table('messages')->where('message_id', $id)->value('content'), true);
    expect($guardada['status'])->toBe('confirmed')
        ->and((float) $guardada['total'])->toBe(16000.0)
        ->and($guardada['ajustada'])->toBeTrue();

    Queue::assertPushed(EnviarAvisoPush::class, fn ($job) => (int) $job->userId === (int) $e['user']->user_id
        && $job->datos['tipo'] === 'preorden_respondida'
        && str_contains($job->cuerpo, 'Ya puedes pagar'));
});

test('el negocio no sube cantidades ni agrega lo que no se pidió', function () {
    $e = preordenDelCliente();
    $id = mandarPreorden($e, $e['tarjeta'], $e['user'], $e['tendero'])->json('data.message_id');

    confirmarComoNegocio($e, $id, ['items' => [[...$e['tarjeta']['items'][0], 'quantity' => 9]]])
        ->assertStatus(422)->assertJsonPath('reason', 'cantidad_invalida');
    confirmarComoNegocio($e, $id, ['items' => [[...$e['tarjeta']['items'][0], 'product_id' => 999999]]])
        ->assertStatus(422)->assertJsonPath('reason', 'producto_no_pedido');
});

test('el cliente no confirma su propia lista, solo la retira', function () {
    $e = preordenDelCliente();
    $id = mandarPreorden($e, $e['tarjeta'], $e['user'], $e['tendero'])->json('data.message_id');

    Sanctum::actingAs($e['user']);
    $this->postJson('/v1/chats/updateMessage', [
        'message_id' => $id, 'content' => [...$e['tarjeta'], 'status' => 'confirmed'],
    ])->assertForbidden();
    $this->postJson('/v1/chats/updateMessage', [
        'message_id' => $id, 'content' => [...$e['tarjeta'], 'status' => 'cancelled'],
    ])->assertOk();
});

test('sin confirmar no se paga, y la respuesta dice a qué chat volver', function () {
    $e = preordenDelCliente();
    $envio = mandarPreorden($e, $e['tarjeta'], $e['user'], $e['tendero']);

    Sanctum::actingAs($e['user']);
    $this->postJson('/v1/orders/orders', cuerpoPedido($e, ['preorder_message_id' => $envio->json('data.message_id')]))
        ->assertStatus(409)
        ->assertJsonPath('reason', 'preorden_sin_confirmar')
        ->assertJsonPath('chat_id', $envio->json('chat_id'));
});

test('confirmada, se cobra al precio pactado y no se paga dos veces', function () {
    $e = preordenDelCliente();
    $id = mandarPreorden($e, $e['tarjeta'], $e['user'], $e['tendero'])->json('data.message_id');
    confirmarComoNegocio($e, $id, ['items' => [[...$e['tarjeta']['items'][0], 'quantity' => 2, 'price' => 8000]]]);

    Sanctum::actingAs($e['user']);
    $cuerpo = cuerpoPedido($e, [
        'preorder_message_id' => $id,
        'products' => [['product_id' => $e['productId'], 'amount' => 2]],
    ]);

    $this->postJson('/v1/orders/cotizar', [...$cuerpo, 'busines_id' => $e['businessId']])
        ->assertOk()->assertJsonPath('subtotal', 16000);

    $this->postJson('/v1/orders/orders', $cuerpo)->assertSuccessful();
    expect((float) DB::table('orderssales')->latest('orderSales_id')->value('subtotal'))->toBe(16000.0);

    $this->postJson('/v1/orders/orders', $cuerpo)->assertStatus(409)->assertJsonPath('reason', 'preorden_pagada');
});

test('lo que se paga es exactamente lo confirmado', function () {
    $e = preordenDelCliente();
    $id = mandarPreorden($e, $e['tarjeta'], $e['user'], $e['tendero'])->json('data.message_id');
    confirmarComoNegocio($e, $id);

    Sanctum::actingAs($e['user']);
    $this->postJson('/v1/orders/orders', cuerpoPedido($e, [
        'preorder_message_id' => $id,
        'products' => [['product_id' => $e['productId'], 'amount' => 5]],
    ]))->assertStatus(409)->assertJsonPath('reason', 'preorden_distinta');
});

test('otro comprador no paga una preorden ajena', function () {
    $e = preordenDelCliente();
    $id = mandarPreorden($e, $e['tarjeta'], $e['user'], $e['tendero'])->json('data.message_id');
    confirmarComoNegocio($e, $id);

    $otro = escenarioPedido();
    $this->postJson('/v1/orders/orders', cuerpoPedido($otro, [
        'busines_id' => $e['businessId'],
        'preorder_message_id' => $id,
        'products' => [['product_id' => $e['productId'], 'amount' => 3]],
    ]))->assertStatus(409)->assertJsonPath('reason', 'preorden_ajena');
});

test('cada uno ve en su inicio lo que le toca', function () {
    $e = preordenDelCliente();
    $id = mandarPreorden($e, $e['tarjeta'], $e['user'], $e['tendero'])->json('data.message_id');

    Sanctum::actingAs($e['tendero']);
    $this->getJson('/v1/negocio/preordenes/pendientes')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.que', 'por_confirmar');

    Sanctum::actingAs($e['user']);
    $this->getJson('/v1/preordenes/pendientes')->assertJsonPath('data.0.que', 'esperando_al_negocio');

    confirmarComoNegocio($e, $id);

    Sanctum::actingAs($e['tendero']);
    $this->getJson('/v1/negocio/preordenes/pendientes')->assertJsonCount(0, 'data');

    Sanctum::actingAs($e['user']);
    $this->getJson('/v1/preordenes/pendientes')
        ->assertJsonPath('data.0.que', 'por_pagar')
        ->assertJsonPath('data.0.con.nombre', 'Tienda del Pedido');
});

test('la preorden que arma el negocio también se cobra a su precio', function () {
    $e = preordenDelCliente();
    $delNegocio = [...$e['tarjeta'], 'de' => 'negocio',
        'items' => [[...$e['tarjeta']['items'][0], 'price' => 7000]], 'total' => 21000];
    $id = mandarPreorden($e, $delNegocio, $e['tendero'], $e['user'])->json('data.message_id');

    Sanctum::actingAs($e['user']);
    $this->postJson('/v1/chats/updateMessage', [
        'message_id' => $id, 'content' => [...$delNegocio, 'status' => 'confirmed'],
    ])->assertOk();

    $this->postJson('/v1/orders/orders', cuerpoPedido($e, ['preorder_message_id' => $id]))->assertSuccessful();
    expect((float) DB::table('orderssales')->latest('orderSales_id')->value('subtotal'))->toBe(21000.0);
});
