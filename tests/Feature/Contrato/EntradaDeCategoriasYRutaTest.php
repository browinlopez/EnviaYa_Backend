<?php

/**
 * LA ENTRADA DE CINCO ENDPOINTS MÁS: categorías, la foto del conjunto y el
 * cálculo de ruta.
 *
 * Mismo acuerdo. Son los últimos que quedaban sin ningún motivo para no
 * moverse: los demás los veta el detector porque abortan antes de validar,
 * validan dentro de un `if`, o comparten reglas con otro método.
 */

use App\Models\Conjunto\ComplexStaff;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function rolesParaCategorias(): void
{
    foreach ([1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario', 4 => 'admin',
              5 => 'dueno_conjunto', 6 => 'celador'] as $id => $n) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $n, 'guard_name' => 'web']);
    }
}

/* ------------------------------------------------------------------ */
/* CATEGORÍAS                                                          */
/* ------------------------------------------------------------------ */

test('el listado libre de categorias exige el tipo de negocio', function () {
    /*
     * `type` no es un filtro opcional: las categorías de producto cuelgan
     * del tipo de negocio, así que sin él la lista no significa nada.
     */
    rolesParaCategorias();
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    $this->getJson('/v1/categories-free')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['type']);

    $this->getJson('/v1/categories-free?type=999999')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['type']);
});

test('corregir y borrar una categoria exigen una que exista', function () {
    rolesParaCategorias();
    Sanctum::actingAs(User::factory()->create(['rol' => 4]));

    $this->putJson('/v1/categories/update', ['category_id' => 999999])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);

    $this->deleteJson('/v1/categories/delete', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);
});

test('corregir una categoria que si existe no da error de validacion', function () {
    rolesParaCategorias();
    Sanctum::actingAs(User::factory()->create(['rol' => 4]));

    $id = DB::table('category')->insertGetId(['name' => 'Lácteos'], 'category_id');

    $this->putJson('/v1/categories/update', [
        'category_id' => $id, 'name' => 'Lácteos y huevos',
    ])->assertJsonMissingPath('errors.category_id');
});

/* ------------------------------------------------------------------ */
/* LA FOTO DEL CONJUNTO                                                */
/* ------------------------------------------------------------------ */

test('la foto del conjunto tiene que ser una imagen', function () {
    rolesParaCategorias();
    Storage::fake('public');

    $conjunto = DB::table('residential_complexes')->insertGetId([
        'name' => 'Alameda del Río', 'state' => 1,
    ], 'complex_id');

    $dueno = User::factory()->create(['rol' => ComplexStaff::ROL_DUENO]);
    ComplexStaff::create([
        'user_id' => $dueno->user_id, 'complex_id' => $conjunto,
        'role' => ComplexStaff::DUENO, 'state' => true,
    ]);
    Sanctum::actingAs($dueno);

    $this->postJson('/v1/conjunto/perfil/foto', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);

    $this->postJson('/v1/conjunto/perfil/foto', [
        'file' => UploadedFile::fake()->create('documento.pdf', 10),
    ])->assertStatus(422)->assertJsonValidationErrors(['file']);
});

/* ------------------------------------------------------------------ */
/* EL CÁLCULO DE RUTA                                                  */
/* ------------------------------------------------------------------ */

test('la ruta se pide con un pedido o con dos coordenadas, no con nada', function () {
    /*
     * `required_without:order_id`: o se nombra el pedido —y el servidor saca
     * de dónde a dónde— o se dan los dos puntos. El `regex` existe porque
     * «-74.79, 10.98» con espacio o al revés llegaba y producía una ruta
     * absurda en vez de un error.
     */
    rolesParaCategorias();
    Sanctum::actingAs(User::factory()->create(['rol' => 3]));

    $this->getJson('/v1/rutas')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['origen', 'destino']);
});

test('unas coordenadas mal escritas se rechazan en vez de dar una ruta absurda', function () {
    rolesParaCategorias();
    Sanctum::actingAs(User::factory()->create(['rol' => 3]));

    $this->getJson('/v1/rutas?origen=por%20ahi&destino=10.98,-74.79')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['origen']);
});
