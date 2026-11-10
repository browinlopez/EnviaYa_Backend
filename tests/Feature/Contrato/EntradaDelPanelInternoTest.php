<?php

/**
 * LA ENTRADA DE CINCO ENDPOINTS DEL PANEL INTERNO.
 *
 * Mismo acuerdo que en los dos archivos hermanos: ninguno tenía una prueba
 * que lo llamara, así que primero se fija qué acepta y después se extrae la
 * regla a un `FormRequest`.
 *
 * Firmar el acuerdo de un domiciliario, darlo de alta, crear un propietario y
 * borrar reseñas —una y en lote—.
 */

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/** Un administrador con permiso de gestión sobre los módulos que se le pidan. */
function gestorDe(array $modulos): User
{
    foreach ([1 => 'comprador', 2 => 'tendero', 3 => 'domiciliario', 4 => 'admin'] as $id => $n) {
        Rol::firstOrCreate(['rol_id' => $id], ['name' => $n, 'guard_name' => 'web']);
    }

    $area = Area::create([
        'code' => 'ctr_'.substr(md5(implode(',', $modulos)), 0, 8),
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
/* POST /v1/admin/domiciliaries/{id}/contrato                          */
/* ------------------------------------------------------------------ */

test('firmar el acuerdo exige la firma y la ciudad', function () {
    /*
     * La ciudad no es un adorno: el documento dice «se firma en la ciudad
     * de ___» y, si hubiera que reexpedirlo, tiene que salir igual.
     */
    gestorDe(['domiciliarios']);

    $this->postJson('/v1/admin/domiciliaries/1/contrato', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['signature', 'city']);
});

test('una ciudad de mas de 120 caracteres se rechaza', function () {
    gestorDe(['domiciliarios']);

    $this->postJson('/v1/admin/domiciliaries/1/contrato', [
        'signature' => 'data:image/png;base64,iVBORw0KGgo=',
        'city' => str_repeat('a', 121),
    ])->assertStatus(422)->assertJsonValidationErrors(['city']);
});

/* ------------------------------------------------------------------ */
/* POST /v1/admin/domiciliaries                                        */
/* ------------------------------------------------------------------ */

test('dar de alta un domiciliario: o una cuenta que existe, o sus datos', function () {
    /*
     * `required_without:user_id` en nombre, correo y contraseña. Son dos
     * caminos: atar a alguien que ya tiene cuenta, o crearla. Sin ninguno de
     * los dos no hay a quién dar de alta.
     */
    gestorDe(['domiciliarios']);

    $this->postJson('/v1/admin/domiciliaries', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'email']);
});

test('con una cuenta que ya existe no se piden sus datos otra vez', function () {
    gestorDe(['domiciliarios']);

    $ya = User::factory()->create(['rol' => 3]);

    $this->postJson('/v1/admin/domiciliaries', ['user_id' => $ya->user_id])
        ->assertJsonMissingPath('errors.name')
        ->assertJsonMissingPath('errors.email');
});

test('un correo que ya tiene cuenta no se puede reusar al crear uno nuevo', function () {
    gestorDe(['domiciliarios']);

    $ocupado = User::factory()->create(['rol' => 1, 'email' => 'tomado@ejemplo.com']);

    $this->postJson('/v1/admin/domiciliaries', [
        'name' => 'Carlos Mendoza',
        'email' => $ocupado->email,
        'password' => 'unaClaveLarga',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);
});

/* ------------------------------------------------------------------ */
/* POST /v1/admin/owners                                               */
/* ------------------------------------------------------------------ */

test('crear un propietario exige nombre, correo y contrasena', function () {
    gestorDe(['propietarios']);

    $this->postJson('/v1/admin/owners', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'email', 'password']);
});

test('el correo de un propietario tiene que ser unico y parecer un correo', function () {
    gestorDe(['propietarios']);

    $this->postJson('/v1/admin/owners', [
        'name' => 'Marta Ruiz', 'email' => 'esto-no-es-un-correo', 'password' => 'secreta123',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);

    $ocupado = User::factory()->create(['rol' => 1, 'email' => 'duena@ejemplo.com']);

    $this->postJson('/v1/admin/owners', [
        'name' => 'Marta Ruiz', 'email' => $ocupado->email, 'password' => 'secreta123',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);
});

/* ------------------------------------------------------------------ */
/* DELETE /v1/admin/reviews  y  /lote                                  */
/* ------------------------------------------------------------------ */

test('borrar una resena exige saber de cual de los tres catalogos', function () {
    // `scope` decide sobre qué tabla se borra. Un valor libre aquí no es un
    // campo mal puesto: es un borrado sin destino.
    gestorDe(['resenas']);

    $this->deleteJson('/v1/admin/reviews', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['scope', 'reviews_id']);

    $this->deleteJson('/v1/admin/reviews', ['scope' => 'inventado', 'reviews_id' => 1])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['scope']);
});

test('el borrado en lote tiene tope de cien', function () {
    /*
     * Cien y no «las que sean». Un lote sin tope es un borrado masivo a un
     * descuido de distancia, y borrar reseñas no se deshace.
     */
    gestorDe(['resenas']);

    $this->deleteJson('/v1/admin/reviews/lote', [
        'scope' => 'business', 'ids' => range(1, 101),
    ])->assertStatus(422)->assertJsonValidationErrors(['ids']);
});

test('el borrado en lote exige al menos una resena', function () {
    gestorDe(['resenas']);

    $this->deleteJson('/v1/admin/reviews/lote', ['scope' => 'business', 'ids' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ids']);
});
