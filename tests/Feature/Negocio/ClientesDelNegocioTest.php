<?php

use App\Models\Business\BusinessUserAffiliation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * LOS CLIENTES AFILIADOS A LA TIENDA
 *
 * Afiliar es lo que convierte a un comprador cualquiera en cliente de ESTA
 * tienda: quien recibe sus promociones y a quien se le puede fiar.
 *
 * La prueba que más importa de este archivo es la del buscador por teléfono.
 * Devolvía el usuario entero a cualquier cuenta autenticada, así que probando
 * números se sacaba de cada persona su dirección, la IP desde la que aceptó la
 * política de datos y su token de verificación de correo.
 */

/** Un comprador de verdad, con su ficha. */
function compradorConTelefono(string $telefono): User
{
    \App\Models\Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $u = User::factory()->create(['rol' => 1, 'phone' => $telefono, 'address' => 'Cra 43 #76-120']);

    DB::table('buyer')->insert([
        'user_id' => $u->user_id, 'qualification' => 0, 'state' => 1,
    ]);

    return $u;
}

/* ---------------------------------------------------------------------- */

test('el tendero encuentra a un comprador por su teléfono', function () {
    $t = tenderoConCatalogo();
    $cliente = compradorConTelefono('3001112233');

    Sanctum::actingAs($t['user']);

    $r = $this->getJson('/v1/negocio/clientes/buscar?telefono=3001112233')->assertSuccessful();

    expect($r->json('cliente.user_id'))->toBe($cliente->user_id)
        ->and($r->json('cliente.afiliado'))->toBeFalse();
});

test('el buscador NO devuelve más de lo que hace falta para reconocer a alguien', function () {
    /*
     * Acá hay una persona que todavía no es cliente de nadie, encontrada por
     * un número de teléfono. Para decidir «sí, es esta» basta el nombre y el
     * correo; su dirección y su token de verificación no son asunto de quien
     * pregunta.
     */
    $t = tenderoConCatalogo();
    compradorConTelefono('3001112233');

    Sanctum::actingAs($t['user']);

    $cliente = $this->getJson('/v1/negocio/clientes/buscar?telefono=3001112233')->json('cliente');

    expect(array_keys($cliente))->toEqualCanonicalizing([
        'user_id', 'name', 'email', 'phone', 'afiliado',
    ]);
});

test('la ruta vieja de la app tampoco vuelca el usuario entero', function () {
    // La usa la pantalla del tendero en el teléfono, y enseña nombre y correo.
    $t = tenderoConCatalogo();
    compradorConTelefono('3001112233');

    Sanctum::actingAs($t['user']);

    $r = $this->getJson('/v1/businesses/affiliations/searchBusinesses?phone=3001112233')
        ->assertSuccessful();

    expect(array_keys($r->json('user')))->toEqualCanonicalizing([
        'user_id', 'name', 'email', 'phone',
    ]);

    // Lo que de verdad no podía salir.
    expect($r->json())->not->toHaveKey('user.address')
        ->and($r->json())->not->toHaveKey('user.email_verification_token')
        ->and($r->json())->not->toHaveKey('user.policy_ip');
});

test('un comprador no puede ir probando teléfonos ajenos', function () {
    $comprador = compradorConTelefono('3009998877');
    compradorConTelefono('3001112233');

    Sanctum::actingAs($comprador);

    $this->getJson('/v1/businesses/affiliations/searchBusinesses?phone=3001112233')
        ->assertStatus(403);
});

test('un teléfono que no existe se dice, y no se inventa un cliente', function () {
    $t = tenderoConCatalogo();
    Sanctum::actingAs($t['user']);

    $this->getJson('/v1/negocio/clientes/buscar?telefono=3000000000')
        ->assertStatus(404)
        ->assertJsonPath('cliente', null);
});

test('afiliar deja al comprador como cliente de la tienda, y repetirlo no rompe', function () {
    $t = tenderoConCatalogo();
    $cliente = compradorConTelefono('3001112233');

    Sanctum::actingAs($t['user']);

    $this->postJson("/v1/negocio/clientes/{$cliente->user_id}")->assertStatus(201);
    $this->postJson("/v1/negocio/clientes/{$cliente->user_id}")->assertStatus(201);

    expect(
        BusinessUserAffiliation::where('busines_id', $t['mio'])
            ->where('user_id', $cliente->user_id)
            ->count(),
    )->toBe(1);
});

test('no se afilia a quien no es comprador', function () {
    $t = tenderoConCatalogo();
    $otroTendero = tenderoConCatalogo();

    Sanctum::actingAs($t['user']);

    $this->postJson("/v1/negocio/clientes/{$otroTendero['user']->user_id}")->assertStatus(404);
});

test('desafiliar NO borra la deuda: la dice', function () {
    /*
     * Desafiliar es dejar de mandarle promociones y de poder fiarle de nuevo,
     * no perdonarle lo que debe. Si el botón borrara la deuda, un toque mal
     * dado regalaría plata.
     */
    $t = tenderoConCatalogo();
    $cliente = compradorConTelefono('3001112233');

    // Sin tope de crédito, la tienda no puede fiar: lo pone el equipo.
    DB::table('business')->where('busines_id', $t['mio'])->update(['credit_debt_cap' => 500000]);

    Sanctum::actingAs($t['user']);
    $this->postJson("/v1/negocio/clientes/{$cliente->user_id}")->assertStatus(201);

    // Se le da cupo y se le fía.
    $this->putJson("/v1/negocio/creditos/{$cliente->user_id}", ['credit_limit' => 100000])
        ->assertSuccessful();

    // Se le anota una compra fiada en su libro: es lo que hace `consumir()`
    // cuando el pedido se paga con crédito de tienda.
    $credito = \App\Models\Operacion\StoreCredit::where('busines_id', $t['mio'])
        ->where('user_id', $cliente->user_id)
        ->firstOrFail();

    \App\Models\Operacion\StoreCreditMovement::create([
        'store_credit_id' => $credito->id,
        'type'            => \App\Models\Operacion\StoreCreditMovement::CONSUMO,
        'amount'          => 40000,
    ]);

    $r = $this->deleteJson("/v1/negocio/clientes/{$cliente->user_id}")->assertSuccessful();

    expect($r->json('debia'))->toEqual(40000);

    // Sigue saliendo en Fiado, que es donde se cobra.
    $clientes = $this->getJson('/v1/negocio/creditos')->json('clientes');

    expect(collect($clientes)->firstWhere('user_id', $cliente->user_id))
        ->not->toBeNull();
});

test('un tendero no toca los clientes de otra tienda', function () {
    $mio   = tenderoConCatalogo();
    $ajeno = tenderoConCatalogo();
    $cliente = compradorConTelefono('3001112233');

    Sanctum::actingAs($mio['user']);
    $this->postJson("/v1/negocio/clientes/{$cliente->user_id}")->assertStatus(201);

    // El de la tienda de enfrente lo desafilia... de la SUYA, que no lo tiene.
    Sanctum::actingAs($ajeno['user']);
    $this->deleteJson("/v1/negocio/clientes/{$cliente->user_id}")->assertSuccessful();

    expect(
        BusinessUserAffiliation::where('busines_id', $mio['mio'])
            ->where('user_id', $cliente->user_id)
            ->exists(),
    )->toBeTrue();
});
