<?php

declare(strict_types=1);

use App\Http\Resources\Producto\ProductoDelNegocioResource;
use App\Http\Resources\Producto\ProductoDestacadoResource;
use App\Http\Resources\Producto\ProductoFavoritoResource;
use App\Http\Resources\Producto\ProductoMasVendidoResource;
use App\Models\Business;
use App\Models\Product\Product;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * «EL PRECIO SOLO VIAJA SI ESTÁS AFILIADO» — la regla, y las puertas que no la
 * cumplen.
 *
 * El mismo producto se sirve por SEIS formas distintas, cada una con su
 * Resource, y la regla del precio está escrita así en una de ellas: «el precio
 * solo viaja si quien pregunta está afiliado a esa tienda; sin sesión o sin
 * afiliación va en 0, y la app lo pinta como bloqueado. Es lo que impide leer
 * los precios de un negocio con el que no se tiene relación».
 *
 * Las seis, con lo que cada una comprueba:
 *
 *   | forma                             | endpoint                        | comprueba          |
 *   |-----------------------------------|---------------------------------|--------------------|
 *   | ProductoDelNegocioResource        | GET /businesses/index           | afiliación + sesión|
 *   | ProductoDeLaFichaResource         | POST /businesses/show           | afiliación         |
 *   | ProductoDestacadoResource         | GET /businesses/top-businesses  | afiliación + sesión|
 *   | ProductoFavoritoResource          | POST /favorites/index           | **nada**           |
 *   | ProductoMasVendidoResource        | POST /product/topProductsBusiness| **nada**          |
 *   | ProductoDelCatalogoPropioResource | POST /product/index             | nada, y está bien  |
 *
 * LA ÚLTIMA NO ES UNA FUGA y conviene no confundirla: ese endpoint comprueba
 * la PERTENENCIA —con la sesión de otro contesta «Ese negocio no es tuyo» con
 * un 403, comprobado— y es el surtido del propio tendero, que tiene que ver su
 * precio y su existencia. La regla protege el precio de una tienda frente a
 * OTROS, no frente a su dueño.
 *
 * LAS DOS DEL MEDIO SÍ. `/favorites/index` y `/product/topProductsBusiness`
 * piden sesión y nada más: ni pertenencia ni afiliación. Marcar una tienda
 * como favorita no exige afiliación, y los más vendidos de cualquier negocio
 * los puede pedir cualquiera con una cuenta — con sus precios y con cuánto se
 * ha pedido de cada uno, que es el surtido estrella de una tienda y lo que
 * cobra por él.
 *
 * NO SE TOCA NINGUNA. Añadir la comprobación cambia lo que reciben dos apps
 * publicadas en las tiendas, y decidir si el ranking de una tienda es público
 * es de producto. Esta prueba fija el estado de las seis para que el día que
 * se decida, se decida mirando esta tabla.
 */
function productoDeUnaTiendaAjena(): array
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);

    // Quien pregunta: con sesión y SIN afiliación a la tienda.
    $user = User::factory()->create(['rol' => 1]);
    Sanctum::actingAs($user);

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'La de la otra cuadra', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    $producto = DB::table('products')->insertGetId([
        'name' => 'Arroz', 'state' => 1,
    ], 'products_id');

    DB::table('products_business')->insert([
        'busines_id' => $negocio, 'products_id' => $producto,
        'price' => 4800, 'amount' => 20, 'sale_unit' => 'unidad',
    ]);

    $p = Business::with('products')->find($negocio)->products->first();

    return ['user' => $user, 'negocio' => (int) $negocio, 'producto' => $p];
}

it('las tres formas del catalogo tapan el precio sin afiliacion', function () {
    $e = productoDeUnaTiendaAjena();
    $req = Request::create('/v1/businesses/index', 'GET');
    $p = $e['producto'];
    $uid = $e['user']->user_id;

    // `afiliado = false`, que es la situacion de quien no tiene relacion.
    expect((new ProductoDelNegocioResource($p, false, $uid))->toArray($req)['price'])->toBe(0);
    expect((new ProductoDestacadoResource($p, false, $uid))->toArray($req)['price'])->toBe(0);

    // Y con afiliacion, el precio SI viaja: sin esto la prueba pasaria con un
    // Resource que devolviera 0 siempre, que no es la regla.
    expect((float) (new ProductoDelNegocioResource($p, true, $uid))->toArray($req)['price'])
        ->toBe(4800.0);
});

it('favoritos y los mas vendidos mandan el precio igual, sin comprobar nada', function () {
    $e = productoDeUnaTiendaAjena();
    $req = Request::create('/v1/favorites/index', 'POST');
    $p = $e['producto'];

    /*
     * A estos dos Resources no se les puede pasar «sin afiliacion» porque no
     * reciben el dato: no lo miran. Eso ES el hallazgo, y por eso la prueba
     * compara el precio que devuelven contra el que tapa el catalogo.
     */
    $favorito = (new ProductoFavoritoResource($p))->toArray($req);
    expect((float) $favorito['price'])->toBe(4800.0);

    $masVendido = (new ProductoMasVendidoResource(
        (object) ['product' => $p, 'product_id' => $p->products_id, 'total_ordered' => 7],
        4800.0,
    ))->toArray($req);
    expect((float) $masVendido['price'])->toBe(4800.0);

    // La desigualdad, que es la afirmación: el catálogo tapa y estos dos no.
    $tapado = (new ProductoDelNegocioResource($p, false, $e['user']->user_id))
        ->toArray($req)['price'];

    expect((float) $favorito['price'])->not->toBe((float) $tapado);
    expect((float) $masVendido['price'])->not->toBe((float) $tapado);
});

it('los mas vendidos no piden ni pertenencia ni afiliacion, por HTTP', function () {
    /*
     * La de arriba prueba los Resources; esta prueba el ENDPOINT, que es
     * donde vive la ausencia de la guarda. Con la sesión de alguien que no es
     * dueño ni está afiliado, `/product/topProductsBusiness` contesta 200.
     *
     * Y la contrapartida en la misma prueba: `/product/index`, que sí
     * comprueba pertenencia, contesta 403 con la misma sesión. Sin esa
     * segunda mitad, un 200 aquí podría explicarse por que el escenario no
     * tiene ninguna guarda configurada.
     */
    $e = productoDeUnaTiendaAjena();

    test()->postJson('/v1/product/topProductsBusiness', ['business_id' => $e['negocio']])
        ->assertOk();

    test()->postJson('/v1/product/index', ['business_id' => $e['negocio']])
        ->assertForbidden()
        ->assertJsonPath('message', 'Ese negocio no es tuyo.');
});
