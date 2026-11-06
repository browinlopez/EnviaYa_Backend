<?php

use App\Imports\LectorDeArchivoDeCatalogo;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * LA PLANTILLA DEL CATÁLOGO, COMO EXCEL.
 *
 * Lo que se rompería sin que nadie lo note:
 *  1. Que la plantilla traiga sus tres hojas y los encabezados que el
 *     importador entiende.
 *  2. Que «con mi catálogo» traiga los productos de ESTA tienda y no los del
 *     vecino.
 *  3. Que al subir un archivo de varias hojas se lea la PRIMERA. Antes se leía
 *     la última, y con la hoja de instrucciones al final se habría importado
 *     la ayuda en vez de los productos.
 */
function libroDeLaRespuesta($r): Spreadsheet
{
    $ruta = tempnam(sys_get_temp_dir(), 'plantilla') . '.xlsx';
    file_put_contents($ruta, $r->streamedContent());

    return IOFactory::load($ruta);
}

test('la plantilla es un excel con productos, instrucciones y la lista de categorías', function () {
    $t = tenderoConCatalogo();
    Sanctum::actingAs($t['user']);

    $r = $this->get('/v1/negocio/productos/cargas/plantilla')->assertOk();
    expect($r->headers->get('content-type'))->toContain('spreadsheetml');

    $libro = libroDeLaRespuesta($r);
    $titulos = array_map(fn ($h) => $h->getTitle(), $libro->getAllSheets());

    expect($titulos)->toBe(['Productos', 'Instrucciones', 'Listas'])
        ->and($libro->getSheet(0)->rangeToArray('A1:C1')[0])->toBe(['Código de barras', 'Precio', 'Cantidad'])
        ->and($libro->getSheetByName('Listas')->getCell('A2')->getValue())->toBe('Abarrotes')
        // Vacía: nada de filas de ejemplo que se importen solas.
        ->and($libro->getSheet(0)->getCell('A2')->getValue())->toBeNull();
});

test('con mi catálogo trae los productos de mi tienda y no los del vecino', function () {
    $t = tenderoConCatalogo();
    $mio = productoDeCatalogo('Arroz Diana 500 g', '7702511000014', $t['categoria']);
    $ajeno = productoDeCatalogo('Cerveza del vecino', '7700000000001', $t['categoria']);
    enLaTienda($t['mio'], $mio->products_id, 3400, 12);
    enLaTienda($t['vecino'], $ajeno->products_id, 2000, 5);

    Sanctum::actingAs($t['user']);
    $hoja = libroDeLaRespuesta(
        $this->get('/v1/negocio/productos/cargas/plantilla?con=productos')->assertOk(),
    )->getSheet(0);

    // El código como texto: así no se vuelve 7,70251E+12.
    expect($hoja->getCell('A2')->getValue())->toBe('7702511000014')
        ->and($hoja->getCell('B2')->getValue())->toEqual(3400)
        ->and($hoja->getCell('C2')->getValue())->toEqual(12)
        ->and($hoja->getCell('A3')->getValue())->toBeNull();
});

test('de un archivo con varias hojas se lee la primera', function () {
    $libro = new Spreadsheet();
    $libro->getActiveSheet()->fromArray([['codigo_barras', 'precio', 'cantidad'], ['7702511000014', 3400, 12]]);
    $libro->createSheet()->fromArray([['esto', 'es', 'ayuda'], ['no', 'se', 'importa']]);

    $ruta = tempnam(sys_get_temp_dir(), 'varias') . '.xlsx';
    (new Xlsx($libro))->save($ruta);

    $filas = app(LectorDeArchivoDeCatalogo::class)->leer($ruta);

    expect($filas)->toHaveCount(1)
        ->and((string) $filas[0]['codigo_barras'])->toBe('7702511000014');
});
