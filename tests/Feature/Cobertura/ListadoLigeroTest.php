<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * El listado de negocios y la ficha: qué pesa y quién ve los precios.
 *
 * El listado del inicio llevaba dentro el CATÁLOGO ENTERO de cada negocio —96 KB
 * con la base de demostración— para pintar tarjetas que solo enseñan nombre,
 * logo y nota. En el emulador Android la descarga se cortaba a medias y la
 * pantalla se quedaba sin negocios; en un teléfono con datos es medio megabyte
 * por apertura.
 *
 * Y la ficha (`businesses/show`) devolvía los precios sin mirar la afiliación,
 * mientras el listado sí la respetaba: bastaba llamarla con el identificador del
 * negocio para leer todos sus precios sin estar afiliado.
 */
function negocioConCatalogo(): array
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    $businessId = DB::table('business')->insertGetId([
        'name' => 'Tienda de Prueba', 'qualification' => 5, 'state' => 1,
    ]);

    $productId = DB::table('products')->insertGetId(
        ['name' => 'Arroz', 'state' => 1],
        'products_id',
    );

    DB::table('products_business')->insert([
        'busines_id' => $businessId, 'products_id' => $productId,
        'price' => 9500, 'amount' => 10,
    ]);

    $user = User::factory()->create(['rol' => 1]);

    return compact('businessId', 'productId', 'user');
}

/* ---------------------------------------------------------------------- */

test('el listado normal sigue trayendo el catálogo', function () {
    // La bandera es opcional a propósito: las versiones de la app ya instaladas
    // leen `products` de acá para pintar la ficha, y quitárselo las dejaría con
    // el catálogo vacío.
    $e = negocioConCatalogo();
    Sanctum::actingAs($e['user']);

    $this->getJson("/v1/businesses/top-businesses?user_id={$e['user']->user_id}")
        ->assertOk()
        ->assertJsonPath('businesses.0.products.0.name', 'Arroz');
});

test('con light=1 el listado va sin catálogo ni reseñas', function () {
    $e = negocioConCatalogo();
    Sanctum::actingAs($e['user']);

    $this->getJson("/v1/businesses/top-businesses?user_id={$e['user']->user_id}&light=1")
        ->assertOk()
        ->assertJsonPath('businesses.0.products', [])
        ->assertJsonPath('businesses.0.reviews', [])
        // Lo que la tarjeta del inicio sí necesita sigue estando.
        ->assertJsonPath('businesses.0.name', 'Tienda de Prueba');
});

test('sin afiliación la ficha no revela los precios', function () {
    // La razón de ser del cambio: el listado ya escondía el precio y esta ruta
    // no, así que la regla se saltaba llamando a la otra puerta.
    $e = negocioConCatalogo();
    Sanctum::actingAs($e['user']);

    $this->postJson('/v1/businesses/show', [
        'busines_id' => $e['businessId'],
        'user_id'    => $e['user']->user_id,
    ])
        ->assertOk()
        ->assertJsonPath('is_affiliated', false)
        ->assertJsonPath('products.0.price', 0);
});

test('con afiliación la ficha sí muestra el precio', function () {
    $e = negocioConCatalogo();
    Sanctum::actingAs($e['user']);

    DB::table('business_user_affiliations')->insert([
        'user_id'    => $e['user']->user_id,
        'busines_id' => $e['businessId'],
    ]);

    $this->postJson('/v1/businesses/show', [
        'busines_id' => $e['businessId'],
        'user_id'    => $e['user']->user_id,
    ])
        ->assertOk()
        ->assertJsonPath('is_affiliated', true)
        ->assertJsonPath('products.0.price', 9500);
});

test('la ficha trae el nombre de la categoría, que es como agrupa la pantalla', function () {
    $e = negocioConCatalogo();
    Sanctum::actingAs($e['user']);

    $this->postJson('/v1/businesses/show', ['busines_id' => $e['businessId']])
        ->assertOk()
        ->assertJsonStructure(['products' => [['product_id', 'name', 'category', 'price']]]);
});

test('el listado completo responde a un comprador con sesión', function () {
    /*
     * Este es el que abre el inicio de la app cuando hay sesión.
     *
     * Su closure capturaba `$ligero`, que solo existe en el otro listado: PHP
     * no se queja de eso hasta que ejecuta la closure, así que respondía 500 en
     * TODAS las llamadas y ningún comprador identificado veía un solo negocio.
     * El listado sin sesión, que no pasa por ahí, seguía funcionando —por eso
     * no saltaba a la vista—.
     */
    $e = negocioConCatalogo();
    Sanctum::actingAs($e['user']);

    $r = test()->getJson('/v1/businesses/index?user_id=' . $e['user']->user_id)
        ->assertOk();

    expect($r->json('user_authenticated'))->toBeTrue()
        ->and($r->json('businesses'))->not->toBeEmpty();
});

test('el listado completo también responde sin user_id', function () {
    $e = negocioConCatalogo();
    Sanctum::actingAs($e['user']);

    test()->getJson('/v1/businesses/index')->assertOk();
});
