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

/* =========================================================================
   EL LISTADO DEL COMPRADOR CON SESION

   `businesses/index` es el que abre un comprador identificado, y era el mas
   pesado de los dos: viajaba SIEMPRE con el catalogo entero de cada negocio.
   Medido contra la base de demostracion, 105 KB con seis tiendas para pintar
   seis tarjetas. La bandera existia en el otro listado y aca se habia quitado
   al arreglar un 500; nadie la volvio a poner.
   ======================================================================== */

test('el listado del comprador con light=1 va sin catalogo ni reseñas', function () {
    $e = negocioConCatalogo();
    Sanctum::actingAs($e['user']);

    $this->getJson("/v1/businesses/index?user_id={$e['user']->user_id}&light=1")
        ->assertOk()
        ->assertJsonPath('businesses.0.products', [])
        ->assertJsonPath('businesses.0.reviews', [])
        // Lo que la tarjeta SI necesita sigue estando, y lo que lee el carrito.
        ->assertJsonPath('businesses.0.name', 'Tienda de Prueba')
        ->assertJsonStructure(['businesses' => [['delivery_fee', 'in_range', 'distance_km']]]);
});

test('sin la bandera sigue trayendo el catalogo, por las versiones ya instaladas', function () {
    /*
     * La bandera es OPCIONAL a proposito: los telefonos que ya tienen la app
     * instalada no la mandan, y si el servidor dejara de enviar `products` de
     * golpe se quedarian con la ficha de negocio vacia hasta que actualicen.
     */
    $e = negocioConCatalogo();
    Sanctum::actingAs($e['user']);

    $this->getJson("/v1/businesses/index?user_id={$e['user']->user_id}")
        ->assertOk()
        ->assertJsonPath('businesses.0.products.0.name', 'Arroz');
});

test('el listado ligero pesa una fraccion del completo', function () {
    /*
     * Lo que se fija es la RAZON, no un numero de bytes: con otra base los dos
     * cambian, pero el ligero tiene que seguir siendo mucho mas pequeño. Si
     * alguien vuelve a meter el catalogo en la tarjeta, esto lo dice.
     */
    $e = negocioConCatalogo();
    Sanctum::actingAs($e['user']);

    $completo = strlen($this->getJson(
        "/v1/businesses/index?user_id={$e['user']->user_id}",
    )->getContent());

    $ligero = strlen($this->getJson(
        "/v1/businesses/index?user_id={$e['user']->user_id}&light=1",
    )->getContent());

    expect($ligero)->toBeLessThan($completo);
});

/* =========================================================================
   LOS FAVORITOS

   Misma historia y mismo arreglo. La pestaña pinta TARJETAS de tienda y no
   enseña ni un producto, pero la respuesta traia el catalogo entero de cada
   favorito: 38 KB por UNO solo, de los cuales 36 eran 185 productos que nadie
   mira. Con cinco favoritos son casi 200 KB por cada visita.
   ======================================================================== */

test('los favoritos con light=1 van sin catalogo ni reseñas', function () {
    $e = negocioConCatalogo();

    \Illuminate\Support\Facades\DB::table('business_user_favorites')->insert([
        'user_id'    => $e['user']->user_id,
        'busines_id' => $e['businessId'],
    ]);

    Sanctum::actingAs($e['user']);

    $this->postJson('/v1/favorites/index', [
        'user_id' => $e['user']->user_id,
        'light'   => 1,
    ])
        ->assertOk()
        ->assertJsonPath('favorites.0.products', [])
        ->assertJsonPath('favorites.0.reviews', [])
        // Lo que la tarjeta si necesita.
        ->assertJsonPath('favorites.0.name', 'Tienda de Prueba')
        ->assertJsonStructure(['favorites' => [['delivery_fee', 'in_range', 'qualification']]]);
});

test('los favoritos sin la bandera siguen trayendo el catalogo', function () {
    // Por las versiones de la app ya instaladas, que no la mandan.
    $e = negocioConCatalogo();

    \Illuminate\Support\Facades\DB::table('business_user_favorites')->insert([
        'user_id'    => $e['user']->user_id,
        'busines_id' => $e['businessId'],
    ]);

    Sanctum::actingAs($e['user']);

    $this->postJson('/v1/favorites/index', ['user_id' => $e['user']->user_id])
        ->assertOk()
        ->assertJsonPath('favorites.0.products.0.name', 'Arroz');
});
