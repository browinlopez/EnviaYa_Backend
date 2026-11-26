<?php

use App\Exports\Comercials\ReportGeneralComercial;
use App\Exports\Compartido\SinFormulas;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Excel as FormatoExcel;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Ningún Excel del sistema ejecuta lo que un tercero escribió.
 *
 * El reporte del conjunto ya guardaba como texto lo que empezara por «=»; los
 * libros financiero, comercial y operacional del panel interno no: el nombre
 * de un negocio o de un usuario con `=HYPERLINK(...)` se abría como fórmula en
 * el computador de quien bajaba el reporte.
 */
test('el nombre de un negocio con =HYPERLINK llega al libro comercial como texto', function () {
    $c = conjuntoConPersonal();
    repartidorConPedidoEn($c['complexId']);

    $malo = '=HYPERLINK("http://malo.test","Tienda")';
    DB::table('business')->update(['name' => $malo]);

    $binario = Excel::raw(
        new ReportGeneralComercial(now()->subDay(), now()->addDay()),
        FormatoExcel::XLSX,
    );

    $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($ruta, $binario);
    $libro = IOFactory::load($ruta);
    @unlink($ruta);

    $hoja = $libro->getSheetByName('Top Negocios');

    expect($hoja->getCell('A2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($hoja->getCell('A2')->getValue())->toBe($malo)
        // Las cifras siguen siendo cifras.
        ->and($hoja->getCell('B2')->getDataType())->toBe(DataType::TYPE_NUMERIC);

    foreach ($libro->getAllSheets() as $h) {
        foreach ($h->getCellCollection()->getCoordinates() as $coordenada) {
            expect($h->getCell($coordenada)->getDataType())
                ->not->toBe(DataType::TYPE_FORMULA, "{$h->getTitle()}!{$coordenada}");
        }
    }
});

test('toda hoja de app/Exports hereda el binder sin formulas', function () {
    /*
     * La protección es por hoja (el binder global lo usa también el lector de
     * las importaciones), así que una hoja nueva que no herede reabre el hueco
     * en silencio. Esta prueba lo nota.
     */
    $sueltas = [];

    $archivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Exports')));

    foreach ($archivos as $archivo) {
        if ($archivo->getExtension() !== 'php') {
            continue;
        }

        $relativa = str_replace([app_path().DIRECTORY_SEPARATOR, '.php', DIRECTORY_SEPARATOR], ['', '', '\\'], $archivo->getPathname());
        $clase = 'App\\'.$relativa;

        if (! class_exists($clase) || (new ReflectionClass($clase))->isAbstract()) {
            continue;
        }

        $esHoja = is_subclass_of($clase, FromArray::class)
            || is_subclass_of($clase, FromCollection::class)
            || is_subclass_of($clase, FromQuery::class)
            || is_subclass_of($clase, FromView::class);

        if ($esHoja && ! is_subclass_of($clase, SinFormulas::class)) {
            $sueltas[] = $clase;
        }
    }

    expect($sueltas)->toBe([]);
});
