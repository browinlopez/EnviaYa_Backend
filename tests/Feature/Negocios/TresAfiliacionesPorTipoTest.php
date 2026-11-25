<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
 * EL TOPE DE AFILIACIONES ES POR TIPO DE NEGOCIO.
 *
 * Tres tiendas, tres droguerías, tres restaurantes… y no tres en total:
 * quien era cliente de su tienda y de dos droguerías se quedaba sin poder
 * afiliarse a ningún restaurante.
 */
function compradorParaAfiliar(): User
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    $u = User::factory()->create(['rol' => 1]);
    DB::table('buyer')->insert(['user_id' => $u->user_id, 'qualification' => 0, 'state' => 1]);

    return $u;
}

function negociosDeTipo(string $nombre, int $cuantos): array
{
    $tipo = DB::table('category_business')->insertGetId(['name' => $nombre], 'id');

    return array_map(
        fn ($i) => DB::table('business')->insertGetId([
            'name' => "$nombre $i", 'qualification' => 0, 'state' => 1, 'type' => $tipo,
        ], 'busines_id'),
        range(1, $cuantos),
    );
}

function afiliarA(User $u, int $negocio)
{
    return test()->postJson('/v1/businesses/affiliations/AfiliationUser', [
        'user_id' => $u->user_id, 'busines_id' => $negocio,
    ]);
}

test('el cuarto negocio del mismo tipo no entra y dice por qué', function () {
    $yo = compradorParaAfiliar();
    Sanctum::actingAs($yo);
    $droguerias = negociosDeTipo('Farmacia', 4);

    foreach (array_slice($droguerias, 0, 3) as $d) {
        afiliarA($yo, $d)->assertStatus(201);
    }

    afiliarA($yo, $droguerias[3])
        ->assertStatus(422)
        ->assertJsonPath('limite', 3);
});

test('tener tres de un tipo no impide afiliarse a otro tipo', function () {
    $yo = compradorParaAfiliar();
    Sanctum::actingAs($yo);
    $tiendas = negociosDeTipo('Tienda', 3);
    [$restaurante] = negociosDeTipo('Restaurante', 1);

    foreach ($tiendas as $t) {
        afiliarA($yo, $t)->assertStatus(201);
    }

    afiliarA($yo, $restaurante)->assertStatus(201);

    expect(DB::table('business_user_affiliations')
        ->where('user_id', $yo->user_id)->count())->toBe(4);
});
