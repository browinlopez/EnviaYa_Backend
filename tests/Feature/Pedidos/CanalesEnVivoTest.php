<?php

use App\Events\MessageSent;
use App\Models\Chat\Message;
use App\Models\Notification;
use App\Models\Rol;
use App\Models\User;
use App\Services\Avisos;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Quién puede escuchar el canal del negocio, y qué llega por la campana.
 *
 * El canal `business.{id}` es nuevo y resuelve algo que `order.{id}` no podía:
 * avisar de un pedido que TODAVÍA NO EXISTE. Para escuchar el de un pedido hay
 * que saber su número, y un pedido recién creado no lo tiene para quien aún no
 * lo ha visto.
 *
 * Lo que se fija acá es a quién deja entrar: la tienda y sus domiciliarios, y
 * nadie más. Por ese canal viaja lo que la competencia querría saber —cuántos
 * pedidos entran, de cuánto son y a qué hora—.
 */
function negocioConEquipo(): array
{
    foreach ([1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario'] as $id => $nombre) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $nombre, 'guard_name' => 'web']);
    }

    $businessId = DB::table('business')->insertGetId([
        'name' => 'Tienda del Canal', 'qualification' => 0, 'state' => 1,
    ]);

    $tendero = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId(['user_id' => $tendero->user_id, 'state' => 1]);
    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $businessId, 'state' => 1,
    ]);

    $domiUser = User::factory()->create(['rol' => 3]);
    $domiId = DB::table('domiciliary')->insertGetId([
        'user_id' => $domiUser->user_id, 'qualification' => 0,
        'available' => 1, 'state' => 1,
    ]);
    DB::table('business_domiciliary')->insert([
        'domiciliary_id' => $domiId, 'busines_id' => $businessId,
    ]);

    // Alguien de fuera: una cuenta válida sin relación con esta tienda.
    $ajeno = User::factory()->create(['rol' => 1]);

    return compact('businessId', 'tendero', 'domiUser', 'ajeno');
}

/** Pide autorización para un canal privado, como haría la app. */
function autorizar($canal): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/broadcasting/auth', [
        'socket_id'    => '1234.5678',
        'channel_name' => 'private-' . $canal,
    ]);
}

/* ------------------------- CANAL DEL NEGOCIO -------------------------- */

test('la tienda escucha su propio canal', function () {
    $e = negocioConEquipo();
    Sanctum::actingAs($e['tendero']);

    autorizar('business.' . $e['businessId'])->assertOk();
});

test('el domiciliario de esa tienda también lo escucha', function () {
    // Es la razón de que sea UN canal y no dos: el repartidor saca sus pedidos
    // disponibles del mismo negocio, así que necesita exactamente lo mismo.
    $e = negocioConEquipo();
    Sanctum::actingAs($e['domiUser']);

    autorizar('business.' . $e['businessId'])->assertOk();
});

test('un desconocido no escucha el canal de una tienda', function () {
    // Por acá viaja cuánto vende la tienda y a qué hora.
    $e = negocioConEquipo();
    Sanctum::actingAs($e['ajeno']);

    autorizar('business.' . $e['businessId'])->assertForbidden();
});

test('un domiciliario de OTRA tienda tampoco', function () {
    $e = negocioConEquipo();

    $otroNegocio = DB::table('business')->insertGetId([
        'name' => 'Tienda Vecina', 'qualification' => 0, 'state' => 1,
    ]);
    $otroUser = User::factory()->create(['rol' => 3]);
    $otroDomi = DB::table('domiciliary')->insertGetId([
        'user_id' => $otroUser->user_id, 'qualification' => 0,
        'available' => 1, 'state' => 1,
    ]);
    DB::table('business_domiciliary')->insert([
        'domiciliary_id' => $otroDomi, 'busines_id' => $otroNegocio,
    ]);

    Sanctum::actingAs($otroUser);
    autorizar('business.' . $e['businessId'])->assertForbidden();
});

/* ---------------------------- LA CAMPANA ------------------------------ */

test('el aviso se guarda y queda sin leer', function () {
    // Guardar es lo que le da historial: sin esto sería un aviso que solo
    // existe si estabas mirando en ese instante.
    $e = negocioConEquipo();

    $aviso = Avisos::para(
        $e['domiUser']->user_id,
        'entrega_asignada',
        'Tienes una entrega nueva: pedido #99.',
        ['order_id' => 99],
    );

    expect($aviso)->not->toBeNull()
        ->and($aviso->read)->toBeFalse()
        ->and($aviso->tipo)->toBe('entrega_asignada')
        // El contexto es lo que permite que tocar el aviso lleve a alguna parte.
        ->and($aviso->datos['order_id'])->toBe(99);
});

test('cada quien ve solo sus avisos', function () {
    $e = negocioConEquipo();

    Avisos::para($e['domiUser']->user_id, 'entrega_asignada', 'Para el domiciliario');
    Avisos::para($e['ajeno']->user_id, 'pedido_cancelado', 'Para el comprador');

    Sanctum::actingAs($e['domiUser']);

    $respuesta = test()->getJson('/v1/users/notifications')->assertOk();

    expect($respuesta->json('notifications'))->toHaveCount(1)
        ->and($respuesta->json('notifications.0.message'))->toBe('Para el domiciliario')
        ->and($respuesta->json('unread'))->toBe(1);
});

test('no se puede marcar como leído el aviso de otro', function () {
    /*
     * El método original recibía el `notification_id` y nada más: bastaba
     * probar números para ir marcando los avisos ajenos. Ahora se responde 404,
     * igual que a uno inexistente, para no confirmar siquiera que el número
     * acertó.
     */
    $e = negocioConEquipo();

    $ajeno = Avisos::para($e['ajeno']->user_id, 'pedido_cancelado', 'No es tuyo');

    Sanctum::actingAs($e['domiUser']);

    test()->putJson('/v1/users/notifications/read', [
        'notification_id' => $ajeno->notification_id,
    ])->assertNotFound();

    expect(Notification::find($ajeno->notification_id)->read)->toBeFalse();
});

/* --------------------- LA BANDEJA DE CONVERSACIONES -------------------- */

test('el mensaje viaja al chat y al canal personal de quien lo recibe', function () {
    /*
     * El canal del chat solo lo escucha quien tiene esa conversación abierta.
     * La pantalla de Mensajes no tiene ninguna abierta, así que sin el canal
     * personal la bandeja se quedaba quieta hasta la recarga del minuto.
     */
    $e = negocioConEquipo();

    $chatId = DB::table('chats')->insertGetId(['type' => 'private']);
    foreach ([[$e['tendero'], 2], [$e['ajeno'], 1]] as [$u, $rol]) {
        DB::table('chat_participants')->insert([
            'chat_id' => $chatId, 'user_id' => $u->user_id, 'role_id' => $rol,
        ]);
    }

    $mensaje = Message::create([
        'chat_id' => $chatId,
        'user_id' => $e['ajeno']->user_id,
        'role_id' => 1,
        'content' => '¿Todavía tienes arepas?',
    ]);

    $canales = collect((new MessageSent($mensaje))->broadcastOn())
        ->map(fn ($c) => (string) $c->name)
        ->all();

    expect($canales)->toContain('private-chat.' . $chatId)
        ->and($canales)->toContain('private-App.Models.User.' . $e['tendero']->user_id)
        // El autor no: ya tiene el mensaje en pantalla, y recibirlo de vuelta
        // le subiría el contador de no leídos de su propio mensaje.
        ->and($canales)->not->toContain('private-App.Models.User.' . $e['ajeno']->user_id);
});
