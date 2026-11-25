<?php

use App\Models\Rol;
use App\Models\Seguridad\ChatReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * DENUNCIAR A UN COMPRADOR DESDE EL CHAT.
 *
 * Lo que se fija acá no es que guarde una fila: es que NO SE PUEDA DIRIGIR.
 * Un reporte en el que el teléfono eligiera a quién se denuncia, o de qué
 * conversación sacar los mensajes, no serviría de prueba de nada —y de paso
 * sería la forma más cómoda de leerse chats ajenos—. Por eso casi todo lo que
 * se prueba aquí es lo que el servidor decide por su cuenta.
 */
beforeEach(function () {
    foreach ([1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario'] as $id => $n) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $n, 'guard_name' => 'web']);
    }
});

function tenderoQueDenuncia(): array
{
    $u = User::factory()->create(['rol' => 2, 'phone' => '3001112233']);

    $ownerId = DB::table('owner')->insertGetId(
        ['user_id' => $u->user_id, 'state' => 1], 'owner_id',
    );
    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'Tienda de la esquina', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');
    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $negocio, 'state' => 1,
    ]);

    return [$u, $negocio];
}

/** Una conversación privada entre dos, con sus mensajes. */
function chatEntre(User $uno, User $otro, array $textos = []): int
{
    $chatId = DB::table('chats')->insertGetId(
        ['type' => 'private', 'created_at' => now()], 'chat_id',
    );

    foreach ([$uno, $otro] as $quien) {
        DB::table('chat_participants')->insert([
            'chat_id' => $chatId,
            'user_id' => $quien->user_id,
            'role_id' => $quien->rol,
            'joined_at' => now(),
        ]);
    }

    foreach ($textos as $i => $texto) {
        DB::table('messages')->insert([
            'chat_id' => $chatId,
            'user_id' => $otro->user_id,
            'role_id' => $otro->rol,
            'content' => $texto,
            'created_at' => now()->subMinutes(count($textos) - $i),
        ]);
    }

    return $chatId;
}

it('guarda la denuncia con el comprador que el servidor resuelve, no el que mande el telefono', function () {
    [$tendero, $negocio] = tenderoQueDenuncia();
    $comprador = User::factory()->create(['rol' => 1]);
    $chatId = chatEntre($tendero, $comprador, ['Pague o le va mal']);

    $r = $this->actingAs($tendero)->postJson("/v1/negocio/chats/{$chatId}/reportes", [
        'reason' => 'extorsion',
        'description' => 'Lleva tres días con lo mismo.',
        /* Aunque lo intente, esto no se mira. */
        'reported_user_id' => 99999,
    ]);

    $r->assertCreated();

    $fila = ChatReport::firstOrFail();
    expect((int) $fila->reported_user_id)->toBe((int) $comprador->user_id)
        ->and((int) $fila->reported_by)->toBe((int) $tendero->user_id)
        ->and((int) $fila->business_id)->toBe((int) $negocio)
        ->and($fila->reason)->toBe('extorsion')
        ->and($fila->state)->toBe(ChatReport::ABIERTO);
});

it('se lleva los mensajes de ESE chat y no los de otro', function () {
    [$tendero] = tenderoQueDenuncia();
    $comprador = User::factory()->create(['rol' => 1]);
    $otroComprador = User::factory()->create(['rol' => 1]);

    $elChat = chatEntre($tendero, $comprador, ['Esto es lo que dijo']);
    chatEntre($tendero, $otroComprador, ['Esto es de otra conversacion']);

    $this->actingAs($tendero)
        ->postJson("/v1/negocio/chats/{$elChat}/reportes", ['reason' => 'amenazas'])
        ->assertCreated();

    $prueba = ChatReport::firstOrFail()->evidence;

    expect($prueba)->toHaveCount(1)
        ->and($prueba[0]['texto'])->toBe('Esto es lo que dijo');
});

it('guarda las tarjetas en prosa y no como JSON', function () {
    [$tendero] = tenderoQueDenuncia();
    $comprador = User::factory()->create(['rol' => 1]);

    $tarjeta = json_encode([
        'type' => 'preorder',
        'items' => [['name' => 'Arroz', 'quantity' => 2, 'price' => 3000]],
        'total' => 6000,
    ]);
    $chatId = chatEntre($tendero, $comprador, [$tarjeta]);

    $this->actingAs($tendero)
        ->postJson("/v1/negocio/chats/{$chatId}/reportes", ['reason' => 'estafa'])
        ->assertCreated();

    $texto = ChatReport::firstOrFail()->evidence[0]['texto'];

    expect($texto)->not->toContain('{')
        ->and($texto)->not->toContain('preorder');
});

it('no deja denunciar una conversacion de la que no eres parte', function () {
    [$tendero] = tenderoQueDenuncia();
    $ajeno = User::factory()->create(['rol' => 2]);
    $comprador = User::factory()->create(['rol' => 1]);

    $chatId = chatEntre($ajeno, $comprador, ['Algo privado entre ellos']);

    $this->actingAs($tendero)
        ->postJson("/v1/negocio/chats/{$chatId}/reportes", ['reason' => 'extorsion'])
        ->assertForbidden();

    expect(ChatReport::count())->toBe(0);
});

it('no deja denunciar a quien no es comprador', function () {
    [$tendero] = tenderoQueDenuncia();
    $domiciliario = User::factory()->create(['rol' => 3]);

    $chatId = chatEntre($tendero, $domiciliario, ['Voy en camino']);

    $this->actingAs($tendero)
        ->postJson("/v1/negocio/chats/{$chatId}/reportes", ['reason' => 'amenazas'])
        ->assertStatus(422);
});

it('rechaza un motivo que no esta en el vocabulario', function () {
    [$tendero] = tenderoQueDenuncia();
    $comprador = User::factory()->create(['rol' => 1]);
    $chatId = chatEntre($tendero, $comprador, ['Hola']);

    $this->actingAs($tendero)
        ->postJson("/v1/negocio/chats/{$chatId}/reportes", ['reason' => 'me_cae_mal'])
        ->assertStatus(422);

    expect(ChatReport::count())->toBe(0);
});

it('dos denuncias seguidas del mismo chat son una sola', function () {
    [$tendero] = tenderoQueDenuncia();
    $comprador = User::factory()->create(['rol' => 1]);
    $chatId = chatEntre($tendero, $comprador, ['Pague o le va mal']);

    $this->actingAs($tendero)
        ->postJson("/v1/negocio/chats/{$chatId}/reportes", ['reason' => 'otro'])
        ->assertCreated();

    /* El tendero vuelve a entrar porque no le quedó claro que ya estaba, y
       esta vez sí elige el motivo. */
    $this->actingAs($tendero)
        ->postJson("/v1/negocio/chats/{$chatId}/reportes", ['reason' => 'extorsion'])
        ->assertOk();

    expect(ChatReport::count())->toBe(1)
        ->and(ChatReport::firstOrFail()->reason)->toBe('extorsion');
});

it('sin sesion no se denuncia a nadie', function () {
    [$tendero] = tenderoQueDenuncia();
    $comprador = User::factory()->create(['rol' => 1]);
    $chatId = chatEntre($tendero, $comprador, ['Hola']);

    $this->postJson("/v1/negocio/chats/{$chatId}/reportes", ['reason' => 'extorsion'])
        ->assertUnauthorized();
});
