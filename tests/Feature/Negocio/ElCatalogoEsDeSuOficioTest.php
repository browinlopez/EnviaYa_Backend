<?php

/**
 * ==========================================================================
 * A UNA DROGUERÍA NO SE LE OFRECE DETERGENTE.
 *
 * El catálogo es COMPARTIDO por toda la plataforma, y el buscador era el
 * único camino que no miraba el tipo de negocio. Al abrir «Agregar un
 * producto» en una droguería, lo primero que salía era detergente, aceite de
 * cocina y papel higiénico: los 5.629 productos de los cuatro oficios
 * ordenados por nombre. Los de farmacia son 784, así que para encontrar el
 * acetaminofén había que escribirlo entero.
 *
 * La pertenencia sale del PIVOTE `category_category_business`, que es la
 * fuente de verdad —la misma que usan el endpoint del esquema y la
 * validación al guardar—.
 *
 * Lo que estas pruebas defienden, por orden de importancia:
 *
 *  1. que cada oficio vea lo suyo;
 *  2. que NADIE se quede sin catálogo por un dato que le falte a su ficha;
 *  3. que el escáner siga encontrando lo que se tiene en la mano.
 * ========================================================================== */

use App\Services\CatalogoDeProductos;
use App\Support\ProductTypeSchema;
use Illuminate\Support\Facades\DB;

/**
 * El tipo de negocio, si no existe ya.
 *
 * `category.business_category_id` y el pivote apuntan los dos a
 * `category_business`, asi que la fila del tipo tiene que estar ANTES. En la
 * base de verdad la siembra `CategoryBusinessSeeder`; en una base de pruebas
 * recien migrada no hay nada.
 */
function tipoDeNegocio(int $id, string $nombre): void
{
    DB::table('category_business')->updateOrInsert(['id' => $id], ['name' => $nombre]);
}

beforeEach(function () {
    tipoDeNegocio(ProductTypeSchema::TYPE_GROCERY, 'Tienda');
    tipoDeNegocio(ProductTypeSchema::TYPE_PHARMACY, 'Farmacia');
});

/** Una categoría de un tipo, escrita en el pivote como manda. */
function categoriaDe(?int $tipo, string $nombre): int
{
    $id = DB::table('category')->insertGetId([
        'name' => $nombre,
        'business_category_id' => $tipo,
        'state' => 1,
    ], 'category_id');

    if ($tipo) {
        DB::table('category_category_business')->insert([
            'category_id' => $id,
            'business_category_id' => $tipo,
        ]);
    }

    return $id;
}

/** Un negocio del tipo que se pida. */
function negocioDeTipo(?int $tipo): int
{
    return DB::table('business')->insertGetId([
        'name' => 'Local '.uniqid(),
        'qualification' => 0,
        'state' => 1,
        'type' => $tipo,
    ], 'busines_id');
}

/**
 * Un producto del catálogo compartido, en la categoría que se diga.
 *
 * Con nombre largo porque `productoEn` ya existe en `AlcancePorNegocioTest`.
 * Pest carga en el mismo proceso todos los archivos que corre, así que dos
 * helpers con el mismo nombre tumban la suite entera con «Cannot redeclare»:
 * el archivo nuevo pasa solo y rompe a los demás al correrlos juntos.
 */
function unProductoDelCatalogo(?int $categoria, string $nombre): int
{
    return DB::table('products')->insertGetId([
        'name' => $nombre,
        'category_id' => $categoria,
        'state' => 1,
    ], 'products_id');
}

/** Los nombres que el buscador le ofrece a ese negocio. */
function leOfrecen(int $negocio, string $texto = ''): array
{
    return array_map(
        fn ($p) => $p['name'],
        app(CatalogoDeProductos::class)->buscar($texto, $negocio, 50),
    );
}

/* ---------------------------------------------------------------------- */

it('a una droguería no se le ofrece detergente, y a una tienda no acetaminofén', function () {
    /*
     * ÉSTA ES LA PRUEBA QUE IMPORTA. Las dos direcciones a la vez: lo que se
     * arregló no puede ser «la droguería ya no ve lo de tienda» a costa de que
     * la tienda vea de todo.
     */
    $abarrotes = categoriaDe(ProductTypeSchema::TYPE_GROCERY, 'Aseo y Limpieza');
    $medicina = categoriaDe(ProductTypeSchema::TYPE_PHARMACY, 'Dolor y fiebre');

    unProductoDelCatalogo($abarrotes, 'DETERGENTE LIQUIDO 1 LT');
    unProductoDelCatalogo($medicina, 'ACETAMINOFEN 500 MG');

    $tienda = negocioDeTipo(ProductTypeSchema::TYPE_GROCERY);
    $drogueria = negocioDeTipo(ProductTypeSchema::TYPE_PHARMACY);

    expect(leOfrecen($drogueria))->toBe(['ACETAMINOFEN 500 MG']);
    expect(leOfrecen($tienda))->toBe(['DETERGENTE LIQUIDO 1 LT']);
});

it('el filtro también manda cuando se escribe algo', function () {
    /*
     * Filtrar solo la lista de arranque y no la búsqueda dejaría el fallo a
     * medio arreglar: escribir «aceite» en una droguería seguiría sacando el
     * aceite de cocina.
     */
    $abarrotes = categoriaDe(ProductTypeSchema::TYPE_GROCERY, 'Abarrotes');
    $cuidado = categoriaDe(ProductTypeSchema::TYPE_PHARMACY, 'Dermocosmética');

    unProductoDelCatalogo($abarrotes, 'ACEITE DE COCINA 900ML');
    unProductoDelCatalogo($cuidado, 'ACEITE CORPORAL BEBE');

    $drogueria = negocioDeTipo(ProductTypeSchema::TYPE_PHARMACY);

    expect(leOfrecen($drogueria, 'aceite'))->toBe(['ACEITE CORPORAL BEBE']);
});

it('un negocio SIN tipo sigue viéndolo todo', function () {
    /*
     * Es lo que hacía antes de este cambio. Dejar a alguien con el catálogo
     * vacío por un dato que le falta a su ficha es peor que enseñarle de más:
     * lo primero parece que la plataforma está rota, lo segundo solo es
     * incómodo.
     */
    $abarrotes = categoriaDe(ProductTypeSchema::TYPE_GROCERY, 'Abarrotes');
    $medicina = categoriaDe(ProductTypeSchema::TYPE_PHARMACY, 'Medicina');

    unProductoDelCatalogo($abarrotes, 'ARROZ');
    unProductoDelCatalogo($medicina, 'IBUPROFENO');

    expect(leOfrecen(negocioDeTipo(null)))->toHaveCount(2);
});

it('un producto SIN categoría se le ofrece a todos', function () {
    /*
     * Hoy no hay ninguno —medido contra la base—, pero el día que entre uno
     * por una carga a medias, lo que no puede pasar es que se vuelva invisible
     * para los cuatro tipos a la vez y nadie entienda por qué no aparece.
     */
    unProductoDelCatalogo(null, 'PRODUCTO SIN CLASIFICAR');

    foreach ([ProductTypeSchema::TYPE_GROCERY, ProductTypeSchema::TYPE_PHARMACY] as $tipo) {
        expect(leOfrecen(negocioDeTipo($tipo)))->toBe(['PRODUCTO SIN CLASIFICAR']);
    }
});

it('una categoría que no está en el pivote tampoco deja el producto huérfano', function () {
    /*
     * Misma regla que `PlantillaDeCatalogo::categorias()`, y por el mismo
     * motivo: hay categorías antiguas que nunca se clasificaron, y un producto
     * colgado de una de ellas no puede desaparecer del catálogo de todos.
     */
    $huerfana = DB::table('category')->insertGetId(
        ['name' => 'Sin clasificar', 'state' => 1],
        'category_id',
    );

    unProductoDelCatalogo($huerfana, 'COSA RARA');

    expect(leOfrecen(negocioDeTipo(ProductTypeSchema::TYPE_PHARMACY)))->toBe(['COSA RARA']);
});

it('lo apagado sigue sin ofrecerse, filtro o no filtro', function () {
    /* El filtro nuevo no puede haberse comido la regla que ya existía. */
    $medicina = categoriaDe(ProductTypeSchema::TYPE_PHARMACY, 'Medicina');

    DB::table('products')->insert([
        'name' => 'RETIRADO DEL MERCADO',
        'category_id' => $medicina,
        'state' => 0,
    ]);

    expect(leOfrecen(negocioDeTipo(ProductTypeSchema::TYPE_PHARMACY)))->toBeEmpty();
});

it('el ESCÁNER no filtra: lo que se tiene en la mano existe', function () {
    /*
     * A propósito, y es la decisión menos obvia de este cambio.
     *
     * Escanear es un acto sobre algo que ya se tiene en la mano. Si el código
     * está en el catálogo, negarlo porque su categoría es de otro oficio sería
     * decirle al tendero que el producto que está sosteniendo no existe.
     *
     * Una droguería que vende pañitos húmedos clasificados en «Aseo y
     * Limpieza» tiene que poder darlos de alta escaneándolos.
     */
    $abarrotes = categoriaDe(ProductTypeSchema::TYPE_GROCERY, 'Aseo y Limpieza');

    DB::table('products')->insert([
        'name' => 'PAÑITOS HUMEDOS',
        'category_id' => $abarrotes,
        'barcode' => '7702999000011',
        'state' => 1,
    ]);

    $drogueria = negocioDeTipo(ProductTypeSchema::TYPE_PHARMACY);

    /* No se lo ofrece la lista... */
    expect(leOfrecen($drogueria))->toBeEmpty();

    /* ...pero escaneado, aparece. */
    $ficha = app(CatalogoDeProductos::class)->porCodigo('7702999000011', $drogueria);

    expect($ficha)->not->toBeNull();
    expect($ficha['name'])->toBe('PAÑITOS HUMEDOS');
});
