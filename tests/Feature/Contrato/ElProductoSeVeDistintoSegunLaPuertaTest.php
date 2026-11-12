<?php

declare(strict_types=1);

use App\Http\Resources\Producto\ProductoDelNegocioResource;
use App\Http\Resources\Producto\ProductoDestacadoResource;
use App\Http\Resources\Producto\ProductoFavoritoResource;
use App\Models\Business;
use App\Models\Product\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * EL MISMO PRODUCTO SE VE DE TRES MANERAS SEGÚN POR DÓNDE ENTRE EL COMPRADOR.
 *
 * Esto NO es una prueba de que algo funcione. Es una prueba de clavo sobre una
 * incoherencia que existe hoy, medida contra las respuestas de verdad, para
 * que el día que alguien la arregle lo haga a sabiendas y no por accidente.
 *
 * Las tres formas, y de dónde sale cada una:
 *
 *   `GET /businesses/index`        7 campos   `ProductoDelNegocioResource`
 *   `POST /businesses/show`        7 campos   el mismo
 *   `GET /businesses/top-businesses`  12      `ProductoDestacadoResource`
 *   `GET /top-businesses-free`        12      el mismo
 *   `POST /favorites/index`         7 campos  `ProductoFavoritoResource`
 *
 * DOS DIFERENCIAS, Y LAS DOS IMPORTAN:
 *
 * 1. LOS CAMPOS. Solo los destacados traen `sale_unit`, `min_qty` y
 *    `step_qty`, que son con los que la app decide si ofrece el +/- de
 *    siempre o los botones de plata y porción —«3.000 de queso», «500 g»—
 *    para lo que se vende al peso. Y solo ellos traen `variante` y `group`,
 *    que hacen que ocho presentaciones del mismo producto se pinten como UNA
 *    tarjeta con chips en vez de ocho tarjetas.
 *
 *    O sea que por la vitrina principal —la lectura de más tráfico de la
 *    plataforma— un producto al peso llega sin su unidad.
 *
 * 2. EL PRECIO. El del catálogo solo viaja si quien pregunta está AFILIADO a
 *    esa tienda, y cae a `0` si no. El de favoritos viaja siempre y cae a
 *    `null`. Marcar una tienda como favorita no exige afiliación, así que ese
 *    endpoint deja leer precios que los otros dos no.
 *
 *    No es la primera vez en esa misma respuesta: el comentario que quedó en
 *    `FavoriteController` dice, de los datos del propietario, «la misma fuga
 *    se cerró en los dos listados del catálogo y ESTE se quedó sin tocar».
 *    Se cerró la de los propietarios y la del precio se quedó.
 *
 * NADA DE ESTO SE UNIFICÓ, a propósito: añadir seis campos a la vitrina o
 * quitárselos a los destacados cambia lo que reciben dos apps publicadas en
 * las tiendas, y poner el precio a 0 en favoritos puede ser un arreglo o una
 * regresión según a quién se le pregunte. Es una decisión de producto.
 *
 * Lo que esta prueba garantiza es que la decisión no se tome sola.
 */
function unProductoDeTienda(): array
{
    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'La de la esquina', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    $producto = DB::table('products')->insertGetId([
        'name' => 'Queso costeño', 'state' => 1,
    ], 'products_id');

    DB::table('products_business')->insert([
        'busines_id' => $negocio, 'products_id' => $producto,
        'price' => 28000, 'amount' => 1,
        // Al peso: es el caso donde la diferencia se nota en pantalla.
        'sale_unit' => 'kg', 'min_qty' => 0.25, 'step_qty' => 0.25,
    ]);

    return ['negocio' => (int) $negocio, 'producto' => (int) $producto];
}

/**
 * El producto, cargado COMO LO CARGAN LOS CONTROLADORES.
 *
 * Por `Business::with('products')` y no por `Product::with('businesses')`, y
 * la diferencia importa: las columnas del pivote se declaran por relacion, y
 * las del peso estan en `Business::products()`. Cargar por el otro lado daba
 * un pivote sin ellas y la prueba medía el camino equivocado — fue asi al
 * principio, y por eso esta dicho aqui.
 */
function productoConPivote(int $negocioId): Product
{
    $negocio = Business::with('products')->find($negocioId);

    expect($negocio)->not->toBeNull();

    $p = $negocio->products->first();

    expect($p)->not->toBeNull();

    return $p;
}

it('la vitrina no manda los campos del peso, y los destacados si los mandan', function () {
    $e = unProductoDeTienda();
    $p = productoConPivote($e['negocio']);
    $req = Request::create('/v1/businesses/index', 'GET');

    $vitrina = (new ProductoDelNegocioResource($p, true, 1))->toArray($req);
    $destacado = (new ProductoDestacadoResource($p, true, 1))->toArray($req);

    $soloEnDestacados = array_values(array_diff(
        array_keys($destacado), array_keys($vitrina),
    ));
    sort($soloEnDestacados);

    /*
     * LA LISTA EXACTA, y no «hay diferencias»: si alguien añade `sale_unit` a
     * la vitrina —que es probablemente lo correcto— esta prueba se pone roja y
     * le obliga a actualizarla, que es el momento en que alguien se pregunta a
     * qué pantalla de qué app le afecta.
     */
    expect($soloEnDestacados)->toBe([
        'category', 'group', 'min_qty', 'sale_unit', 'step_qty', 'variante',
    ]);

    $soloEnVitrina = array_values(array_diff(
        array_keys($vitrina), array_keys($destacado),
    ));

    expect($soloEnVitrina)->toBe(['category_id']);

    /*
     * Y QUE LLEGAN DE VERDAD, que es donde estaba el fallo.
     *
     * Aqui esta prueba afirmaba `'unidad'` y `null`, y no porque la tienda no
     * tuviera kilos configurados: `Business::products()` declaraba
     * `withPivot(['price','amount','qualification'])` y NO incluia
     * `sale_unit`, `min_qty` ni `step_qty`. La relacion no traia esas
     * columnas, el pivote llegaba sin ellas, y el `?? 'unidad'` del Resource
     * se disparaba siempre.
     *
     * No daba ningun error: un pivote al que le falta una columna devuelve
     * null. Asi que el camino entero —migracion, columna, lectura,
     * serializacion— estaba bien escrito y no funcionaba, y nada lo decia.
     *
     * Arreglado el 11 de noviembre de 2026 anadiendo las tres al `withPivot`.
     * Estas dos lineas son la prueba de que llega: sin ellas, la comprobacion
     * de las claves de arriba pasaria igual con todos los valores vacios.
     */
    expect($destacado['sale_unit'])->toBe('kg');
    expect((float) $destacado['min_qty'])->toBe(0.25);
    expect((float) $destacado['step_qty'])->toBe(0.25);
});

it('favoritos manda el precio SIN comprobar la afiliacion, y el catalogo no', function () {
    $e = unProductoDeTienda();
    $p = productoConPivote($e['negocio']);
    $req = Request::create('/v1/favorites/index', 'POST');

    // Sin afiliación: el catálogo tapa el precio.
    $catalogo = (new ProductoDelNegocioResource($p, false, 1))->toArray($req);
    expect($catalogo['price'])->toBe(0);

    // Sin sesión tampoco: el `0` del `userId` falsy.
    $sinSesion = (new ProductoDelNegocioResource($p, true, null))->toArray($req);
    expect($sinSesion['price'])->toBe(0);

    // Favoritos lo manda igual, porque no mira ni una cosa ni la otra.
    $favorito = (new ProductoFavoritoResource($p))->toArray($req);
    expect((float) $favorito['price'])->toBe(28000.0);

    /*
     * ESTA ES LA AFIRMACIÓN QUE IMPORTA, escrita como desigualdad: las dos
     * formas discrepan sobre el mismo producto. El día que se decida unificar,
     * esto se pone rojo.
     */
    expect($favorito['price'])->not->toBe($catalogo['price']);
});

it('las tres formas comparten los seis campos que la app siempre lee', function () {
    /*
     * La contrapartida: una prueba que solo mire diferencias se queda tan
     * contenta si mañana alguien rompe lo que SÍ es común. Estos seis los lee
     * la app en las tres pantallas.
     */
    $e = unProductoDeTienda();
    $p = productoConPivote($e['negocio']);
    $req = Request::create('/v1/businesses/index', 'GET');

    $formas = [
        'vitrina' => (new ProductoDelNegocioResource($p, true, 1))->toArray($req),
        'destacado' => (new ProductoDestacadoResource($p, true, 1))->toArray($req),
        'favorito' => (new ProductoFavoritoResource($p))->toArray($req),
    ];

    foreach ($formas as $nombre => $forma) {
        foreach (['product_id', 'name', 'description', 'image', 'state', 'price'] as $campo) {
            expect(array_key_exists($campo, $forma))->toBeTrue(
                "A la forma «{$nombre}» le falta «{$campo}»",
            );
        }
    }
});
