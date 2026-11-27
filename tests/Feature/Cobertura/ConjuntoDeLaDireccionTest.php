<?php

use App\Http\Resources\Pedido\PedidoDeLaAppResource;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

// Sin el conjunto en la dirección, la portería rechaza al domiciliario.
function residenteDe(?int $complexId = null): array
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    $user = User::factory()->create(['rol' => 1]);
    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $user->user_id, 'qualification' => 0, 'state' => 1,
        'belongs_to_complex' => $complexId ? 1 : 0,
    ], 'buyer_id');

    if ($complexId) {
        DB::table('buyer_complex')->insert(['buyer_id' => $buyerId, 'complex_id' => $complexId]);
    }

    return ['user' => $user, 'buyerId' => $buyerId];
}

function conjuntoConPunto(): int
{
    return DB::table('residential_complexes')->insertGetId([
        'name' => 'Conjunto Los Almendros', 'address' => 'Calle 84 #51-20',
        'state' => 1, 'people_count' => 0, 'towers_count' => 4, 'apartments_per_tower' => 20,
        'latitude' => 10.9900000, 'longitude' => -74.8000000,
    ], 'complex_id');
}

test('la lista de direcciones dice el conjunto, la torre y el apartamento', function () {
    $c = conjuntoConPunto();
    $r = residenteDe($c);
    DB::table('user_address')->insert([
        'user_id' => $r['user']->user_id, 'address' => 'Calle 84 #51-20',
        'complex_id' => $c, 'tower' => '2', 'apartment' => '301', 'state' => 1,
    ]);

    Sanctum::actingAs($r['user']);

    $this->postJson('/v1/users/addresses', ['user_id' => $r['user']->user_id])
        ->assertOk()
        ->assertJsonPath('0.complex_id', $c)
        ->assertJsonPath('0.complex_name', 'Conjunto Los Almendros')
        ->assertJsonPath('0.tower', '2')
        ->assertJsonPath('0.apartment', '301');
});

test('guardar una dirección en un conjunto une al comprador a ese conjunto', function () {
    $c = conjuntoConPunto();
    $r = residenteDe();

    Sanctum::actingAs($r['user']);

    $this->postJson('/v1/users/addresses/add', [
        'user_id' => $r['user']->user_id, 'address' => 'Calle 84 #51-20',
        'complex_id' => $c, 'tower' => '3', 'apartment' => '12',
    ])->assertCreated();

    expect(DB::table('buyer_complex')->where('buyer_id', $r['buyerId'])->where('complex_id', $c)->exists())->toBeTrue()
        ->and((int) DB::table('buyer')->where('buyer_id', $r['buyerId'])->value('belongs_to_complex'))->toBe(1);
});

test('«mi ubicación» en la puerta del conjunto hereda el conjunto, la torre y el apartamento', function () {
    $c = conjuntoConPunto();
    $r = residenteDe($c);
    DB::table('user_address')->insert([
        'user_id' => $r['user']->user_id, 'address' => 'Calle 84 #51-20',
        'complex_id' => $c, 'tower' => '2', 'apartment' => '301', 'state' => 1,
    ]);

    Sanctum::actingAs($r['user']);

    // A unos 50 metros del punto del conjunto, sin conjunto en la petición.
    $this->postJson('/v1/users/addresses/add', [
        'user_id' => $r['user']->user_id, 'address' => 'Mi ubicación actual',
        'latitude' => 10.9904, 'longitude' => -74.8001,
    ])->assertCreated()
        ->assertJsonPath('complex_id', $c)
        ->assertJsonPath('tower', '2')
        ->assertJsonPath('apartment', '301');
});

test('lejos del conjunto, la dirección nueva no se lo inventa', function () {
    $c = conjuntoConPunto();
    $r = residenteDe($c);
    DB::table('user_address')->insert([
        'user_id' => $r['user']->user_id, 'address' => 'Calle 84 #51-20',
        'complex_id' => $c, 'tower' => '2', 'apartment' => '301', 'state' => 1,
    ]);

    Sanctum::actingAs($r['user']);

    // Unos dos kilómetros más allá: es el trabajo, no la casa.
    $this->postJson('/v1/users/addresses/add', [
        'user_id' => $r['user']->user_id, 'address' => 'Oficina',
        'latitude' => 11.0080, 'longitude' => -74.8000,
    ])->assertCreated()
        ->assertJsonPath('complex_id', null);
});

test('no se guarda una dirección a nombre de otra persona', function () {
    $r = residenteDe();
    $otro = residenteDe();

    Sanctum::actingAs($r['user']);

    $this->postJson('/v1/users/addresses/add', [
        'user_id' => $otro['user']->user_id, 'address' => 'Calle falsa 123',
    ])->assertForbidden();
});

test('al domiciliario le llegan la torre y el apartamento en la dirección del pedido', function () {
    $torreYApto = new ReflectionMethod(PedidoDeLaAppResource::class, 'torreYApto');

    expect($torreYApto->invoke(null, '2', '301'))->toBe('Torre 2, apto 301')
        ->and($torreYApto->invoke(null, ' A ', null))->toBe('Torre A')
        ->and($torreYApto->invoke(null, null, null))->toBeNull();
});

test('una torre que el conjunto no tiene se rechaza; una con letra pasa', function () {
    $c = conjuntoConPunto(); // 4 torres
    $r = residenteDe();
    Sanctum::actingAs($r['user']);

    $this->postJson('/v1/users/addresses/add', [
        'user_id' => $r['user']->user_id, 'address' => 'Calle 84',
        'complex_id' => $c, 'tower' => '7', 'apartment' => '301',
    ])->assertStatus(422)->assertJsonValidationErrors('tower');

    $this->postJson('/v1/users/addresses/add', [
        'user_id' => $r['user']->user_id, 'address' => 'Calle 84',
        'complex_id' => $c, 'tower' => 'A', 'apartment' => '301',
    ])->assertCreated();

    // El apartamento no se compara con los «por torre»: un 301 real pasa.
    $this->postJson('/v1/users/addresses/add', [
        'user_id' => $r['user']->user_id, 'address' => 'Calle 84',
        'complex_id' => $c, 'tower' => '4', 'apartment' => '301',
    ])->assertCreated();
});
