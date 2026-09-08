<?php

use App\Jobs\EnviarAvisoPush;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/**
 * UN MENSAJE DE CHAT TAMBIÉN TIENE QUE LLEGAR CON LA APP CERRADA.
 *
 * EL FALLO QUE ESTO CAZA: el chat sólo emitía por websocket. Alguien preguntaba
 * «¿tiene leche deslactosada?» y si el otro lado tenía la app cerrada, el
 * mensaje esperaba a que la abriera. En un pedido en curso eso es un cliente
 * esperando una respuesta que nadie va a ver.
 */

beforeEach(function () {
    Queue::fake();

    foreach ([1 => 'comprador', 2 => 'tendero'] as $id => $nombre) {
        \App\Models\Rol::firstOrCreate(['rol_id' => $id], ['name' => $nombre, 'guard_name' => 'web']);
    }
});

it('al escribir un mensaje le suena el telefono al otro', function () {
    $quienEscribe = User::factory()->create(['rol' => 1, 'name' => 'Ana']);
    $quienRecibe  = User::factory()->create(['rol' => 2, 'name' => 'Tienda el progreso']);

    Sanctum::actingAs($quienEscribe);

    $this->postJson('/v1/chats/send-message', [
        'user_id'      => $quienEscribe->user_id,
        'recipient_id' => $quienRecibe->user_id,
        'content'      => '¿Tiene leche deslactosada?',
    ])->assertCreated();

    Queue::assertPushed(
        EnviarAvisoPush::class,
        fn ($job) => (int) $job->userId === (int) $quienRecibe->user_id
            && $job->datos['tipo'] === 'mensaje_nuevo',
    );
});

it('a quien escribe NO le llega su propio mensaje', function () {
    $quienEscribe = User::factory()->create(['rol' => 1, 'name' => 'Ana']);
    $quienRecibe  = User::factory()->create(['rol' => 2, 'name' => 'Tienda']);

    Sanctum::actingAs($quienEscribe);

    $this->postJson('/v1/chats/send-message', [
        'user_id'      => $quienEscribe->user_id,
        'recipient_id' => $quienRecibe->user_id,
        'content'      => 'Hola',
    ])->assertCreated();

    Queue::assertNotPushed(
        EnviarAvisoPush::class,
        fn ($job) => (int) $job->userId === (int) $quienEscribe->user_id,
    );
});

it('el titulo es el NOMBRE de quien escribe, no un rotulo generico', function () {
    $quienEscribe = User::factory()->create(['rol' => 2, 'name' => 'Tienda el progreso']);
    $quienRecibe  = User::factory()->create(['rol' => 1, 'name' => 'Ana']);

    Sanctum::actingAs($quienEscribe);

    $this->postJson('/v1/chats/send-message', [
        'user_id'      => $quienEscribe->user_id,
        'recipient_id' => $quienRecibe->user_id,
        'content'      => 'Ya salió tu pedido',
    ])->assertCreated();

    /*
     * En la barra de notificaciones el título es lo único que se lee entero, y
     * saber quién escribe es la mitad de la decisión de abrir. «Mensaje nuevo»
     * lo escribe cualquier aplicación.
     */
    Queue::assertPushed(
        EnviarAvisoPush::class,
        fn ($job) => $job->titulo === 'Tienda el progreso',
    );
});

it('el aviso lleva con quien se habla, no solo el numero del chat', function () {
    $quienEscribe = User::factory()->create(['rol' => 1, 'name' => 'Ana']);
    $quienRecibe  = User::factory()->create(['rol' => 2, 'name' => 'Tienda']);

    Sanctum::actingAs($quienEscribe);

    $this->postJson('/v1/chats/send-message', [
        'user_id'      => $quienEscribe->user_id,
        'recipient_id' => $quienRecibe->user_id,
        'content'      => 'Hola',
    ])->assertCreated();

    /*
     * La pantalla del chat resuelve la contraparte de `recipient`, y sin ella
     * se queda en null y el chat NO ABRE. Mandar sólo `chat_id` haría que
     * tocar la notificación llevara a una pantalla vacía.
     */
    Queue::assertPushed(EnviarAvisoPush::class, function ($job) use ($quienEscribe) {
        return !empty($job->datos['chat_id'])
            && (int) $job->datos['remitente_id'] === (int) $quienEscribe->user_id
            && $job->datos['remitente_nombre'] === 'Ana';
    });
});

it('un mensaje largo se recorta antes de salir', function () {
    $quienEscribe = User::factory()->create(['rol' => 1, 'name' => 'Ana']);
    $quienRecibe  = User::factory()->create(['rol' => 2, 'name' => 'Tienda']);

    Sanctum::actingAs($quienEscribe);

    $this->postJson('/v1/chats/send-message', [
        'user_id'      => $quienEscribe->user_id,
        'recipient_id' => $quienRecibe->user_id,
        'content'      => str_repeat('a', 500),
    ])->assertCreated();

    // Se corta igual en la pantalla bloqueada: mandarlo entero sólo gasta la
    // carga útil, que en APNs está limitada.
    Queue::assertPushed(
        EnviarAvisoPush::class,
        fn ($job) => mb_strlen($job->cuerpo) <= 125,
    );
});
