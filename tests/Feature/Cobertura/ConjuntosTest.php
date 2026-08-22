<?php

use App\Models\Area;
use App\Models\Buyer\Buyer;
use App\Models\Rol;
use App\Models\User;
use App\Models\User\UserAddress;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Los conjuntos por dentro.
 *
 * Un conjunto era una fila plana: nombre, dirección y un punto en el mapa. No
 * había noción de torres ni de apartamentos, y —lo que más pesaba— NINGÚN
 * vínculo entre una dirección y un conjunto. La dirección de quien vive en uno
 * era indistinguible de cualquier otra, así que no se podía responder "cuánto
 * vendimos en este conjunto".
 */

function gestorDeConjuntos(): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    $user = User::factory()->create(['rol' => 4]);
    $user->area_id      = Area::where('code', 'sistema')->first()->id;
    $user->access_level = Area::NIVEL_GESTOR;
    $user->save();

    return $user;
}

function conjunto(array $datos = []): int
{
    return DB::table('residential_complexes')->insertGetId(array_merge([
        'name' => 'Conjunto Los Almendros',
        'address' => 'Calle 84 #51-20',
        'state' => 1,
        'people_count' => 0,
        'towers_count' => 4,
        'apartments_per_tower' => 20,
    ], $datos), 'complex_id');
}

/* ---------------------------------------------------------------------- */

test('el listado publico dice cuantas torres tiene', function () {
    // Es lo que permite que el registro de la app ofrezca una lista en vez de
    // pedir que la persona escriba "Torre 3" a mano.
    conjunto();

    $this->getJson('/v1/complexes-free')
        ->assertOk()
        ->assertJsonPath('data.0.towers_count', 4)
        ->assertJsonPath('data.0.apartments_per_tower', 20);
});

test('el panel crea un conjunto con su estructura', function () {
    Sanctum::actingAs(gestorDeConjuntos());

    $this->postJson('/v1/admin/complexes', [
        'name' => 'Torres del Parque',
        'towers_count' => 6,
        'apartments_per_tower' => 15,
    ])->assertCreated();

    $c = DB::table('residential_complexes')->where('name', 'Torres del Parque')->first();

    expect((int) $c->towers_count)->toBe(6)
        ->and((int) $c->apartments_per_tower)->toBe(15);
});

test('no acepta cero torres', function () {
    Sanctum::actingAs(gestorDeConjuntos());

    $this->postJson('/v1/admin/complexes', [
        'name' => 'Imposible', 'towers_count' => 0,
    ])->assertStatus(422);
});

test('una direccion guarda conjunto, torre y apartamento', function () {
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    $user = User::factory()->create(['rol' => 1]);
    Sanctum::actingAs($user);

    $complexId = conjunto();

    $this->postJson('/v1/users/addresses/add', [
        'user_id' => $user->user_id,
        'address' => 'Calle 84 #51-20',
        'complex_id' => $complexId,
        'tower' => '3',
        'apartment' => '502',
    ])->assertCreated();

    $d = UserAddress::where('user_id', $user->user_id)->first();

    expect((int) $d->complex_id)->toBe($complexId)
        ->and($d->tower)->toBe('3')
        ->and($d->apartment)->toBe('502');
});

test('la torre admite letras', function () {
    // Hay conjuntos con "Torre A" y apartamentos "502B". Guardarlos como
    // números obligaría a inventar una traducción.
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    $user = User::factory()->create(['rol' => 1]);
    Sanctum::actingAs($user);

    $this->postJson('/v1/users/addresses/add', [
        'user_id' => $user->user_id,
        'address' => 'Calle 84',
        'complex_id' => conjunto(),
        'tower' => 'A',
        'apartment' => '502B',
    ])->assertCreated();

    expect(UserAddress::first()->tower)->toBe('A');
});

test('una direccion fuera de conjunto sigue funcionando', function () {
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    $user = User::factory()->create(['rol' => 1]);
    Sanctum::actingAs($user);

    $this->postJson('/v1/users/addresses/add', [
        'user_id' => $user->user_id,
        'address' => 'Carrera 10 #20-30',
        'street' => 'Carrera 10 #20-30',
    ])->assertCreated();

    expect(UserAddress::first()->complex_id)->toBeNull();
});

test('registrarse en un conjunto marca la bandera del comprador', function () {
    /*
     * La bandera se validaba, se usaba para decidir si crear la fila del
     * pivote… y no se guardaba. Todo comprador que declaraba vivir en un
     * conjunto quedaba con `belongs_to_complex = 0` mientras `buyer_complex`
     * decía que sí, y el tendero veía la versión equivocada.
     */
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $complexId = conjunto();

    $this->postJson('/v1/register', [
        'name' => 'Camila Restrepo',
        'email' => 'camila@ejemplo.test',
        'password' => 'ClaveDePrueba1*',
        'phone' => '3001234567',
        'belongs_to_complex' => 1,
        'complex_id' => $complexId,
    ])->assertCreated();

    $buyer = Buyer::whereHas('user', fn ($q) => $q->where('email', 'camila@ejemplo.test'))->first();

    expect((int) $buyer->belongs_to_complex)->toBe(1);

    // Y el pivote sigue diciendo lo mismo, que era el punto.
    expect(DB::table('buyer_complex')->where('buyer_id', $buyer->buyer_id)->exists())
        ->toBeTrue();
});

test('quien no vive en conjunto queda con la bandera en cero', function () {
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $this->postJson('/v1/register', [
        'name' => 'Sin Conjunto',
        'email' => 'solo@ejemplo.test',
        'password' => 'ClaveDePrueba1*',
        'belongs_to_complex' => 0,
    ])->assertCreated();

    $buyer = Buyer::whereHas('user', fn ($q) => $q->where('email', 'solo@ejemplo.test'))->first();

    expect((int) $buyer->belongs_to_complex)->toBe(0);
});
