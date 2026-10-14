<?php

use App\Models\Product\Product;
use App\Models\Product\ProductAlias;
use Illuminate\Support\Facades\DB;

/**
 * CÓMO LLAMA CADA TIENDA A UN PRODUCTO DEL CATÁLOGO.
 *
 * Esta prueba existe por doce duplicados que aparecieron en producción.
 *
 * Al subir el inventario de una tienda —1.010 filas— el importador creó
 * catorce productos. Dos eran de verdad nuevos; los otros doce duplicaban
 * productos que ya estaban, porque el nombre que la tienda le da a un producto
 * NO es el que tiene el catálogo: el archivo dice «ACETAMINOFEN» y el catálogo
 * tiene «ACETAMINOFEN TABLETAS 500MG XUNIDAD».
 *
 * Que esos dos sean el mismo producto es una decisión humana. El importador no
 * puede deducirla, y si lo intentara por parecido juntaría «ÁGUILA NEGRA» con
 * «ÁGUILA», que son dos cervezas distintas. Lo que faltaba no era un algoritmo
 * mejor sino un sitio donde guardar la decisión, para que la siguiente carga
 * no la repita mal.
 */

test('normaliza el nombre antes de guardarlo o buscarlo', function (string $entra, string $sale) {
    expect(ProductAlias::normalizar($entra))->toBe($sale);
})->with([
    // Tres de los doce duplicados eran esto y nada más: un espacio de sobra.
    ['AJINOMEN CARNE  80 GR', 'AJINOMEN CARNE 80 GR'],
    ['  BOTELLON BRISA  18,9L ', 'BOTELLON BRISA 18 9L'],
    ['Pañal Winny', 'PANAL WINNY'],
    ['CAFÉ TOSTAO', 'CAFE TOSTAO'],
]);

test('el alias encuentra el producto aunque el nombre no se parezca', function () {
    $t = tenderoConCatalogo();
    $p = productoDeCatalogo('ACETAMINOFEN TABLETAS 500MG XUNIDAD', null, $t['categoria']);

    ProductAlias::create([
        'products_id' => $p->products_id,
        'alias'       => ProductAlias::normalizar('ACETAMINOFEN'),
        'origen'      => 'equipo',
    ]);

    expect(ProductAlias::resolver('ACETAMINOFEN'))->toBe($p->products_id);
    // Y da igual cómo venga escrito.
    expect(ProductAlias::resolver('  acetaminofen '))->toBe($p->products_id);
});

test('sin alias no inventa nada', function () {
    tenderoConCatalogo();

    expect(ProductAlias::resolver('ALGO QUE NADIE DECIDIO'))->toBeNull();
});

test('el alias de una tienda gana sobre el general', function () {
    /*
     * Un local puede llamar «la grande» a algo que en el resto significa otra
     * cosa. El alias propio manda; el general es el que vale para todos.
     */
    $t = tenderoConCatalogo();
    $general = productoDeCatalogo('GASEOSA GRANDE GENERICA', null, $t['categoria']);
    $suyo = productoDeCatalogo('COCA COLA 2.25', null, $t['categoria']);

    ProductAlias::create([
        'products_id' => $general->products_id,
        'alias'       => ProductAlias::normalizar('LA GRANDE'),
        'origen'      => 'equipo',
    ]);

    ProductAlias::create([
        'products_id' => $suyo->products_id,
        'alias'       => ProductAlias::normalizar('LA GRANDE'),
        'busines_id'  => $t['mio'],
        'origen'      => 'equipo',
    ]);

    expect(ProductAlias::resolver('LA GRANDE', $t['mio']))->toBe($suyo->products_id);
    expect(ProductAlias::resolver('LA GRANDE'))->toBe($general->products_id);
});

test('la carga usa el alias en vez de crear un duplicado', function () {
    /*
     * El caso exacto que ocurrió en producción, en pequeño.
     */
    $t = tenderoConCatalogo();
    $p = productoDeCatalogo('NORAVER-P GARGANTA', null, $t['categoria']);

    ProductAlias::create([
        'products_id' => $p->products_id,
        'alias'       => ProductAlias::normalizar('NORAVER GARGANTA'),
        'origen'      => 'equipo',
    ]);

    $antes = Product::count();

    $carga = cargarDesdePdv($t, archivoBiff2(
        ['barras', 'nombre', 'venta1'],
        [['', 'NORAVER GARGANTA', 2500.0]],
    ), $t['categoria']);

    expect($carga->rechazados)->toBe(0);
    expect(Product::count())->toBe($antes);   // no se creó nada

    expect(DB::table('products_business')
        ->where('busines_id', $t['mio'])
        ->where('products_id', $p->products_id)
        ->value('price'))->toEqual(2500.0);
});

test('el nombre con espacios de mas ya no crea un producto nuevo', function () {
    /*
     * Tres de los doce duplicados de producción eran exactamente esto.
     */
    $t = tenderoConCatalogo();
    productoDeCatalogo('AJINOMEN CARNE 80 GR', null, $t['categoria']);

    $antes = Product::count();

    cargarDesdePdv($t, archivoBiff2(
        ['barras', 'nombre', 'venta1'],
        [['', 'AJINOMEN CARNE  80 GR', 4500.0]],
    ), $t['categoria']);

    expect(Product::count())->toBe($antes);
});

test('dos aliases generales iguales no pueden existir', function () {
    /*
     * Sin el único, dos cargas a la vez crearían dos filas y la consulta
     * siguiente tendría que elegir a ciegas entre dos productos distintos.
     *
     * El ámbito general se guarda como 0 y no como NULL justamente para que
     * este único funcione: en SQL dos NULL se consideran distintos, así que
     * con NULL esta prueba no pasaría por más índice que hubiera.
     */
    $t = tenderoConCatalogo();
    $a = productoDeCatalogo('UNO', null, $t['categoria']);
    $b = productoDeCatalogo('OTRO', null, $t['categoria']);

    ProductAlias::create(['products_id' => $a->products_id, 'alias' => 'MISMO', 'origen' => 'equipo']);

    expect(fn () => ProductAlias::create([
        'products_id' => $b->products_id, 'alias' => 'MISMO', 'origen' => 'equipo',
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

test('un nombre ambiguo se queda SIN alias, para que la carga pregunte', function () {
    /*
     * El catálogo tiene «PANELA» dos veces. Si el alias apuntara al primero,
     * una carga elegiría uno de los dos en silencio y el precio acabaría en el
     * producto equivocado la mitad de las veces.
     *
     * Sin alias, la carga cae en el camino de siempre: ve dos productos que se
     * llaman igual y RECHAZA la fila pidiendo el código de barras, que es la
     * pregunta correcta y la que el código existe para responder.
     */
    $t = tenderoConCatalogo();

    productoDeCatalogo('PANELA', null, $t['categoria']);
    expect(ProductAlias::resolver('PANELA'))->not->toBeNull();

    productoDeCatalogo('PANELA', null, $t['categoria']);
    expect(ProductAlias::resolver('PANELA'))->toBeNull();

    $carga = cargarDesdePdv($t, archivoBiff2(
        ['barras', 'nombre', 'venta1'],
        [['', 'PANELA', 3000.0]],
    ), $t['categoria']);

    expect($carga->rechazados)->toBe(1);
    expect($carga->resumen['errores'][0]['motivo'])->toContain('código de barras');
});

test('un alias decidido a mano no lo borra uno automatico', function () {
    /*
     * La decisión de una persona pesa más que la coincidencia de un nombre.
     */
    $t = tenderoConCatalogo();
    $bueno = productoDeCatalogo('ACETAMINOFEN TABLETAS 500MG', null, $t['categoria']);

    ProductAlias::create([
        'products_id' => $bueno->products_id,
        'alias'       => ProductAlias::normalizar('ACETAMINOFEN'),
        'origen'      => 'equipo',
    ]);

    // Llega un producto que se llama justo así.
    productoDeCatalogo('ACETAMINOFEN', null, $t['categoria']);

    expect(ProductAlias::resolver('ACETAMINOFEN'))->toBe($bueno->products_id);
});
