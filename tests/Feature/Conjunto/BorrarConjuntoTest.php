<?php

/**
 * BORRAR UN CONJUNTO NO PUEDE LLEVARSE COSAS POR DELANTE.
 *
 * Las foráneas de `complex_staff` y `complex_entries` van EN CASCADA. Antes se
 * comprobaba solo que no hubiera residentes vinculados, así que borrar un
 * conjunto con portería en marcha borraba también, sin decirlo:
 *
 *  · a su administrador y a sus celadores — la cuenta quedaba viva y sin
 *    conjunto, entraba al panel de aliados y se le rechazaba;
 *  · **la bitácora entera de la portería**: quién entró, cuándo y con qué
 *    método. Eso es el control del conjunto, no un dato accesorio.
 *
 * Comprobado antes de arreglarlo: con una ficha de personal y una entrada,
 * después del borrado quedaban cero de las dos y la cuenta del dueño seguía
 * ahí con `rol = 5` y ninguna ficha.
 *
 * La incoherencia era además interna: una pantalla más allá, el propio panel
 * se niega a BORRAR a un celador —«tienen entradas registradas a su nombre»— y
 * solo lo desactiva. Acá se aplica el mismo criterio.
 */

use App\Models\Area;
use App\Models\Buyer\ResidentialComplex;
use App\Models\Conjunto\ComplexStaff;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function comoQuienBorra(): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 5], ['name' => 'dueno_conjunto', 'guard_name' => 'web']);

    $u = User::factory()->create([
        'rol'          => 4,
        'area_id'      => Area::where('code', 'sistema')->firstOrFail()->id,
        'access_level' => Area::NIVEL_GESTOR,
    ]);

    Sanctum::actingAs($u);

    return $u;
}

function unConjuntoVacio(string $nombre = 'Los Cerezos'): ResidentialComplex
{
    return ResidentialComplex::create([
        'name' => $nombre, 'address' => 'Calle 1', 'state' => 1,
    ]);
}

/* ===================================================================== */

test('un conjunto sin nada detras se borra', function () {
    comoQuienBorra();
    $conjunto = unConjuntoVacio();

    $this->deleteJson("/v1/admin/complexes/{$conjunto->complex_id}")->assertOk();

    expect(ResidentialComplex::find($conjunto->complex_id))->toBeNull();
});

test('uno que no existe da 404 y no un 200 mentiroso', function () {
    comoQuienBorra();

    $this->deleteJson('/v1/admin/complexes/99999')->assertNotFound();
});

test('con personal a cargo de su porteria no se borra', function () {
    comoQuienBorra();
    $conjunto = unConjuntoVacio();

    $celador = User::factory()->create(['rol' => ComplexStaff::ROL_CELADOR]);
    ComplexStaff::create([
        'user_id' => $celador->user_id, 'complex_id' => $conjunto->complex_id,
        'role' => ComplexStaff::CELADOR, 'state' => true,
    ]);

    $r = $this->deleteJson("/v1/admin/complexes/{$conjunto->complex_id}")
        ->assertStatus(422);

    expect($r->json('message'))->toContain('portería');

    // Y sigue todo en su sitio: ni el conjunto ni su gente.
    expect(ResidentialComplex::find($conjunto->complex_id))->not->toBeNull();
    expect(ComplexStaff::where('complex_id', $conjunto->complex_id)->count())->toBe(1);
});

test('con entradas registradas tampoco: son la constancia de quien entro', function () {
    comoQuienBorra();
    $conjunto = unConjuntoVacio();

    DB::table('complex_entries')->insert([
        'complex_id' => $conjunto->complex_id,
        'domiciliary_id' => null,
        'visitor_name' => 'Alguien',
        'method' => 'cedula',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $r = $this->deleteJson("/v1/admin/complexes/{$conjunto->complex_id}")
        ->assertStatus(422);

    expect($r->json('message'))->toContain('entrada');
    expect(DB::table('complex_entries')->where('complex_id', $conjunto->complex_id)->count())->toBe(1);
});

test('con residentes vinculados sigue sin borrarse, como antes', function () {
    comoQuienBorra();
    $conjunto = unConjuntoVacio();

    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    $vecino  = User::factory()->create(['rol' => 1]);
    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $vecino->user_id, 'qualification' => 0,
        'belongs_to_complex' => 1, 'state' => 1,
    ]);
    DB::table('buyer_complex')->insert([
        'buyer_id' => $buyerId, 'complex_id' => $conjunto->complex_id,
    ]);

    $this->deleteJson("/v1/admin/complexes/{$conjunto->complex_id}")
        ->assertStatus(422);

    expect(ResidentialComplex::find($conjunto->complex_id))->not->toBeNull();
});
