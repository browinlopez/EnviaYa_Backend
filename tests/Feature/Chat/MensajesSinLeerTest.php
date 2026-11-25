<?php

/*
 * CUÁNTOS MENSAJES SIN LEER TIENE CADA CONVERSACIÓN.
 *
 * La app pintaba `unread_count` en la lista de chats y en el acceso
 * «Mensajes» del inicio, y el servidor nunca lo mandaba: no guardaba hasta
 * dónde había leído cada uno. Ahora sí: abrir la conversación la deja leída,
 * escribir también, y la lista cuenta los mensajes de los demás posteriores.
 */

use App\Models\Notification;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Queue::fake();
    foreach ([1 => 'comprador', 2 => 'tendero'] as $id => $n) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $n, 'guard_name' => 'web']);
    }
});

function mensajeSinLeer(User $de, User $a, string $texto)
{
    Sanctum::actingAs($de);

    return test()->postJson('/v1/chats/send-message', [
        'user_id' => $de->user_id,
        'recipient_id' => $a->user_id,
        'content' => $texto,
    ])->assertStatus(201);
}

function sinLeerDe(User $quien): array
{
    Sanctum::actingAs($quien);
    $r = test()->postJson('/v1/chats/user-chats', ['user_id' => $quien->user_id])->assertOk();

    return collect($r->json())->pluck('unread_count', 'chat_id')->all();
}

function abrirChat(User $quien, int $chatId): void
{
    Sanctum::actingAs($quien);
    test()->postJson('/v1/chats/messages', ['chat_id' => $chatId])->assertOk();
}

it('cuenta los mensajes que llegan y los borra al abrir la conversación', function () {
    $vecino = User::factory()->create(['rol' => 1]);
    $tienda = User::factory()->create(['rol' => 2]);

    mensajeSinLeer($vecino, $tienda, 'Hola');
    $this->travel(1)->minutes();
    mensajeSinLeer($vecino, $tienda, '¿Tienen pan?');

    $chats = sinLeerDe($tienda);
    $chatId = (int) array_key_first($chats);
    expect($chats[$chatId])->toBe(2);

    // Quien escribió no tiene nada sin leer: los mensajes son suyos.
    expect(sinLeerDe($vecino)[$chatId])->toBe(0);

    $avisosDeMensaje = fn () => Notification::where('user_id', $tienda->user_id)
        ->where('tipo', 'mensaje_nuevo')->where('read', false)->count();
    expect($avisosDeMensaje())->toBe(2);

    $this->travel(1)->minutes();
    abrirChat($tienda, $chatId);
    expect(sinLeerDe($tienda)[$chatId])->toBe(0)
        // Sus avisos de «mensaje nuevo» quedan leídos con el chat.
        ->and($avisosDeMensaje())->toBe(0);
});

it('al responder, el que responde queda al día y el otro tiene uno nuevo', function () {
    $vecino = User::factory()->create(['rol' => 1]);
    $tienda = User::factory()->create(['rol' => 2]);

    mensajeSinLeer($vecino, $tienda, 'Hola');
    $this->travel(1)->minutes();
    mensajeSinLeer($tienda, $vecino, 'Sí, claro');

    $chatId = (int) array_key_first(sinLeerDe($tienda));
    expect(sinLeerDe($tienda)[$chatId])->toBe(0)
        ->and(sinLeerDe($vecino)[$chatId])->toBe(1);
});
