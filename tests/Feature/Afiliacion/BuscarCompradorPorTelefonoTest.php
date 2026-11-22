<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * BUSCAR AL COMPRADOR POR SU TELÉFONO, ESCRITO COMO SEA.
 *
 * El fallo que trajo estas pruebas: la búsqueda comparaba el TEXTO EXACTO
 * (`where('phone', $phone)`), y los teléfonos están guardados con espacios
 * --«308 291 2460»-- mientras la pantalla que la usa pide el número seguido;
 * su propio marcador de posición dice «3001234567». El tendero escribía bien
 * el número de su cliente y le salía «no encontramos a nadie».
 *
 * Así que lo que se prueba no es que encuentre, sino que encuentre
 * ESCRIBIÉNDOLO DE CUALQUIERA DE LAS FORMAS en que una persona lo escribe,
 * y que siga sin encontrar lo que no existe.
 */

/** Un tendero con tienda --la puerta pide eso-- y un comprador con teléfono. */
function telEscenario(string $telefonoGuardado): array
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);

    $tendero = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId(['user_id' => $tendero->user_id, 'state' => 1], 'owner_id');

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'La tienda', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
        'latitude' => 11.0, 'longitude' => -74.8,
    ], 'busines_id');
    DB::table('owner_busines')->insert(['owner_id' => $ownerId, 'busines_id' => $negocio, 'state' => 1]);

    $comprador = User::factory()->create(['rol' => 1, 'phone' => $telefonoGuardado]);
    DB::table('buyer')->insert([
        'user_id' => $comprador->user_id, 'qualification' => 0, 'belongs_to_complex' => 0, 'state' => 1,
    ]);

    return compact('tendero', 'comprador');
}

function telBuscar(User $tendero, string $escrito)
{
    Sanctum::actingAs($tendero);

    return test()->getJson('/v1/businesses/affiliations/searchBusinesses?phone='.urlencode($escrito));
}

it('encuentra al comprador escriba el número como lo escriba', function (string $escrito) {
    $e = telEscenario('308 291 2460');

    telBuscar($e['tendero'], $escrito)
        ->assertOk()
        ->assertJsonPath('user.user_id', $e['comprador']->user_id);
})->with([
    'seguido, como lo pide la pantalla' => '3082912460',
    'con espacios, como está guardado' => '308 291 2460',
    'con indicativo' => '+57 308 291 2460',
    'con guiones' => '308-291-2460',
    'con paréntesis y espacios' => '(308) 291 2460',
]);

it('también lo encuentra cuando el sucio está en la base y no en lo escrito', function () {
    /* El otro lado del mismo problema: hay números guardados con indicativo.
       Limpiar sólo lo que llega dejaría a esa gente sin encontrar. */
    $e = telEscenario('+57 (308) 291-2460');

    telBuscar($e['tendero'], '3082912460')
        ->assertOk()
        ->assertJsonPath('user.user_id', $e['comprador']->user_id);
});

it('no encuentra a nadie con un número que no existe', function () {
    $e = telEscenario('308 291 2460');

    telBuscar($e['tendero'], '3009999999')->assertNotFound();
});

it('no confunde a dos personas cuyos números terminan parecido', function () {
    /* Se comparan los diez dígitos del móvil, no una cola cualquiera: dos
       números distintos que comparten el final NO son la misma persona. */
    $e = telEscenario('308 291 2460');
    telEscenario('301 291 2460');

    telBuscar($e['tendero'], '3012912460')
        ->assertOk()
        ->assertJsonPath('user.phone', '301 291 2460');
});

it('no devuelve a un tendero ni a quien no tiene ficha de comprador', function () {
    $e = telEscenario('308 291 2460');

    /* Mismo número, pero es un tendero: no es a quien se busca. */
    User::factory()->create(['rol' => 2, 'phone' => '305 111 2233']);

    telBuscar($e['tendero'], '3051112233')->assertNotFound();
});
