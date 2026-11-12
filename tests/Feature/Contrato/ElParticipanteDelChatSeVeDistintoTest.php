<?php

declare(strict_types=1);

use App\Http\Resources\Chat\ParticipanteDeLaListaResource;
use App\Http\Resources\Chat\ParticipanteDelChatResource;
use App\Models\Chat\Chat;
use App\Models\Chat\ChatParticipant;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * LISTAR TUS CONVERSACIONES DEVUELVE MÁS DE LA OTRA PERSONA QUE ABRIRLAS.
 *
 * Como la prueba del producto, esto NO fija que algo funcione: fija una
 * incoherencia que existe hoy, medida, para que el día que se arregle sea una
 * decisión y no un accidente.
 *
 * El mismo participante, dos formas:
 *
 *   `POST /chats/messages`      4 campos   `ParticipanteDelChatResource`
 *   `POST /chats/send-message`  4 campos   el mismo
 *   `POST /chats/user-chats`    9 campos   `ParticipanteDeLaListaResource`
 *
 * Los cinco de más: `email`, `phone`, `address`, `qualification` y `state`.
 *
 * O SEA QUE LA PANTALLA QUE LISTA LAS CONVERSACIONES RECIBE EL CORREO, EL
 * TELÉFONO Y LA DIRECCIÓN de cada persona con la que has hablado, y la que
 * abre una conversación concreta no. Es la de menos falta la que más manda.
 *
 * ES LA MISMA FUGA QUE ESTE REPOSITORIO YA CERRÓ DOS VECES. En los dos
 * listados del catálogo salían el documento, la fecha de nacimiento y la
 * dirección del propietario a cualquiera que abriera la vitrina; se cerró, y
 * la regla que quedó escrita fue: «si algún día el panel necesita esos
 * campos, van por la API de administración, que tiene puerta». Después se
 * cerró otra vez en favoritos. Aquí sigue abierta.
 *
 * NO SE TOCA: quitar cinco campos de una respuesta cambia lo que reciben dos
 * apps publicadas, y el teléfono puede estar ahí por una razón —llamar al
 * tendero por un domicilio en curso—. Cuál de los cinco sobra es una decisión
 * de producto.
 */
function unChatConDosPersonas(): Chat
{
    foreach ([1 => 'comprador', 2 => 'tendero'] as $id => $nombre) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $nombre, 'guard_name' => 'web']);
    }

    $comprador = User::factory()->create([
        'rol' => 1,
        'email' => 'quien.compra@ejemplo.com',
        'phone' => '3001234567',
        'address' => 'Carrera 1 # 2-3, apto 401',
    ]);

    $tendero = User::factory()->create(['rol' => 2]);

    $chat = Chat::create(['type' => 'private']);

    foreach ([$comprador, $tendero] as $u) {
        ChatParticipant::create([
            'chat_id' => $chat->chat_id,
            'user_id' => $u->user_id,
            'role_id' => $u->rol,
            'joined_at' => now(),
        ]);
    }

    return $chat->load('participants.user');
}

it('la lista manda cinco campos mas del participante que la conversacion', function () {
    $chat = unChatConDosPersonas();
    $req = Request::create('/v1/chats/user-chats', 'POST');

    $participante = $chat->participants->first();

    $enLaConversacion = (new ParticipanteDelChatResource($participante))->toArray($req);
    $enLaLista = (new ParticipanteDeLaListaResource($participante))->toArray($req);

    $soloEnLaLista = array_values(array_diff(
        array_keys($enLaLista), array_keys($enLaConversacion),
    ));
    sort($soloEnLaLista);

    /*
     * LA LISTA EXACTA, y no «hay diferencias»: el día que alguien quite
     * `address` —que es probablemente lo correcto— esto se pone rojo y le
     * obliga a actualizarla, que es el momento de preguntarse qué pantalla de
     * qué app lo estaba leyendo.
     */
    expect($soloEnLaLista)->toBe([
        'address', 'email', 'phone', 'qualification', 'state',
    ]);

    // Y que no se pierde nada por el otro lado.
    expect(array_diff(array_keys($enLaConversacion), array_keys($enLaLista)))->toBe([]);
});

it('los tres campos personales LLEVAN DATO, no son claves vacias', function () {
    /*
     * Esta es la mitad que convierte la diferencia de claves en una fuga. Una
     * forma con cinco claves más, todas nulas, sería fea y no grave; con el
     * correo y la dirección dentro, es otra cosa.
     *
     * El escenario pone teléfono y dirección a propósito: en la base de
     * desarrollo esas columnas están vacías, así que medir allí daría «no
     * llega nada» y escondería el problema detrás de un dato que falta.
     */
    $chat = unChatConDosPersonas();
    $req = Request::create('/v1/chats/user-chats', 'POST');

    $comprador = $chat->participants->first(
        fn (ChatParticipant $p) => (int) $p->role_id === 1,
    );

    expect($comprador)->not->toBeNull();

    $enLaLista = (new ParticipanteDeLaListaResource($comprador))->toArray($req);

    expect($enLaLista['email'])->toBe('quien.compra@ejemplo.com');
    expect($enLaLista['phone'])->toBe('3001234567');
    expect($enLaLista['address'])->toBe('Carrera 1 # 2-3, apto 401');

    // Y que la otra forma NO los manda, que es la contrapartida.
    $enLaConversacion = (new ParticipanteDelChatResource($comprador))->toArray($req);

    expect($enLaConversacion)->not->toHaveKey('email');
    expect($enLaConversacion)->not->toHaveKey('phone');
    expect($enLaConversacion)->not->toHaveKey('address');

    // Lo que SÍ comparten: el nombre, que es lo que la pantalla pinta.
    expect($enLaConversacion['name'])->toBe($enLaLista['name']);
});
