<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * «RECIBIENDO PEDIDOS»: la pausa del mostrador.
 *
 * Lo que se prueba acá no es el interruptor —guardar un booleano no se rompe
 * nunca— sino las TRES PUERTAS que tiene que cerrar, que es donde esto se cae
 * en silencio:
 *
 *  1. el escaparate deja de ofrecerla;
 *  2. la ficha sí se sirve, y con el dato, para que la app pueda explicarlo;
 *  3. crear el pedido se rechaza aunque alguien conserve el identificador.
 *
 * La 3 es la única que de verdad protege al tendero. Si se cae, el interruptor
 * es un adorno: filtrar listados no impide nada a quien ya tiene el número de
 * la tienda guardado en un favorito.
 */

/** Un tendero con su tienda, por la cadena completa user → owner → business. */
function tiendaQueRecibe(bool $recibiendo = true): array
{
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);

    $tendero = User::factory()->create(['rol' => 2]);

    $ownerId = DB::table('owner')->insertGetId([
        'user_id' => $tendero->user_id,
        'state'   => 1,
    ], 'owner_id');

    // `business.type` es clave foránea a `category_business`: sin la fila, la
    // inserción falla por integridad.
    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');

    $businessId = DB::table('business')->insertGetId([
        'name'             => 'Mercadito del Buho',
        'qualification'    => 0,
        'state'            => 1,
        'type'             => $tipo,
        'accepting_orders' => $recibiendo ? 1 : 0,
    ], 'busines_id');

    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $businessId, 'state' => 1,
    ]);

    return compact('tendero', 'businessId');
}

/* ------------------------------------------------------------------ */
/* EL INTERRUPTOR                                                      */
/* ------------------------------------------------------------------ */

test('una tienda recibe pedidos mientras nadie diga lo contrario', function () {
    $t = tiendaQueRecibe();

    Sanctum::actingAs($t['tendero']);

    $r = $this->getJson('/v1/negocio/me')->assertOk();

    // Arranca encendida: es como funcionaba el día antes de que la columna
    // existiera, y una tienda que aparece apagada sola deja de vender sin que
    // nadie lo haya decidido.
    expect($r->json('business.accepting_orders'))->toBeTrue()
        ->and($r->json('business.accepting_changed_at'))->toBeNull();
});

test('el tendero apaga y enciende su tienda', function () {
    $t = tiendaQueRecibe();

    Sanctum::actingAs($t['tendero']);

    $r = $this->putJson('/v1/negocio/me/recibiendo', ['accepting_orders' => false])
        ->assertOk();

    expect($r->json('business.accepting_orders'))->toBeFalse()
        // La hora queda puesta: es con lo que se le avisa a quien se le olvidó.
        ->and($r->json('business.accepting_changed_at'))->not->toBeNull()
        // El mensaje dice qué pasa, no «guardado».
        ->and($r->json('message'))->toContain('dejó de recibir');

    expect((bool) DB::table('business')->where('busines_id', $t['businessId'])->value('accepting_orders'))
        ->toBeFalse();

    $r = $this->putJson('/v1/negocio/me/recibiendo', ['accepting_orders' => true])->assertOk();
    expect($r->json('business.accepting_orders'))->toBeTrue();
});

test('la ficha del negocio no sirve para apagarlo', function () {
    $t = tiendaQueRecibe();

    Sanctum::actingAs($t['tendero']);

    /*
     * `PUT /negocio/me` valida con lista blanca, así que esto se descarta sin
     * decir nada. Se prueba porque el descarte silencioso es justo el fallo
     * que tuvo `max_courier_cash`: la pantalla decía «actualizado» y la
     * columna seguía igual. Acá el silencio es el comportamiento correcto, y
     * conviene que quede escrito cuál de los dos es.
     */
    $this->putJson('/v1/negocio/me', [
        'name'             => 'Mercadito del Buho',
        'accepting_orders' => false,
    ])->assertOk();

    expect((bool) DB::table('business')->where('busines_id', $t['businessId'])->value('accepting_orders'))
        ->toBeTrue();
});

test('no se apaga la tienda de otro', function () {
    $mia  = tiendaQueRecibe();
    $suya = tiendaQueRecibe();

    Sanctum::actingAs($mia['tendero']);

    // Con la cabecera de un local que no es suyo, la puerta responde 403 antes
    // de llegar al controlador. Ver `EnsureBusinessOwner`.
    $this->putJson(
        '/v1/negocio/me/recibiendo',
        ['accepting_orders' => false],
        ['X-Negocio' => (string) $suya['businessId']],
    )->assertStatus(403);

    expect((bool) DB::table('business')->where('busines_id', $suya['businessId'])->value('accepting_orders'))
        ->toBeTrue();
});

/* ------------------------------------------------------------------ */
/* LAS TRES PUERTAS                                                    */
/* ------------------------------------------------------------------ */

test('la tienda en pausa no sale en el escaparate', function () {
    $abierta = tiendaQueRecibe();
    $pausada = tiendaQueRecibe(false);

    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    $r = $this->getJson('/v1/businesses/index')->assertOk();

    $ids = collect($r->json('businesses'))
        ->pluck('business_id')
        ->filter()
        ->all();

    expect($ids)->toContain($abierta['businessId'])
        ->and($ids)->not->toContain($pausada['businessId']);
});

test('la ficha de la tienda en pausa se sirve, y dice que no recibe', function () {
    $t = tiendaQueRecibe(false);

    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    /*
     * ESTO NO ES UN 404 A PROPÓSITO. Quien entra por un favorito o por un
     * pedido anterior tiene que poder ver que la tienda existe y que vuelve;
     * «no encontrado» le dice que desapareció. La app apaga el carrito con
     * este campo.
     */
    $r = $this->postJson('/v1/businesses/show', ['busines_id' => $t['businessId']])
        ->assertOk();

    expect($r->json('data.accepting_orders') ?? $r->json('accepting_orders'))->toBeFalse();
});

test('no se le puede pedir a una tienda en pausa aunque se tenga su numero', function () {
    $e = escenarioPedido();

    // La tienda se apaga DESPUÉS de que el comprador tuviera su número, que es
    // el caso real: el carrito armado, la pantalla abierta, el favorito.
    DB::table('business')->where('busines_id', $e['businessId'])
        ->update(['accepting_orders' => 0]);

    $r = $this->postJson('/v1/orders/orders', cuerpoPedido($e))
        ->assertStatus(422);

    /*
     * El mensaje tiene que distinguirse del de una tienda dada de baja: una
     * vuelve en una hora y la otra no vuelve. Un «no disponible» para las dos
     * manda al vecino a esperar lo que no va a pasar.
     */
    expect($r->json('message'))->toContain('por ahora')
        ->and($r->json('message'))->not->toContain('no está disponible');

    expect(DB::table('orderssales')->where('busines_id', $e['businessId'])->count())->toBe(0);
});

test('encendida otra vez, el pedido entra', function () {
    $e = escenarioPedido();

    DB::table('business')->where('busines_id', $e['businessId'])
        ->update(['accepting_orders' => 0]);
    $this->postJson('/v1/orders/orders', cuerpoPedido($e))->assertStatus(422);

    DB::table('business')->where('busines_id', $e['businessId'])
        ->update(['accepting_orders' => 1]);

    $this->postJson('/v1/orders/orders', cuerpoPedido($e))->assertCreated();

    expect(DB::table('orderssales')->where('busines_id', $e['businessId'])->count())->toBe(1);
});

/* ------------------------------------------------------------------ */
/* LA PAUSA TIENE HORA DE VUELTA                                       */
/* ------------------------------------------------------------------ */

test('se pausa hasta una hora, y se dice cual', function () {
    $t = tiendaQueRecibe();

    Sanctum::actingAs($t['tendero']);

    $hasta = now()->addHours(2);

    $r = $this->putJson('/v1/negocio/me/recibiendo', [
        'accepting_orders' => false,
        'accepting_until'  => $hasta->toIso8601String(),
    ])->assertOk();

    expect($r->json('business.accepting_orders'))->toBeFalse()
        ->and($r->json('business.accepting_until'))->not->toBeNull()
        // «Se enciende sola» es la mitad del mensaje que importa: es lo que
        // evita que el tendero vuelva a entrar a comprobarlo.
        ->and($r->json('message'))->toContain('Se enciende sola');
});

test('cuando pasa la hora, la tienda vuelve a recibir sin que nadie la toque', function () {
    $t = tiendaQueRecibe();

    /*
     * La pausa se guarda vencida a mano porque el caso es justo ese: nadie
     * volvió a tocar la fila. Si esto fallara, haría falta una tarea
     * programada encendiendo tiendas, que es lo que el diseño evita.
     */
    DB::table('business')->where('busines_id', $t['businessId'])->update([
        'accepting_orders' => 0,
        'accepting_until'  => now()->subMinutes(5),
    ]);

    Sanctum::actingAs($t['tendero']);

    $r = $this->getJson('/v1/negocio/me')->assertOk();

    expect($r->json('business.accepting_orders'))->toBeTrue()
        // Vencida no se enseña: diría «Pausada hasta las 3» a las 4.
        ->and($r->json('business.accepting_until'))->toBeNull();
});

test('una pausa vencida no esconde la tienda ni rechaza el pedido', function () {
    $e = escenarioPedido();

    DB::table('business')->where('busines_id', $e['businessId'])->update([
        'accepting_orders' => 0,
        'accepting_until'  => now()->subMinute(),
    ]);

    // El escaparate la vuelve a ofrecer...
    $r = $this->getJson('/v1/businesses/index')->assertOk();
    expect(collect($r->json('businesses'))->pluck('business_id')->all())
        ->toContain($e['businessId']);

    // ...y el pedido entra. Las dos cosas tienen que decidirse igual: una
    // tienda que sale en la lista y luego rechaza es peor que una escondida.
    $this->postJson('/v1/orders/orders', cuerpoPedido($e))->assertCreated();
});

test('encender borra la hora de vuelta', function () {
    $t = tiendaQueRecibe();

    Sanctum::actingAs($t['tendero']);

    $this->putJson('/v1/negocio/me/recibiendo', [
        'accepting_orders' => false,
        'accepting_until'  => now()->addHour()->toIso8601String(),
    ])->assertOk();

    $this->putJson('/v1/negocio/me/recibiendo', ['accepting_orders' => true])->assertOk();

    expect(DB::table('business')->where('busines_id', $t['businessId'])->value('accepting_until'))
        ->toBeNull();
});

test('la hora de vuelta no puede ser pasada ni de la semana que viene', function () {
    $t = tiendaQueRecibe();

    Sanctum::actingAs($t['tendero']);

    // Ayer: una pausa que nace vencida no es una pausa.
    $this->putJson('/v1/negocio/me/recibiendo', [
        'accepting_orders' => false,
        'accepting_until'  => now()->subHour()->toIso8601String(),
    ])->assertStatus(422);

    /*
     * Dentro de un mes: eso no es pausar, es cerrar. Cerrar lo decide el
     * equipo con `state`, y sin tope este interruptor sería la manera de salir
     * del catálogo sin que nadie se entere.
     */
    $this->putJson('/v1/negocio/me/recibiendo', [
        'accepting_orders' => false,
        'accepting_until'  => now()->addMonth()->toIso8601String(),
    ])->assertStatus(422);

    expect((bool) DB::table('business')->where('busines_id', $t['businessId'])->value('accepting_orders'))
        ->toBeTrue();
});

test('la hora de vuelta en UTC se guarda en hora de Colombia', function () {
    $t = tiendaQueRecibe();

    Sanctum::actingAs($t['tendero']);

    // El panel manda toISOString(): una hora desde ahora, en UTC.
    $enUnaHora = now()->addHour()->utc();

    $this->putJson('/v1/negocio/me/recibiendo', [
        'accepting_orders' => false,
        'accepting_until'  => $enUnaHora->format('Y-m-d\TH:i:s.v\Z'),
    ])->assertOk();

    $guardada = \App\Models\Business::find($t['businessId'])->accepting_until;

    // Sin la conversión quedaba cinco horas más tarde.
    expect(abs($guardada->diffInMinutes(now()->addHour())))->toBeLessThan(2);
});
