<?php

use App\Models\Product\CatalogUpload;
use App\Models\Product\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * SUBIR EL ARCHIVO QUE LA TIENDA YA TIENE.
 *
 * `CargaDeCatalogoTest` prueba la carga con la plantilla del proyecto. Esto
 * prueba lo otro: el archivo que el tendero exporta de su propio punto de
 * venta y sube tal cual, sin renombrar veintisiete columnas a mano.
 *
 * Son tres problemas distintos y los tres hacían fallar la carga entera:
 *
 *  · EL FORMATO. Los puntos de venta de las tiendas exportan BIFF2, el .xls de
 *    Excel 2.0. PhpSpreadsheet ni siquiera lo reconoce, así que la carga moría
 *    con «No se pudo leer el archivo» y el tendero no podía entender por qué:
 *    su archivo se llama .xls y Excel lo abre.
 *
 *  · LAS COLUMNAS. Se llaman `barras`, `venta1` y `existencia`, no
 *    `codigo_barras`, `precio` y `cantidad`. Y hay una columna `codigo` que es
 *    el número interno del artículo y NO un código de barras.
 *
 *  · LA CATEGORÍA. No traen ninguna, y sin ella el importador rechazaba cada
 *    fila nueva.
 */

/**
 * Un .xls BIFF2, armado a mano.
 *
 * No hay librería en PHP que lo escriba —PhpSpreadsheet ni siquiera sabe
 * leerlo, que es el motivo de que exista `LectorDeArchivoDeCatalogo`— así que
 * se construyen los bytes. El formato es una cadena de registros: tipo (2),
 * largo (2) y cuerpo. Una celda lleva fila (2), columna (2), tres bytes de
 * formato y el valor.
 */
function archivoBiff2(array $encabezados, array $filas): UploadedFile
{
    $texto = function (int $fila, int $col, string $v): string {
        /*
         * A CP1252, que es lo que de verdad lleva un archivo BIFF2: el
         * formato es de 1987 y anterior a Unicode. Escribirlo en UTF-8
         * haria una prueba que pasa contra un archivo que no existe, y
         * el lector —que convierte DESDE CP1252— doblaria la Ñ.
         */
        $v = mb_convert_encoding($v, 'CP1252', 'UTF-8');

        $cuerpo = pack('vv', $fila, $col) . "\x00\x00\x00" . chr(strlen($v)) . $v;

        return pack('vv', 0x04, strlen($cuerpo)) . $cuerpo;
    };

    $numero = function (int $fila, int $col, float $v): string {
        $cuerpo = pack('vv', $fila, $col) . "\x00\x00\x00" . pack('d', $v);

        return pack('vv', 0x03, strlen($cuerpo)) . $cuerpo;
    };

    // BOF: lo primero de todo archivo BIFF2, y lo que lo identifica.
    $bytes = "\x09\x00\x04\x00" . pack('vv', 0, 0x10);

    foreach ($encabezados as $c => $h) {
        $bytes .= $texto(0, $c, $h);
    }

    foreach ($filas as $f => $valores) {
        foreach ($valores as $c => $v) {
            $bytes .= (is_int($v) || is_float($v))
                ? $numero($f + 1, $c, (float) $v)
                : $texto($f + 1, $c, (string) $v);
        }
    }

    $bytes .= pack('vv', 0x0A, 0);  // EOF

    return UploadedFile::fake()->createWithContent('ARTICULOS.XLS', $bytes);
}

/** Sube el archivo y devuelve el expediente ya procesado. */
function cargarDesdePdv(array $t, UploadedFile $archivo, ?int $categoria = null): CatalogUpload
{
    Sanctum::actingAs($t['user']);

    $cuerpo = ['archivo' => $archivo];

    if ($categoria !== null) {
        $cuerpo['categoria_id'] = $categoria;
    }

    $r = test()->post('/v1/negocio/productos/cargas', $cuerpo)->assertStatus(202);

    return CatalogUpload::find($r->json('data.catalog_upload_id'))->fresh();
}

/* ---------------------------------------------------------------------- */

test('lee el .xls viejo que exportan los puntos de venta', function () {
    $t = tenderoConCatalogo();
    productoDeCatalogo('ARROZ DIANA 500G', '7702011000011', $t['categoria']);

    $carga = cargarDesdePdv($t, archivoBiff2(
        ['codigo', 'barras', 'nombre', 'existencia', 'venta1'],
        [['100194', '7702011000011', 'ARROZ DIANA 500G', -12.0, 4800.0]],
    ));

    expect($carga->estado)->toBe('terminada');
    expect($carga->filas)->toBe(1);
    expect($carga->rechazados)->toBe(0);
});

test('la enie del archivo viejo llega entera', function () {
    /*
     * BIFF2 es anterior a Unicode y el texto viene en la página de códigos de
     * Windows. Sin convertirlo, «PAÑAL» se guardaba roto en `products`, que es
     * el catálogo compartido de todas las tiendas.
     */
    $t = tenderoConCatalogo();

    cargarDesdePdv($t, archivoBiff2(
        ['barras', 'nombre', 'venta1', 'categoria'],
        [['7702011000022', 'PAÑAL WINNY ETAPA 3', 2500.0, 'Abarrotes']],
    ));

    expect(Product::where('barcode', '7702011000022')->value('name'))
        ->toBe('PAÑAL WINNY ETAPA 3');
});

test('el codigo interno del punto de venta NO se guarda como codigo de barras', function () {
    /*
     * ESTA ES LA IMPORTANTE.
     *
     * En el archivo de un punto de venta, `codigo` es el número interno del
     * artículo —«101608»— y `barras` es el EAN. Tomar el primero por código de
     * barras lo guardaría en `products.barcode`, que es ÚNICO y del catálogo
     * COMPARTIDO: el número interno de una tienda bloquearía ese valor para
     * siempre, y el día que alguien escanee el producto de verdad no lo
     * encontraría nunca.
     *
     * Un EAN tiene de 8 a 14 dígitos; un código interno, cinco o seis.
     */
    $t = tenderoConCatalogo();

    cargarDesdePdv($t, archivoBiff2(
        ['codigo', 'nombre', 'venta1'],
        [['101608', 'CERVEZA ARTESANAL', 6000.0]],
    ), $t['categoria']);

    $p = Product::where('name', 'CERVEZA ARTESANAL')->first();

    expect($p)->not->toBeNull();
    expect($p->barcode)->toBeNull();
});

test('un codigo de trece digitos en la columna codigo SI es un codigo de barras', function () {
    // Hay archivos donde la única columna se llama `codigo` y sí trae el EAN.
    $t = tenderoConCatalogo();

    cargarDesdePdv($t, archivoBiff2(
        ['codigo', 'nombre', 'venta1'],
        [['7702011000033', 'PANELA', 3000.0]],
    ), $t['categoria']);

    expect(Product::where('name', 'PANELA')->value('barcode'))->toBe('7702011000033');
});

test('las existencias negativas no pisan el conteo que hizo el tendero', function () {
    /*
     * El inventario real de una tienda llegó con 975 existencias negativas de
     * 1.010 filas, una de ellas de -17.144: es lo que pasa cuando un punto de
     * venta lleva años descontando ventas sin que nadie hiciera un conteo
     * inicial. Escribir eso encima del número que el tendero sí contó a mano
     * sería perder el único dato bueno de los dos.
     */
    $t = tenderoConCatalogo();
    $p = productoDeCatalogo('ACEITE', '7702011000044', $t['categoria']);

    DB::table('products_business')->insert([
        'busines_id' => $t['mio'], 'products_id' => $p->products_id,
        'price' => 5000, 'amount' => 30, 'qualification' => 0,
    ]);

    cargarDesdePdv($t, archivoBiff2(
        ['barras', 'nombre', 'existencia', 'venta1'],
        [['7702011000044', 'ACEITE', -62.0, 5500.0]],
    ));

    $fila = DB::table('products_business')
        ->where('busines_id', $t['mio'])
        ->where('products_id', $p->products_id)
        ->first();

    // El precio SÍ se actualiza: es a lo que el tendero vuelve a subir el archivo.
    expect((float) $fila->price)->toBe(5500.0);
    // Las existencias se quedan como estaban.
    expect((int) $fila->amount)->toBe(30);
});

test('la categoria elegida al subir recoge lo que el archivo no clasifica', function () {
    /*
     * Los archivos de un punto de venta no traen columna de categoría. Sin
     * esto, una carga de 1.010 filas daba 1.010 rechazos, todos con el mismo
     * motivo: técnicamente correcto y completamente inútil.
     */
    $t = tenderoConCatalogo();

    $carga = cargarDesdePdv($t, archivoBiff2(
        ['barras', 'nombre', 'venta1'],
        [['7702011000055', 'CHORIZO CUNIT', 4400.0]],
    ), $t['categoria']);

    expect($carga->rechazados)->toBe(0);
    expect(Product::where('name', 'CHORIZO CUNIT')->value('category_id'))
        ->toBe($t['categoria']);
});

test('sin categoria elegida, lo nuevo se rechaza diciendo que falta', function () {
    $t = tenderoConCatalogo();

    $carga = cargarDesdePdv($t, archivoBiff2(
        ['barras', 'nombre', 'venta1'],
        [['7702011000066', 'ALGO QUE NO EXISTE', 1000.0]],
    ));

    expect($carga->rechazados)->toBe(1);
    expect($carga->resumen['errores'][0]['motivo'])->toContain('Elige una al subir');
});

test('volver a subir con otro precio lo actualiza y no duplica la fila', function () {
    // Es lo que el tendero hace cada vez que le suben los precios.
    $t = tenderoConCatalogo();
    productoDeCatalogo('AZUCAR', '7702011000077', $t['categoria']);

    $archivo = fn (float $precio) => archivoBiff2(
        ['barras', 'nombre', 'venta1'],
        [['7702011000077', 'AZUCAR', $precio]],
    );

    cargarDesdePdv($t, $archivo(4000.0));
    $segunda = cargarDesdePdv($t, $archivo(4600.0));

    expect($segunda->actualizados)->toBe(1);
    expect($segunda->creados)->toBe(0);

    expect(DB::table('products_business')->where('busines_id', $t['mio'])->count())->toBe(1);
    expect((float) DB::table('products_business')->where('busines_id', $t['mio'])->value('price'))
        ->toBe(4600.0);
});

test('una categoria que no existe no tumba la fila si hay una elegida', function () {
    /*
     * Dos tiendas llaman «Aseo» y «Aseo y Limpieza» a lo mismo. Rechazar la
     * fila por eso deja el producto fuera del catálogo y nadie vuelve a
     * mirarlo; mandarlo a la categoría elegida lo deja visible y corregible.
     */
    $t = tenderoConCatalogo();

    $carga = cargarDesdePdv($t, archivoBiff2(
        ['barras', 'nombre', 'venta1', 'categoria'],
        [['7702011000088', 'JABON REY', 2000.0, 'Aseo del hogar']],
    ), $t['categoria']);

    expect($carga->rechazados)->toBe(0);
    expect(Product::where('name', 'JABON REY')->value('category_id'))->toBe($t['categoria']);
});
