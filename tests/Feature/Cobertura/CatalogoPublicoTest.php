<?php

use App\Models\Business;
use App\Models\Business\CategoryBusiness;
use App\Models\Owner\Owner;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * LO QUE EL CATÁLOGO PÚBLICO DEJA VER DE UN PROPIETARIO
 *
 * `top-businesses-free` no exige token: existe para que la app pueda mostrar
 * negocios antes de que alguien inicie sesión. Durante un tiempo devolvió,
 * junto a cada negocio, el tipo y número de documento de su dueño, su fecha
 * de nacimiento, su teléfono secundario y las notas internas del equipo.
 *
 * Es decir: cualquiera, sin cuenta, podía descargar la cédula de todos los
 * tenderos aliados de una sola petición.
 *
 * Esta prueba fija el arreglo. No comprueba que "hoy no salga": comprueba que
 * no pueda volver a salir sin que alguien lo note.
 */

beforeEach(function () {
    // `business.type` es clave foránea a `category_business`.
    CategoryBusiness::forceCreate(['name' => 'Tienda']);
});

test('el catálogo público no expone los datos personales del propietario', function () {
    $usuario = User::factory()->create();

    $propietario = Owner::forceCreate([
        'user_id'           => $usuario->user_id,
        'document_number'   => '1043663123',
        'birthdate'         => '1990-05-14',
        'contact_secondary' => '3009998877',
        'notes'             => 'Nota interna del equipo comercial',
        'state'             => 1,
    ]);

    $negocio = Business::forceCreate([
        'name'          => 'Tienda Doña Marta',
        'state'         => 1,
        'type'          => 1,
        'qualification' => 0,
    ]);

    DB::table('owner_busines')->insert([
        'owner_id'   => $propietario->owner_id,
        'busines_id' => $negocio->busines_id,
        'state'      => 1,
    ]);

    $respuesta = $this->getJson('/v1/top-businesses-free')->assertOk();
    $crudo = $respuesta->getContent();

    // `document_type` no se comprueba: nunca existió como columna —es
    // `document_type_id`— y por eso siempre salía en null.
    expect($crudo)->not->toContain('1043663123')
        ->and($crudo)->not->toContain('3009998877')
        ->and($crudo)->not->toContain('1990-05-14')
        ->and($crudo)->not->toContain('Nota interna del equipo comercial')
        ->and($crudo)->not->toContain('document_number')
        ->and($crudo)->not->toContain('birthdate')
        ->and($crudo)->not->toContain('contact_secondary');
});

test('el catálogo público sigue devolviendo lo que la app necesita', function () {
    // La app abre el chat con el tendero usando `owners[0].user_id`. Al
    // recortar los datos personales había que dejar ese campo intacto, o el
    // botón de "hablar con la tienda" se rompía sin que nadie lo notara.
    $usuario = User::factory()->create();

    $propietario = Owner::forceCreate([
        'user_id' => $usuario->user_id,
        'state'   => 1,
    ]);

    $negocio = Business::forceCreate([
        'name'          => 'Tienda Doña Marta',
        'state'         => 1,
        'type'          => 1,
        'qualification' => 0,
    ]);

    DB::table('owner_busines')->insert([
        'owner_id'   => $propietario->owner_id,
        'busines_id' => $negocio->busines_id,
        'state'      => 1,
    ]);

    $this->getJson('/v1/top-businesses-free')
        ->assertOk()
        ->assertJsonPath('businesses.0.name', 'Tienda Doña Marta')
        ->assertJsonPath('businesses.0.owners.0.user_id', $usuario->user_id)
        ->assertJsonPath('businesses.0.owner_count', 1);
});
