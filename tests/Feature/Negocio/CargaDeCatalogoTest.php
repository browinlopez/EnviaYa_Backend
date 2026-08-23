<?php

use App\Models\Product\CatalogUpload;
use App\Models\Product\Product;
use App\Services\ImportadorDeCatalogo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * LA CARGA MASIVA.
 *
 * La prueba que justifica todo esto es «subir dos veces no duplica». Lo que
 * había antes —`ProductsImport` con `ToModel` y sin deduplicar— creaba filas
 * nuevas en cada importación: volver a subir el mismo archivo duplicaba el
 * catálogo entero, y no había nada que lo impidiera ni en el código ni en la
 * base, porque `products_business` no tenía un solo índice.
 *
 * La identidad es el código de barras. Es lo que hace que repetir sea
 * actualizar, y lo que permite que la plantilla tenga tres columnas en vez de
 * doce: lo demás sale del catálogo.
 */

/** Un CSV con encabezados, que es lo que `WithHeadingRow` sabe leer. */
function archivoDeCatalogo(array $filas, array $encabezados = null): UploadedFile
{
    $encabezados ??= ['codigo_barras', 'nombre', 'marca', 'categoria', 'precio', 'cantidad'];

    $lineas = [implode(',', $encabezados)];

    foreach ($filas as $f) {
        $lineas[] = implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', $f));
    }

    return UploadedFile::fake()->createWithContent(
        'catalogo.csv',
        implode("\n", $lineas),
    );
}

/**
 * Sube el archivo y devuelve el expediente ya procesado.
 *
 * En pruebas la cola es `sync`, asi que el `dispatch` del controlador corre el
 * job dentro de la misma peticion. Eso conviene: se prueba el camino completo
 * —controlador, job, importador— y no solo el importador suelto. Llamar ademas
 * a `procesar()` a mano seria correrlo DOS veces, y la segunda contaria todo
 * como actualizado.
 */
function cargar(array $t, UploadedFile $archivo): CatalogUpload
{
    Sanctum::actingAs($t['user']);

    $r = test()->post('/v1/negocio/productos/cargas', ['archivo' => $archivo])
        ->assertStatus(202);

    return CatalogUpload::find($r->json('data.catalog_upload_id'))->fresh();
}

/* ---------------------------------------------------------------------- */

test('el archivo se guarda y se encola en vez de procesarse en la peticion', function () {
    Queue::fake();
    Storage::fake('local');

    $t = tenderoConCatalogo();
    Sanctum::actingAs($t['user']);

    $r = test()->post('/v1/negocio/productos/cargas', [
        'archivo' => archivoDeCatalogo([['7702011000011', 'ARROZ', '', 'Abarrotes', '4800', '10']]),
    ]);

    /*
     * 202 y no 200: se aceptó, no se terminó. Un Excel de cuatrocientas filas
     * hace ochocientas consultas y no cabe en el tiempo de una petición — el
     * tendero se quedaba mirando una barra hasta que el navegador se rendía y,
     * como el proceso sí seguía, volvía a subirlo.
     */
    $r->assertStatus(202);

    expect($r->json('data.estado'))->toBe('pendiente');

    Queue::assertPushed(\App\Jobs\ProcesarCargaDeCatalogo::class);
});

test('una fila con codigo conocido entra a la tienda sin tocar el catalogo', function () {
    $t = tenderoConCatalogo();
    $p = productoDeCatalogo('ARROZ DIANA 500G', '7702011000011', $t['categoria']);

    $carga = cargar($t, archivoDeCatalogo([
        ['7702011000011', 'COMO SE ME OCURRA LLAMARLO', '', 'Abarrotes', '4800', '10'],
    ]));

    expect($carga->estado)->toBe('terminada')
        ->and($carga->creados)->toBe(1)
        ->and($carga->rechazados)->toBe(0);

    // El nombre del archivo NO pisa el del catálogo: el código manda.
    expect(Product::find($p->products_id)->name)->toBe('ARROZ DIANA 500G');

    $fila = DB::table('products_business')
        ->where('busines_id', $t['mio'])->where('products_id', $p->products_id)->first();

    expect((float) $fila->price)->toBe(4800.0)
        ->and((int) $fila->amount)->toBe(10);
});

test('subir dos veces el mismo archivo actualiza y no duplica', function () {
    $t = tenderoConCatalogo();
    productoDeCatalogo('PANELA', '7702011000028', $t['categoria']);

    cargar($t, archivoDeCatalogo([['7702011000028', 'PANELA', '', 'Abarrotes', '3000', '5']]));

    $segunda = cargar($t, archivoDeCatalogo([['7702011000028', 'PANELA', '', 'Abarrotes', '3600', '8']]));

    /*
     * ESTA ES LA PRUEBA QUE JUSTIFICA EL CAMBIO ENTERO. Antes, esto dejaba dos
     * filas y el catálogo del tendero mostraba PANELA dos veces.
     */
    expect($segunda->creados)->toBe(0)
        ->and($segunda->actualizados)->toBe(1);

    $filas = DB::table('products_business')->where('busines_id', $t['mio'])->get();

    expect($filas)->toHaveCount(1)
        ->and((float) $filas[0]->price)->toBe(3600.0);
});

test('un codigo nuevo con nombre y categoria se da de alta y queda marcado', function () {
    $t = tenderoConCatalogo();

    $carga = cargar($t, archivoDeCatalogo([
        ['7702011000202', 'CHOCOLATE CORONA', 'Corona', 'Abarrotes', '9800', '4'],
    ]));

    expect($carga->rechazados)->toBe(0);

    $p = Product::where('barcode', '7702011000202')->first();

    expect($p)->not->toBeNull()
        ->and($p->name)->toBe('CHOCOLATE CORONA')
        ->and($p->brand)->toBe('Corona')
        // Se sabe de dónde salió, para poder revisarlo después sin frenar a
        // nadie hoy.
        ->and($p->origen)->toBe('excel');
});

test('un codigo nuevo sin categoria se rechaza diciendo la fila', function () {
    $t = tenderoConCatalogo();
    productoDeCatalogo('CONOCIDO', '7702011000011', $t['categoria']);

    // La primera fila entra y la segunda no: un archivo malo a la mitad no
    // puede tirar abajo lo que si servia.
    $carga = cargar($t, archivoDeCatalogo([
        ['7702011000011', 'CONOCIDO', '', 'Abarrotes', '1000', '1'],
        ['7702011000219', 'NUEVO SIN CATEGORIA', '', '', '5000', '2'],
    ]));

    expect($carga->creados)->toBe(1);

    expect($carga->rechazados)->toBe(1);

    $errores = $carga->resumen['errores'];

    /*
     * «Fila 3: …» es accionable; «error al importar» no lo es. La fila 3 y no
     * la 2 porque la 1 son los encabezados.
     */
    expect($errores[0]['fila'])->toBe(3)
        ->and($errores[0]['motivo'])->toContain('no trae categoría');
});

test('una categoria que no existe se rechaza con su nombre', function () {
    $t = tenderoConCatalogo();

    $carga = cargar($t, archivoDeCatalogo([
        ['7702011000226', 'ALGO', '', 'Lacteo', '5000', '2'],
    ]));

    expect($carga->rechazados)->toBe(1)
        ->and($carga->resumen['errores'][0]['motivo'])->toContain('Lacteo');
});

test('sin codigo se acepta el nombre exacto si identifica a uno solo', function () {
    $t = tenderoConCatalogo();
    $p = productoDeCatalogo('SAL REFISAL', '7702011000073', $t['categoria']);

    /*
     * Hay tiendas cuyo sistema no exporta el código, y negarles la carga por
     * eso sería empujarlas de vuelta al papel.
     */
    $carga = cargar($t, archivoDeCatalogo([['', 'SAL REFISAL', '', '', '2200', '6']]));

    expect($carga->rechazados)->toBe(0)
        ->and($carga->creados)->toBe(1);

    expect((float) DB::table('products_business')
        ->where('busines_id', $t['mio'])->where('products_id', $p->products_id)->value('price'))->toBe(2200.0);
});

test('un nombre repetido se rechaza pidiendo el codigo', function () {
    $t = tenderoConCatalogo();
    productoDeCatalogo('PANELA', '7702011000028', $t['categoria']);
    productoDeCatalogo('PANELA', '7702011000233', $t['categoria']);

    $carga = cargar($t, archivoDeCatalogo([['', 'PANELA', '', '', '3000', '5']]));

    expect($carga->rechazados)->toBe(1)
        // Es exactamente el caso que el código de barras existe para resolver.
        ->and($carga->resumen['errores'][0]['motivo'])->toContain('código de barras');
});

test('el codigo del archivo le pone codigo a un producto que no lo tenia', function () {
    $t = tenderoConCatalogo();
    $p = productoDeCatalogo('AVENA ALPINA', null, $t['categoria']);

    cargar($t, archivoDeCatalogo([['7702011000240', 'AVENA ALPINA', '', '', '3500', '4']]));

    /*
     * El catálogo se va sanando solo con cada carga en vez de quedarse a medias
     * para siempre: los 587 productos viejos no tienen código y así lo van
     * ganando sin que nadie los toque a mano.
     */
    expect(Product::find($p->products_id)->barcode)->toBe('7702011000240');
});

test('los precios escritos como en Colombia se entienden', function () {
    $t = tenderoConCatalogo();
    $p = productoDeCatalogo('COSTOSO', '7702011000257', $t['categoria']);

    // «$ 12.500» es como lo escribe una persona; «12500» como lo exporta un
    // sistema. Las dos cosas llegan en el mismo archivo.
    $carga = cargar($t, archivoDeCatalogo([['7702011000257', 'COSTOSO', '', '', '$ 12.500', '2']]));

    expect($carga->rechazados)->toBe(0);

    expect((float) DB::table('products_business')
        ->where('busines_id', $t['mio'])->where('products_id', $p->products_id)->value('price'))->toBe(12500.0);
});

test('una fila sin precio se rechaza en vez de dejar el producto invisible', function () {
    $t = tenderoConCatalogo();
    productoDeCatalogo('SIN PRECIO', '7702011000264', $t['categoria']);

    $carga = cargar($t, archivoDeCatalogo([['7702011000264', 'SIN PRECIO', '', '', '', '3']]));

    /*
     * Sin precio el producto no se le ofrece al comprador, así que aceptarlo en
     * silencio sería decirle al tendero que cargó algo que nadie va a ver.
     */
    expect($carga->rechazados)->toBe(1)
        ->and($carga->resumen['errores'][0]['motivo'])->toContain('precio');
});

test('las filas vacias del final no cuentan como error', function () {
    $t = tenderoConCatalogo();
    productoDeCatalogo('UNO', '7702011000271', $t['categoria']);

    $carga = cargar($t, archivoDeCatalogo([
        ['7702011000271', 'UNO', '', '', '1000', '1'],
        ['', '', '', '', '', ''],
        ['', '', '', '', '', ''],
    ]));

    // Una hoja de cálculo arrastra filas vacías al guardarla; no son errores
    // del tendero.
    expect($carga->rechazados)->toBe(0)
        ->and($carga->creados)->toBe(1);
});

test('una carga de otra tienda no se puede consultar', function () {
    $t = tenderoConCatalogo();

    $ajena = CatalogUpload::create([
        'busines_id' => $t['vecino'], 'archivo' => 'x.csv', 'estado' => 'terminada',
    ]);

    Sanctum::actingAs($t['user']);

    test()->getJson("/v1/negocio/productos/cargas/{$ajena->catalog_upload_id}")
        ->assertStatus(404);
});

test('la plantilla se descarga con las columnas que el importador entiende', function () {
    $t = tenderoConCatalogo();
    Sanctum::actingAs($t['user']);

    $r = test()->get('/v1/negocio/productos/cargas/plantilla')->assertOk();

    $csv = $r->streamedContent();

    expect($csv)->toContain('codigo_barras')
        ->and($csv)->toContain('precio')
        ->and($csv)->toContain('cantidad')
        // El BOM: sin él Excel abre el archivo en ANSI y parte las tildes.
        ->and(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF");
});
