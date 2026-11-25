<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
 * La pre-orden la escribe el tendero y la confirma o cancela el cliente.
 * El cliente solo puede cambiar su ESTADO; nada más, y solo si está en el chat.
 */
function chatConPreorden(): array
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);
    $tendero = User::factory()->create(['rol' => 2]);
    $cliente = User::factory()->create(['rol' => 1]);

    $chat = DB::table('chats')->insertGetId(['type' => 'private'], 'chat_id');
    DB::table('chat_participants')->insert([
        ['chat_id' => $chat, 'user_id' => $tendero->user_id, 'role_id' => 2],
        ['chat_id' => $chat, 'user_id' => $cliente->user_id, 'role_id' => 1],
    ]);

    $tarjeta = [
        'type' => 'preorder', 'preorder_id' => 1795538813258, 'status' => 'pending',
        'items' => [['product_id' => 7, 'name' => 'Pony Malta', 'quantity' => 3, 'price' => 3200]],
    ];
    $mensaje = DB::table('messages')->insertGetId([
        'chat_id' => $chat, 'user_id' => $tendero->user_id, 'role_id' => 2,
        'content' => json_encode($tarjeta),
    ], 'message_id');

    return compact('tendero', 'cliente', 'mensaje', 'tarjeta');
}

test('el cliente confirma la pre-orden del tendero', function () {
    $c = chatConPreorden();
    Sanctum::actingAs($c['cliente']);

    $this->postJson('/v1/chats/updateMessage', [
        'message_id' => $c['mensaje'],
        'content' => [...$c['tarjeta'], 'status' => 'confirmed'],
    ])->assertOk();

    expect(json_decode(DB::table('messages')->where('message_id', $c['mensaje'])->value('content'))->status)
        ->toBe('confirmed');
});

test('el cliente no puede cambiar precios ni cantidades', function () {
    $c = chatConPreorden();
    Sanctum::actingAs($c['cliente']);
    $trampa = $c['tarjeta'];
    $trampa['status'] = 'confirmed';
    $trampa['items'][0]['price'] = 1;

    $this->postJson('/v1/chats/updateMessage', ['message_id' => $c['mensaje'], 'content' => $trampa])
        ->assertStatus(403);
});

test('alguien de fuera del chat no la toca', function () {
    $c = chatConPreorden();
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    $this->postJson('/v1/chats/updateMessage', [
        'message_id' => $c['mensaje'],
        'content' => [...$c['tarjeta'], 'status' => 'confirmed'],
    ])->assertStatus(403);
});
