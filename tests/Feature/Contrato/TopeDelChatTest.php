<?php

/**
 * CUÁNTO CABE EN UN MENSAJE, SEGÚN QUÉ SEA.
 *
 * Un mensaje escrito se queda en mil letras. Una TARJETA —el pedido armado,
 * que viaja como JSON con nombres, precios y fotos de cada producto— pasaba de
 * mil con cuatro productos y el envío se rechazaba; la columna es TEXT y le
 * caben 64 KB, así que su tope es treinta mil.
 *
 * Y SOLO LAS TARJETAS QUE EXISTEN. Con cualquier `type` bastaba envolver un
 * texto largo en JSON para saltarse el tope de mil, que es lo que esa lista
 * blanca impide.
 *
 * Esta regla no tenía ninguna prueba, y es de las que fallan en silencio: si
 * se rompe, el tendero manda un carrito de cinco productos y recibe «el
 * contenido es demasiado largo» sin que nadie entienda por qué.
 */

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Queue::fake();

    foreach ([1 => 'comprador', 2 => 'tendero'] as $id => $nombre) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $nombre, 'guard_name' => 'web']);
    }
});

/** Manda `$contenido` de una cuenta a otra y devuelve la respuesta. */
function mandar(string $contenido, bool $conNegocio = false)
{
    $escribe = User::factory()->create(['rol' => 1]);
    $recibe = User::factory()->create(['rol' => 2]);

    Sanctum::actingAs($escribe);

    $cuerpo = [
        'user_id' => $escribe->user_id,
        'recipient_id' => $recibe->user_id,
        'content' => $contenido,
    ];

    // Una preorden solo viaja en el chat de un negocio, del que es dueño quien la recibe.
    if ($conNegocio) {
        $negocio = DB::table('business')->insertGetId(['name' => 'Tienda', 'qualification' => 0, 'state' => 1], 'busines_id');
        $dueno = DB::table('owner')->insertGetId(['user_id' => $recibe->user_id, 'state' => 1], 'owner_id');
        DB::table('owner_busines')->insert(['owner_id' => $dueno, 'busines_id' => $negocio, 'state' => 1]);
        $cuerpo['busines_id'] = $negocio;
    }

    return test()->postJson('/v1/chats/send-message', $cuerpo);
}

/** Una tarjeta de pedido de `$productos` líneas, como la que arma la app. */
function tarjetaDePedido(int $productos, string $tipo = 'preorder'): string
{
    return json_encode([
        'type' => $tipo,
        'de' => 'cliente',
        'items' => array_map(fn ($i) => [
            'name' => "Producto número {$i} con nombre largo de los de verdad",
            'price' => 4600 + $i,
            'image' => 'https://media.vecipaya.com/catalogo/producto-'.$i.'.jpg',
        ], range(1, $productos)),
    ]);
}

test('un mensaje escrito corriente pasa', function () {
    mandar('¿Tiene leche deslactosada?')->assertCreated();
});

test('un texto de mas de mil letras se rechaza', function () {
    mandar(str_repeat('a', 1001))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['content']);
});

test('una tarjeta de pedido pasa aunque supere las mil letras', function () {
    // Es el caso que dio origen al tope doble: cuatro productos ya se pasaban.
    $tarjeta = tarjetaDePedido(20);

    expect(strlen($tarjeta))->toBeGreaterThan(1000);

    mandar($tarjeta, conNegocio: true)->assertCreated();
});

test('envolver un texto largo en un JSON cualquiera NO salta el tope', function () {
    /*
     * El agujero que la lista blanca cierra. Sin ella, `{"type":"loquesea"}`
     * con treinta mil caracteres entraba igual: bastaba con que el contenido
     * pareciera JSON.
     */
    mandar(tarjetaDePedido(20, 'loquesea'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['content']);
});

test('ni siquiera una tarjeta pasa de treinta mil', function () {
    mandar(json_encode(['type' => 'preorder', 'relleno' => str_repeat('a', 31000)]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['content']);
});
