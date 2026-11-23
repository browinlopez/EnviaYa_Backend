<?php

/**
 * ==========================================================================
 * DOS LOCALES DEL MISMO DUEÑO SON DOS CONVERSACIONES.
 *
 * `chats` no guardaba de qué negocio era una conversación: el chat se
 * buscaba por la PAREJA de personas. Con un dueño de un solo local eso
 * funciona y nadie lo nota. Con dos, falla de dos maneras:
 *
 *  1. El dueño ve las mismas conversaciones mire el local que mire.
 *
 *  2. Y la seria: si el mismo vecino escribe a los DOS locales, los dos
 *     hilos se funden en UNO. La pregunta del medicamento y la del almuerzo
 *     quedan mezcladas por fecha y no hay forma de separarlas después.
 *
 * La prueba que importa de este archivo es «el mismo vecino escribiendo a
 * los dos locales abre DOS conversaciones». Las demás cuidan las esquinas.
 * ========================================================================== */

use App\Models\Chat\Chat;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Queue::fake();

    foreach ([1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario', 4 => 'admin'] as $id => $n) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $n, 'guard_name' => 'web']);
    }
});

/** Un dueño con los locales que se le pidan, y su usuario. */
function duenoCon(int $cuantos): array
{
    $user = User::factory()->create(['rol' => 2]);

    $ownerId = DB::table('owner')->insertGetId(
        ['user_id' => $user->user_id, 'state' => 1],
        'owner_id',
    );

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');

    $locales = [];

    for ($i = 0; $i < $cuantos; $i++) {
        $id = DB::table('business')->insertGetId([
            'name' => 'Local '.($i + 1),
            'qualification' => 0,
            'state' => 1,
            'type' => $tipo,
        ], 'busines_id');

        DB::table('owner_busines')->insert([
            'owner_id' => $ownerId,
            'busines_id' => $id,
            'state' => 1,
        ]);

        $locales[] = $id;
    }

    return ['user' => $user, 'locales' => $locales];
}

/** Manda un mensaje, opcionalmente diciendo de qué local es. */
function escribir(User $de, User $a, string $texto, ?int $negocio = null)
{
    Sanctum::actingAs($de);

    $cuerpo = [
        'user_id' => $de->user_id,
        'recipient_id' => $a->user_id,
        'content' => $texto,
    ];

    if ($negocio !== null) {
        $cuerpo['busines_id'] = $negocio;
    }

    return test()->postJson('/v1/chats/send-message', $cuerpo);
}

/* ---------------------------------------------------------------------- */

it('el mismo vecino escribiendo a los DOS locales abre DOS conversaciones', function () {
    /*
     * ÉSTE ES EL FALLO QUE MOTIVÓ TODO.
     *
     * Antes los dos mensajes caían en el mismo chat, porque la búsqueda solo
     * miraba quién habla con quién. Un hilo con la pregunta del medicamento y
     * la del almuerzo revueltas.
     */
    $d = duenoCon(2);
    [$drogueria, $restaurante] = $d['locales'];

    $vecino = User::factory()->create(['rol' => 1]);

    escribir($vecino, $d['user'], '¿Tienen acetaminofén?', $drogueria)->assertStatus(201);
    escribir($vecino, $d['user'], '¿A cómo el almuerzo?', $restaurante)->assertStatus(201);

    expect(Chat::count())->toBe(2);

    expect(Chat::where('busines_id', $drogueria)->first()->messages()->first()->content)
        ->toBe('¿Tienen acetaminofén?');

    expect(Chat::where('busines_id', $restaurante)->first()->messages()->first()->content)
        ->toBe('¿A cómo el almuerzo?');
});

it('dos mensajes al MISMO local siguen siendo una sola conversación', function () {
    /* El arreglo no puede partir en dos lo que siempre fue un hilo. */
    $d = duenoCon(2);
    $vecino = User::factory()->create(['rol' => 1]);

    escribir($vecino, $d['user'], 'Buenas', $d['locales'][0]);
    escribir($vecino, $d['user'], '¿Me lo manda hoy?', $d['locales'][0]);

    expect(Chat::count())->toBe(1);
    expect(Chat::first()->messages()->count())->toBe(2);
});

it('el dueño solo ve las del local que está mirando', function () {
    $d = duenoCon(2);
    [$uno, $otro] = $d['locales'];

    $ana = User::factory()->create(['rol' => 1]);
    $beto = User::factory()->create(['rol' => 1]);

    escribir($ana, $d['user'], 'Del primero', $uno);
    escribir($beto, $d['user'], 'Del segundo', $otro);

    Sanctum::actingAs($d['user']);

    $lista = test()->postJson('/v1/chats/user-chats', [
        'user_id' => $d['user']->user_id,
        'busines_id' => $uno,
    ])->assertStatus(200)->json();

    expect($lista)->toHaveCount(1);
});

it('sin decir el local, salen TODAS: la app de hoy no cambia', function () {
    /*
     * La app publicada no manda `busines_id` ni en el envío ni en la lista.
     * Si esto se pusiera rojo, una versión instalada se quedaría sin la mitad
     * de sus conversaciones de un día para otro.
     */
    $d = duenoCon(2);

    $ana = User::factory()->create(['rol' => 1]);
    $beto = User::factory()->create(['rol' => 1]);

    escribir($ana, $d['user'], 'Del primero', $d['locales'][0]);
    escribir($beto, $d['user'], 'Del segundo', $d['locales'][1]);

    Sanctum::actingAs($d['user']);

    $lista = test()->postJson('/v1/chats/user-chats', [
        'user_id' => $d['user']->user_id,
    ])->assertStatus(200)->json();

    expect($lista)->toHaveCount(2);
});

it('con UN solo local, el negocio se deduce sin que nadie lo mande', function () {
    /*
     * Esto es lo que hace que la app publicada siga clasificando bien sin
     * tocarle una línea: no manda el local, pero no hace falta porque no hay
     * duda posible.
     */
    $d = duenoCon(1);
    $vecino = User::factory()->create(['rol' => 1]);

    escribir($vecino, $d['user'], 'Buenas');

    expect(Chat::first()->busines_id)->toBe($d['locales'][0]);
});

it('con DOS locales y sin decir cuál, NO se adivina', function () {
    /*
     * Queda sin local, y eso es una respuesta: mover la conversación a un
     * mostrador al azar sería peor, porque el dueño la buscaría en el otro y
     * no la encontraría.
     *
     * Sin local se ve en los dos, que es exactamente lo que pasaba antes de
     * todo esto. No empeora nada.
     */
    $d = duenoCon(2);
    $vecino = User::factory()->create(['rol' => 1]);

    escribir($vecino, $d['user'], 'Buenas');

    expect(Chat::first()->busines_id)->toBeNull();
});

it('no se puede colgar la conversación del mostrador de otro', function () {
    /*
     * Sin esto, cualquiera podría mandar `busines_id` del negocio del vecino y
     * meterse en su bandeja de entrada. Se ignora y queda sin local, que es lo
     * mismo que si no lo hubiera mandado.
     */
    $mio = duenoCon(1);
    $ajeno = duenoCon(1);

    $vecino = User::factory()->create(['rol' => 1]);

    escribir($vecino, $mio['user'], 'Hola', $ajeno['locales'][0])->assertStatus(201);

    expect(Chat::first()->busines_id)->toBe($mio['locales'][0]);
});

it('una conversación con soporte NO es de ningún local', function () {
    /*
     * Nulo acá es una respuesta --«esto no es de un mostrador»-- y no un
     * hueco. Al no tener local, el dueño la ve mire el local que mire, que es
     * lo correcto para un reclamo al equipo.
     */
    $d = duenoCon(2);
    $soporte = User::factory()->create(['rol' => 4]);

    escribir($soporte, $d['user'], 'Revisamos tu caso.');

    expect(Chat::first()->busines_id)->toBeNull();

    Sanctum::actingAs($d['user']);

    $lista = test()->postJson('/v1/chats/user-chats', [
        'user_id' => $d['user']->user_id,
        'busines_id' => $d['locales'][0],
    ])->json();

    expect($lista)->toHaveCount(1);
});

it('la de un domiciliario con el comprador tampoco tiene local', function () {
    $domi = User::factory()->create(['rol' => 3]);
    $vecino = User::factory()->create(['rol' => 1]);

    escribir($domi, $vecino, 'Ya voy llegando.');

    expect(Chat::first()->busines_id)->toBeNull();
});
