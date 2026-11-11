<?php

declare(strict_types=1);

use App\Http\Resources\Producto\ProductoDelNegocioResource;
use App\Http\Resources\Producto\ProductoDestacadoResource;
use App\Http\Resources\Producto\ProductoFavoritoResource;
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

/** El producto, con el pivote cargado como lo cargan los controladores. */
function productoConPivote(int $negocioId): Product
{
    $p = Product::whereHas('businesses', fn ($q) => $q->where('business.busines_id', $negocioId))
        ->with(['businesses' => fn ($q) => $q->where('business.busines_id', $negocioId)])
        ->first();

    expect($p)->not->toBeNull();

    // El pivote que las tres formas leen sale de la relacion muchos-a-muchos.
    $p->setRelation('pivot', $p->businesses->first()->pivot);

    return $p;
}

it('la vitrina no manda los campos del peso, y los destacados los mandan vacios', function () {
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
     * Y AQUI SALE EL FALLO DE VERDAD, que esta asercion destapo.
     *
     * El producto que siembra esta prueba tiene `sale_unit = 'kg'` y
     * `min_qty = 0.25` EN LA BASE. Lo que llega al Resource es `'unidad'` y
     * `null`.
     *
     * El motivo: `Business::products()` declara
     * `withPivot(['price', 'amount', 'qualification'])` y NO incluye
     * `sale_unit`, `min_qty` ni `step_qty`. El controlador las lee
     * —`$product->pivot->sale_unit ?? 'unidad'`— pero la relacion nunca las
     * trae, asi que el `??` se dispara siempre.
     *
     * Comprobado tambien contra la base de desarrollo, fuera de las pruebas:
     * se puso una fila a `kg`, se cargo por la relacion y salio `NULL`.
     *
     * LO QUE SIGNIFICA: los tres campos con los que la app decide si ofrece
     * los botones de plata y de porcion para lo que se vende al peso NO
     * LLEGAN NUNCA, por ningun endpoint. Un tendero puede configurar su queso
     * en kilos y el comprador seguira viendo el +/- de unidades.
     *
     * NO SE ARREGLA AQUI, y el arreglo es de una linea: añadir las tres
     * columnas al `withPivot`. No se hace porque enciende un camino que las
     * apps publicadas en las dos tiendas reciben hoy apagado, y si la pantalla
     * del comprador no sabe pintar una unidad distinta de `unidad`, encenderlo
     * rompe la compra en vez de arreglarla. Eso lo decide quien conozca el
     * estado de la app, no yo.
     *
     * Esta prueba fija el fallo TAL COMO ESTA. El dia que se arregle se pone
     * roja, y ese es el punto: cambiarla es el momento de comprobar la app.
     */
    expect($destacado['sale_unit'])->toBe('unidad');
    expect($destacado['min_qty'])->toBeNull();

    // Y que la base SI tiene el valor, para que no se lea esto como «no habia
    // dato»: el dato esta, no llega.
    expect(DB::table('products_business')
        ->where('busines_id', $e['negocio'])
        ->where('products_id', $e['producto'])
        ->value('sale_unit'))->toBe('kg');
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
