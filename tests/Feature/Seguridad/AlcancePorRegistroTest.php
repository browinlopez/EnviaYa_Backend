<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * QUE LO QUE PIDES SEA TUYO.
 *
 * Los permisos del proyecto son por MÓDULO y nunca por REGISTRO:
 * `EnsureModuleAccess` responde «¿puede ver la sección Pedidos?» y jamás
 * «¿puede ver ESTE pedido?». Para los paneles eso se resolvió con middleware
 * propio, pero las rutas antiguas —las que usa la app publicada— siguen
 * recibiendo el identificador en el cuerpo. Y un identificador en el cuerpo es
 * una sugerencia, no una credencial: son correlativos.
 *
 * Todo lo que se prueba acá se comprobó primero EXPLOTÁNDOLO. Con la cuenta de
 * un comprador se leyeron 21 pedidos de un negocio ajeno, cada uno con el
 * nombre, el correo, el teléfono y la dirección de quien lo hizo.
 *
 * Cada prueba usa a un COMPRADOR contra lo de un tercero. No hace falta ser
 * tendero para intentarlo, y ese es justamente el problema.
 */

function unComprador(): User
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $u = User::factory()->create(['rol' => 1]);

    DB::table('buyer')->insert([
        'user_id' => $u->user_id, 'qualification' => 0, 'state' => 1,
    ]);

    return $u;
}

function unNegocioAjeno(): int
{
    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');

    return DB::table('business')->insertGetId([
        'name' => 'La del vecino', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');
}

/* ===================================================================== */
/*  PEDIDOS Y DINERO DE OTRA TIENDA                                      */
/* ===================================================================== */

test('un comprador no puede leer los pedidos de una tienda ajena', function () {
    $yo = unComprador();
    $ajeno = unNegocioAjeno();

    Sanctum::actingAs($yo);

    /*
     * Esta es LA prueba. Antes devolvía la lista entera con
     * `buyer.user.name`, `.email`, `.phone` y `.address` de cada comprador.
     */
    $this->postJson('/v1/orders/business', ['business_id' => $ajeno])
        ->assertStatus(403);
});

test('un comprador no puede ver los ingresos de una tienda ajena', function () {
    $yo = unComprador();

    Sanctum::actingAs($yo);

    $this->postJson('/v1/orders/IncomeBusiness', ['business_id' => unNegocioAjeno()])
        ->assertStatus(403);
});

test('un comprador no puede listar el catalogo de una tienda ajena', function () {
    $yo = unComprador();

    Sanctum::actingAs($yo);

    // El catálogo con precios es información comercial: a cuánto vende cada
    // cosa el de al lado.
    $this->postJson('/v1/product/index', ['business_id' => unNegocioAjeno()])
        ->assertStatus(403);
});

test('un comprador no puede cambiarle el nombre ni el NIT a una tienda ajena', function () {
    $yo = unComprador();
    $ajeno = unNegocioAjeno();

    Sanctum::actingAs($yo);

    $this->putJson('/v1/businesses/update', [
        'busines_id' => $ajeno,
        'name'       => 'SECUESTRADA',
        'NIT'        => '000',
    ])->assertStatus(403);

    expect(DB::table('business')->where('busines_id', $ajeno)->value('name'))
        ->toBe('La del vecino');
});

/* ===================================================================== */
/*  AFILIACIONES: LOS CLIENTES DE OTRA TIENDA                            */
/* ===================================================================== */

test('un comprador no puede afiliarse solo a una tienda ajena', function () {
    $yo = unComprador();
    $ajeno = unNegocioAjeno();

    Sanctum::actingAs($yo);

    /*
     * La afiliación decide QUÉ PRECIOS VE: sin ella el catálogo sale con
     * candado. Auto-afiliarse era saltarse la decisión del tendero.
     */
    $this->postJson('/v1/businesses/affiliations/AfiliationUser', [
        'user_id' => $yo->user_id, 'busines_id' => $ajeno,
    ])->assertStatus(403);

    expect(DB::table('business_user_affiliations')
        ->where('busines_id', $ajeno)->count())->toBe(0);
});

test('un comprador no puede listar los clientes de una tienda ajena', function () {
    $yo = unComprador();

    Sanctum::actingAs($yo);

    $this->postJson('/v1/businesses/affiliations/getAffiliatedUsers', [
        'busines_id' => unNegocioAjeno(),
    ])->assertStatus(403);
});

/* ===================================================================== */
/*  DATOS PERSONALES                                                     */
/* ===================================================================== */

test('nadie puede leer las direcciones de otra persona', function () {
    $yo = unComprador();
    $otro = unComprador();

    DB::table('user_address')->insert([
        'user_id' => $otro->user_id,
        'address' => 'Calle 12 #65-15',
        'latitude' => 10.9, 'longitude' => -74.8,
        'state' => 1,
    ]);

    Sanctum::actingAs($yo);

    // La dirección de la casa, con coordenadas.
    $this->postJson('/v1/users/addresses', ['user_id' => $otro->user_id])
        ->assertStatus(403);
});

test('nadie puede borrar la direccion de otra persona', function () {
    $yo = unComprador();
    $otro = unComprador();

    $id = DB::table('user_address')->insertGetId([
        'user_id' => $otro->user_id, 'address' => 'Calle 12 #65-15', 'state' => 1,
    ], 'address_id');

    Sanctum::actingAs($yo);

    $this->deleteJson("/v1/users/addresses/{$id}", ['user_id' => $otro->user_id])
        ->assertStatus(403);

    expect((bool) DB::table('user_address')->where('address_id', $id)->value('state'))
        ->toBeTrue();
});

test('nadie puede desactivar la cuenta de otra persona', function () {
    $yo = unComprador();
    $otro = unComprador();

    Sanctum::actingAs($yo);

    /*
     * Bastaba con saber un correo —y el de cualquier tienda está publicado—
     * para dejar a esa persona fuera. Uno por uno se apagaba la plataforma
     * entera, administradores incluidos.
     */
    $this->deleteJson('/v1/users/delete', ['email' => $otro->email])
        ->assertStatus(403);

    expect((bool) User::find($otro->user_id)->state)->toBeTrue();
});

test('cada quien si puede darse de baja', function () {
    $yo = unComprador();

    Sanctum::actingAs($yo);

    // Cerrar la propia cuenta es un derecho, no un ataque.
    $this->deleteJson('/v1/users/delete', ['email' => $yo->email])->assertOk();

    expect((bool) User::find($yo->user_id)->state)->toBeFalse();
});

/* ===================================================================== */
/*  EL CATALOGO DE LA PLATAFORMA                                         */
/* ===================================================================== */

test('un comprador no puede crear ni borrar categorias de la plataforma', function () {
    $yo = unComprador();

    Sanctum::actingAs($yo);

    /*
     * Las categorías organizan el catálogo de TODOS los negocios. Borrar una
     * deja sin sección a los productos que colgaban de ella.
     */
    $this->postJson('/v1/categories/create', ['name' => 'Inventada'])
        ->assertStatus(403);

    expect(DB::table('category')->where('name', 'Inventada')->exists())->toBeFalse();
});

test('un comprador no puede dar de alta un negocio', function () {
    $yo = unComprador();

    Sanctum::actingAs($yo);

    // Afiliar un aliado implica contrato y verificación: lo hace el equipo.
    $this->postJson('/v1/businesses/store', ['name' => 'Mi tienda pirata'])
        ->assertStatus(403);
});

test('un comprador no puede dar de alta domiciliarios', function () {
    $yo = unComprador();

    Sanctum::actingAs($yo);

    $this->postJson('/v1/domiciliaries/createDomiciliary', [
        'name' => 'Falso', 'email' => 'falso@ejemplo.com', 'password' => 'secreto123',
    ])->assertStatus(403);

    expect(User::where('email', 'falso@ejemplo.com')->exists())->toBeFalse();
});
