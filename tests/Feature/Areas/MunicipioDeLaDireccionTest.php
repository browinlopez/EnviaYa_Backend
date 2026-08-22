<?php

use App\Models\Rol;
use App\Models\User;
use App\Services\MunicipioPorNombre;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * El municipio de una dirección creada desde la app.
 *
 * Estaba comentado en el controlador y la app nunca lo mandó, así que TODAS
 * quedaban con `municipality_id` nulo: la dirección de facturación que se le
 * manda a la pasarela salía sin ciudad, y cualquier consulta por municipio
 * dejaba fuera a esas direcciones.
 *
 * El dato existía y se tiraba —el teléfono ya resuelve las coordenadas a
 * ciudad y departamento para pintar la dirección—. Acá se comprueba que llega
 * y que se traduce bien, incluido lo que suele romper estas traducciones:
 * tildes, mayúsculas y nombres repetidos en departamentos distintos.
 */
function municipioDePrueba(string $nombre, string $departamento): int
{
    // El país y el departamento se reutilizan: las pruebas de nombres
    // repetidos crean dos municipios y volver a insertarlos choca con la
    // restricción de unicidad.
    $paisId = DB::table('countries')->where('iso_code', 'CO')->value('id')
        ?? DB::table('countries')->insertGetId(['name' => 'Colombia', 'iso_code' => 'CO']);

    $depId = DB::table('departments')->where('name', $departamento)->value('id')
        ?? DB::table('departments')->insertGetId([
            'name' => $departamento, 'country_id' => $paisId,
        ]);

    return DB::table('municipalities')->insertGetId([
        'name' => $nombre, 'department_id' => $depId,
    ]);
}

function compradorConSesionParaDireccion(): User
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $user = User::factory()->create(['rol' => 1]);
    DB::table('buyer')->insert(['user_id' => $user->user_id, 'state' => 1]);
    Sanctum::actingAs($user);

    return $user;
}

/* ------------------------- LA TRADUCCIÓN ------------------------------ */

test('reconoce el municipio por su nombre', function () {
    $id = municipioDePrueba('Barranquilla', 'Atlántico');

    expect(MunicipioPorNombre::resolver('Barranquilla'))->toBe($id);
});

test('no se le atraganta una tilde ni una mayúscula', function () {
    /*
     * El teléfono devuelve "Bogotá" y la base puede tener "BOGOTA". Comparar en
     * crudo dejaría sin municipio a direcciones perfectamente reconocibles.
     */
    $id = municipioDePrueba('BOGOTA', 'Cundinamarca');

    expect(MunicipioPorNombre::resolver('Bogotá'))->toBe($id)
        ->and(MunicipioPorNombre::resolver('  bogota  '))->toBe($id);
});

test('con nombres repetidos desempata por departamento', function () {
    $antioquia = municipioDePrueba('San Pedro', 'Antioquia');
    $sucre = municipioDePrueba('San Pedro', 'Sucre');

    expect(MunicipioPorNombre::resolver('San Pedro', 'Sucre'))->toBe($sucre)
        ->and(MunicipioPorNombre::resolver('San Pedro', 'Antioquia'))->toBe($antioquia);
});

test('si se repite y no se sabe el departamento, prefiere no adivinar', function () {
    /*
     * Una dirección sin municipio se puede completar después; una con el
     * municipio equivocado manda al domiciliario a otra ciudad.
     */
    municipioDePrueba('San Pedro', 'Antioquia');
    municipioDePrueba('San Pedro', 'Sucre');

    expect(MunicipioPorNombre::resolver('San Pedro'))->toBeNull();
});

test('un municipio que no está en la tabla devuelve nulo, no revienta', function () {
    expect(MunicipioPorNombre::resolver('Ciudad Inventada'))->toBeNull()
        ->and(MunicipioPorNombre::resolver(''))->toBeNull()
        ->and(MunicipioPorNombre::resolver(null))->toBeNull();
});

/* --------------------- GUARDANDO LA DIRECCIÓN -------------------------- */

test('la dirección se guarda con su municipio', function () {
    $id = municipioDePrueba('Barranquilla', 'Atlántico');
    $user = compradorConSesionParaDireccion();

    test()->postJson('/v1/users/addresses/add', [
        'user_id' => $user->user_id,
        'address' => 'Calle 84, Barranquilla, Atlántico',
        'municipality' => 'Barranquilla',
        'department' => 'Atlántico',
    ])->assertCreated();

    expect(DB::table('user_address')->where('user_id', $user->user_id)->value('municipality_id'))
        ->toBe($id);
});

test('un municipio desconocido no impide guardar la dirección', function () {
    /*
     * Rechazarla dejaría sin poder pedir a quien vive en un municipio que
     * todavía no está en la tabla, y la dirección del mapa —la que usa el
     * domiciliario— ya está completa.
     */
    $user = compradorConSesionParaDireccion();

    test()->postJson('/v1/users/addresses/add', [
        'user_id' => $user->user_id,
        'address' => 'Alguna calle, Pueblo Nuevo',
        'municipality' => 'Pueblo Que No Existe',
    ])->assertCreated();

    $fila = DB::table('user_address')->where('user_id', $user->user_id)->first();

    expect($fila)->not->toBeNull()
        ->and($fila->municipality_id)->toBeNull();
});
