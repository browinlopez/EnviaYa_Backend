<?php

use App\Models\Domiciliary;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * BUSCAR A ALGUIEN ANTES DE CREARLE OTRA CUENTA.
 *
 * La pantalla de alta promete una cosa concreta: «vincúlalo sin crearle otra
 * cuenta». Si la búsqueda no encuentra a quien sí existe, esa promesa se
 * rompe en silencio y hacia el lado malo: el tendero lee «No hay nadie con
 * ese dato. Créalo abajo», lo crea, y a partir de ahí hay DOS fichas de la
 * misma persona, cada una con sus papeles, su efectivo y sus entregas.
 *
 * El teléfono en la base está de las dos maneras --«3002464977» y
 * «303 632 7859»-- porque lo escriben personas distintas en momentos
 * distintos. Nadie lo teclea con espacios al buscarlo.
 */
beforeEach(function () {
    /* Los roles son una tabla con clave ajena: sin ellos no nace ni un `user`. */
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);

    $this->tendero = User::factory()->create(['rol' => 2]);

    $owner = DB::table('owner')->insertGetId([
        'user_id' => $this->tendero->user_id,
        'document_number' => '900900900',
        'state' => 1,
    ]);

    $this->negocio = DB::table('business')->insertGetId([
        'name' => 'Tienda de la prueba',
        'state' => 1,
    ]);

    DB::table('owner_busines')->insert([
        'owner_id' => $owner,
        'busines_id' => $this->negocio,
        'state' => 1,
    ]);

    Sanctum::actingAs($this->tendero);
});

/** Deja un domiciliario con el teléfono escrito tal cual se pide. */
function domiciliarioConTelefono(string $telefono): Domiciliary
{
    $u = User::factory()->create(['rol' => 3, 'phone' => $telefono]);

    return Domiciliary::create([
        'user_id' => $u->user_id,
        'document' => '1098765432',
        'available' => 1,
        'state' => 1,
    ]);
}

it('encuentra a quien tiene el telefono con espacios cuando se busca seguido', function () {
    $d = domiciliarioConTelefono('303 632 7859');

    $r = $this->getJson('/v1/negocio/domiciliarios/buscar?q=3036327859');

    $r->assertOk();
    expect($r->json('data.domiciliary_id'))->toBe($d->domiciliary_id);
})->group('alta-de-domiciliario');

it('y al reves: guardado seguido, buscado con espacios', function () {
    // Pasa de verdad: se copia y se pega desde WhatsApp.
    $d = domiciliarioConTelefono('3002464977');

    $r = $this->getJson('/v1/negocio/domiciliarios/buscar?q=300 246 4977');

    expect($r->json('data.domiciliary_id'))->toBe($d->domiciliary_id);
})->group('alta-de-domiciliario');

it('tambien con guiones y con el indicativo', function () {
    $d = domiciliarioConTelefono('300-246-4977');

    expect($this->getJson('/v1/negocio/domiciliarios/buscar?q=3002464977')
        ->json('data.domiciliary_id'))->toBe($d->domiciliary_id);
})->group('alta-de-domiciliario');

it('no confunde a dos personas por parecerse el numero', function () {
    domiciliarioConTelefono('300 246 4977');

    $r = $this->getJson('/v1/negocio/domiciliarios/buscar?q=3002464000');

    expect($r->json('data'))->toBeNull();
})->group('alta-de-domiciliario');

it('dice si ya es de tu tienda, para no vincularlo dos veces', function () {
    $d = domiciliarioConTelefono('305 111 2233');

    DB::table('business_domiciliary')->insert([
        'busines_id' => $this->negocio,
        'domiciliary_id' => $d->domiciliary_id,
        'state' => 1,
    ]);

    $r = $this->getJson('/v1/negocio/domiciliarios/buscar?q=3051112233');

    expect($r->json('data.ya_es_tuyo'))->toBeTrue();
})->group('alta-de-domiciliario');
