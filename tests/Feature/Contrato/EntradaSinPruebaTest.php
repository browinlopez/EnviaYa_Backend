<?php

/**
 * LA RED QUE FALTABA EN TRES ENDPOINTS.
 *
 * Al pasar la validación en línea a `FormRequest` hay que poder demostrar que
 * el 422 sigue diciendo lo mismo: el cuerpo de un error de validación es una
 * respuesta como cualquier otra, y la app y los paneles la leen.
 *
 * Estos tres no tenían ninguna prueba que los llamara —se vio al cruzar las
 * rutas con la suite POR VERBO Y URI, que es como son las rutas; cruzando solo
 * la URI, un `GET /admin/categories` hacía pasar por cubierto a su `PUT`—. Así
 * que primero se fija lo que hacen hoy y después se mueve nada.
 *
 * No prueban la lógica de cada endpoint: prueban su ENTRADA. Qué campos son
 * obligatorios, qué valores se rechazan y con qué forma sale el error. Es lo
 * único que el cambio a `FormRequest` podía estropear.
 */

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** Un administrador con los módulos que se le pidan. */
function administradorCon(array $modulos): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    $area = Area::create([
        'code' => 'ent_'.substr(md5(implode(',', $modulos)), 0, 8),
        'name' => 'Área de prueba',
        'state' => 1,
    ]);

    $area->guardarPermisos(
        collect($modulos)->mapWithKeys(fn ($m) => [$m => ['view' => true, 'manage' => true]])->all(),
    );

    $u = User::factory()->create([
        'rol' => 4, 'area_id' => $area->id, 'access_level' => Area::NIVEL_GESTOR,
    ]);

    Sanctum::actingAs($u);

    return $u;
}

/* ------------------------------------------------------------------ */
/* PUT /v1/admin/categories */
/* ------------------------------------------------------------------ */

test('corregir una categoria exige scope, id y nombre', function () {
    administradorCon(['categorias', 'categorias-negocio']);

    $this->putJson('/v1/admin/categories', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['scope', 'id', 'name']);
});

test('un scope desconocido lo para el middleware, no la validacion', function () {
    /*
     * Esto lo descubrió la propia prueba, y es cierto desde antes de tocar
     * nada: la regla `in:product,business` del FormRequest NO llega a correr
     * con un scope inventado, porque `EnsureCategoryAccess` ya lo rechazó
     * —tiene que resolver el scope para saber qué módulo exigir, así que lo
     * mira primero—.
     *
     * Se fija el comportamiento real y no el que uno esperaría: 422 con un
     * `message` propio y sin `errors.scope`. Si algún día el middleware deja
     * de mirarlo, esta prueba falla y obliga a decidir cuál de los dos manda,
     * en vez de que la respuesta cambie de forma en silencio.
     */
    administradorCon(['categorias', 'categorias-negocio']);

    $this->putJson('/v1/admin/categories', ['scope' => 'inventado', 'id' => 1, 'name' => 'X'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'No existe un catálogo de categorías "inventado".')
        ->assertJsonMissingPath('errors.scope');
});

test('corregir una categoria de negocio que existe devuelve 200', function () {
    administradorCon(['categorias', 'categorias-negocio']);

    $id = DB::table('category_business')->insertGetId(['name' => 'Ferretería'], 'id');

    $this->putJson('/v1/admin/categories', [
        'scope' => 'business', 'id' => $id, 'name' => 'Ferretería y pinturas',
    ])->assertOk();

    expect(DB::table('category_business')->where('id', $id)->value('name'))
        ->toBe('Ferretería y pinturas');
});

/* ------------------------------------------------------------------ */
/* DELETE /v1/admin/categories */
/* ------------------------------------------------------------------ */

test('borrar una categoria exige scope e id', function () {
    administradorCon(['categorias', 'categorias-negocio']);

    $this->deleteJson('/v1/admin/categories', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['scope', 'id']);
});

test('borrar una categoria que no existe devuelve 404 y no 422', function () {
    // La diferencia importa: 422 es «lo que mandaste está mal escrito» y 404
    // es «está bien escrito y no hay nada ahí». Confundirlos manda al panel a
    // enseñar un error de formulario sobre un campo correcto.
    administradorCon(['categorias', 'categorias-negocio']);

    $this->deleteJson('/v1/admin/categories', ['scope' => 'business', 'id' => 999999])
        ->assertStatus(404);
});

/* ------------------------------------------------------------------ */
/* PUT /v1/admin/complexes/{id} */
/* ------------------------------------------------------------------ */

test('corregir un conjunto acepta un cuerpo parcial', function () {
    // Todo es `sometimes` a propósito: el panel manda solo lo que cambió. Un
    // cuerpo vacío no es un error, es «no cambies nada».
    administradorCon(['conjuntos']);

    $id = DB::table('residential_complexes')->insertGetId([
        'name' => 'Alameda del Río', 'state' => 1,
    ], 'complex_id');

    $this->putJson("/v1/admin/complexes/{$id}", ['name' => 'Alameda del Río II'])
        ->assertOk();

    expect(DB::table('residential_complexes')->where('complex_id', $id)->value('name'))
        ->toBe('Alameda del Río II');
});

test('las coordenadas de un conjunto se validan dentro del mundo', function () {
    // Una latitud de 120 no existe. Sin el tope, el conjunto se guarda y
    // aparece fuera del mapa, que es un fallo que nadie relaciona con esto.
    administradorCon(['conjuntos']);

    $id = DB::table('residential_complexes')->insertGetId([
        'name' => 'Torres del Parque', 'state' => 1,
    ], 'complex_id');

    $this->putJson("/v1/admin/complexes/{$id}", ['latitude' => 120, 'longitude' => -74.8])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['latitude']);
});

test('el correo de un conjunto tiene que parecer un correo', function () {
    administradorCon(['conjuntos']);

    $id = DB::table('residential_complexes')->insertGetId([
        'name' => 'Villa Campestre', 'state' => 1,
    ], 'complex_id');

    $this->putJson("/v1/admin/complexes/{$id}", ['email' => 'esto-no-es-un-correo'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});
