<?php

use App\Models\Conjunto\ComplexStaff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * El Excel del reporte del conjunto, bajado por el mismo endpoint que usa el
 * panel.
 *
 * Lo que se fija acá es lo que se le prometió al administrador en la
 * pantalla: «el Excel trae exactamente estos mismos datos, con la misma
 * consulta de esta pantalla. Sin nombres de residentes». Por eso las cifras
 * se comparan contra el JSON del MISMO rango, y se recorre el archivo entero
 * buscando los nombres de quien vive en el conjunto.
 */

/**
 * Un conjunto con movimiento de verdad: domiciliarios por QR y por cédula,
 * una visita anotada a mano, pedidos a dos torres y una torre escrita con
 * mala intención.
 */
function conjuntoConMovimiento(): array
{
    $c = conjuntoConPersonal(ComplexStaff::DUENO);

    $domi = repartidorConPedidoEn($c['complexId'], '1090000001');
    $otro = repartidorConPedidoEn($c['complexId'], '1090000002');

    // El nombre del domiciliario lo escribe él: puede traer una fórmula.
    DB::table('user')->where('user_id', $otro->user_id)->update(['name' => '=HYPERLINK("http://malo.test","Juan")']);
    DB::table('user')->where('user_id', $domi->user_id)->update(['name' => 'Carlos Repartidor']);

    // Los residentes: sus nombres NO pueden salir en el archivo.
    $residentes = DB::table('user_address as ua')
        ->join('user as u', 'u.user_id', '=', 'ua.user_id')
        ->where('ua.complex_id', $c['complexId'])
        ->pluck('u.user_id');
    DB::table('user')->where('user_id', $residentes[0])->update(['name' => 'Residente Secreta Pérez']);
    DB::table('user')->where('user_id', $residentes[1])->update(['name' => 'Vecino Confidencial Gómez']);

    $hoy = CarbonImmutable::today();
    $entrada = fn (array $e) => DB::table('complex_entries')->insert($e + [
        'complex_id' => $c['complexId'],
        'kind' => 'domiciliario',
        'orders_count' => 0,
        'updated_at' => now(),
    ]);
    $pedido = fn (string $torre, string $apto) => json_encode([[
        'tower' => $torre, 'apartment' => $apto, 'orderSales_id' => 1,
        // Lo que lleva el JSON real; tampoco debe salir.
        'business_name' => 'Tienda',
    ]]);

    foreach (range(0, 3) as $i) {
        $entrada([
            'domiciliary_id' => $domi->domiciliary_id, 'method' => 'codigo',
            'orders_count' => 1, 'orders' => $pedido('3', '502'),
            'created_at' => $hoy->subDays(2)->setTime(10, $i)->toDateTimeString(),
            'exited_at' => $hoy->subDays(2)->setTime(10, $i + 8)->toDateTimeString(),
        ]);
    }

    $entrada([
        'domiciliary_id' => $otro->domiciliary_id, 'method' => 'cedula',
        'orders_count' => 1, 'orders' => $pedido('=HYPERLINK("http://malo.test","T")', '101'),
        'created_at' => $hoy->subDay()->setTime(12, 0)->toDateTimeString(),
    ]);
    $entrada([
        'domiciliary_id' => $otro->domiciliary_id, 'method' => 'codigo',
        'orders_count' => 2, 'orders' => json_encode([
            ['tower' => '7', 'apartment' => '101'], ['tower' => '3', 'apartment' => '201'],
        ]),
        'created_at' => $hoy->setTime(8, 30)->toDateTimeString(),
    ]);

    // Una visita: el celador anota quién es y quién autorizó.
    $entrada([
        'kind' => 'visitante', 'domiciliary_id' => null, 'method' => 'manual',
        'visitor_name' => 'Visitante Anónimo Ruiz', 'authorized_by' => 'Residente Secreta Pérez',
        'tower' => '3', 'apartment' => '502',
        'created_at' => $hoy->subDay()->setTime(15, 0)->toDateTimeString(),
    ]);

    return $c;
}

/** Baja el Excel por el endpoint y lo abre con las gráficas. */
function bajarReporte($prueba, string $consulta): Spreadsheet
{
    $respuesta = $prueba->get('/v1/conjunto/reportes/excel?'.$consulta)->assertOk();

    $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
    copy($respuesta->baseResponse->getFile()->getPathname(), $ruta);

    $lector = IOFactory::createReader('Xlsx');
    $lector->setIncludeCharts(true);
    $libro = $lector->load($ruta);
    @unlink($ruta);

    return $libro;
}

/** El valor de la columna B en la fila cuyo rótulo (columna A) es `$rotulo`. */
function cifraDelConsolidado(Spreadsheet $libro, string $rotulo): mixed
{
    $hoja = $libro->getSheetByName('Consolidado');

    foreach ($hoja->getRowIterator() as $fila) {
        $n = $fila->getRowIndex();
        if ($hoja->getCell("A{$n}")->getValue() === $rotulo) {
            return $hoja->getCell("B{$n}")->getValue();
        }
    }

    return null;
}

test('el excel trae las hojas en orden y tres graficas nativas en el consolidado', function () {
    $c = conjuntoConMovimiento();
    Sanctum::actingAs($c['user']);

    $hasta = CarbonImmutable::today();
    $desde = $hasta->subDays(29);

    $libro = bajarReporte($this, "desde={$desde->toDateString()}&hasta={$hasta->toDateString()}");

    expect($libro->getSheetNames())->toBe([
        'Consolidado', 'Entradas por día', 'Identificación', 'Por torre', 'Domiciliarios',
    ]);

    $graficas = $libro->getSheetByName('Consolidado')->getChartCollection();
    expect(count($graficas))->toBeGreaterThanOrEqual(3);

    // Cada gráfica lee una hoja de detalle, no números sueltos.
    $fuentes = collect($graficas)->map(fn ($g) => $g->getPlotArea()->getPlotGroup()[0]
        ->getPlotValues()[0]->getDataSource())->all();

    expect($fuentes)->toBe([
        "'Entradas por día'!\$C\$7:\$C\$36",
        "'Identificación'!\$B\$7:\$B\$9",
        "'Por torre'!\$B\$7:\$B\$9",
    ]);

    // Un renglón por día del rango, también los días sin nadie.
    expect($libro->getSheetByName('Entradas por día')->getCell('A36')->getValue())->not->toBeNull()
        ->and($libro->getSheetByName('Entradas por día')->getCell('A37')->getValue())->toBe('Total');
});

test('las cifras del excel son las mismas que las del json del mismo rango', function () {
    $c = conjuntoConMovimiento();
    Sanctum::actingAs($c['user']);

    $hasta = CarbonImmutable::today();
    $desde = $hasta->subDays(29);
    $rango = "desde={$desde->toDateString()}&hasta={$hasta->toDateString()}";

    $l = $this->getJson('/v1/conjunto/reportes?'.$rango)->assertOk()->json('libro');
    $libro = bajarReporte($this, $rango);

    $todas = $l['identificacion']['codigo'] + $l['identificacion']['cedula'] + $l['identificacion']['manual'];

    expect(cifraDelConsolidado($libro, 'Entradas a la portería'))->toBe($l['entradas'])
        ->and($l['entradas'])->toBe(7)
        ->and(cifraDelConsolidado($libro, "Pedidos de VeciPa'Ya recibidos por la portería"))->toBe($l['pedidos_recibidos'])
        ->and(cifraDelConsolidado($libro, 'Visitantes anotados'))->toBe($l['visitantes'])
        // El % por QR sobre TODAS las entradas, como la cabecera de la pantalla.
        ->and(round(cifraDelConsolidado($libro, 'Verificados por QR'), 4))->toBe(round($l['identificacion']['codigo'] / $todas, 4))
        ->and(round(cifraDelConsolidado($libro, 'Promedio de entradas por día'), 1))->toBe(round($l['entradas'] / count($l['por_dia']), 1))
        // Sin periodo anterior con qué comparar, no se inventa un porcentaje.
        ->and(cifraDelConsolidado($libro, 'Cambio frente al periodo anterior'))->toBe('—');

    // El detalle por día suma lo mismo que la serie del JSON, día por día.
    $porDia = $libro->getSheetByName('Entradas por día');
    foreach ($l['por_dia'] as $i => $d) {
        expect($porDia->getCell('C'.(7 + $i))->getValue())->toBe($d['entradas']);
    }

    // Las torres, en el mismo orden y con las mismas cuentas.
    $torres = $libro->getSheetByName('Por torre');
    foreach ($l['por_torre'] as $i => $t) {
        expect((string) $torres->getCell('A'.(7 + $i))->getValue())->toBe($t['torre'])
            ->and($torres->getCell('B'.(7 + $i))->getValue())->toBe($t['pedidos']);
    }

    // Los domiciliarios de la pantalla, con su % por QR y su tiempo adentro.
    $domis = $libro->getSheetByName('Domiciliarios');
    foreach ($l['domiciliarios'] as $i => $d) {
        expect($domis->getCell('B'.(7 + $i))->getValue())->toBe($d['nombre'])
            ->and($domis->getCell('C'.(7 + $i))->getValue())->toBe($d['entradas'])
            ->and(round((float) $domis->getCell('D'.(7 + $i))->getValue(), 4))->toBe(round((float) $d['por_qr'], 4));
    }
    expect($domis->getCell('E7')->getValue())->toBe(8);

    $ident = $libro->getSheetByName('Identificación');
    expect($ident->getCell('B7')->getValue())->toBe($l['identificacion']['codigo'])
        ->and($ident->getCell('B8')->getValue())->toBe($l['identificacion']['cedula'])
        ->and($ident->getCell('B9')->getValue())->toBe($l['identificacion']['manual']);
});

test('el excel no trae nombres de residentes ni deja formulas de terceros', function () {
    $c = conjuntoConMovimiento();
    Sanctum::actingAs($c['user']);

    $libro = bajarReporte($this, 'desde='.CarbonImmutable::today()->subDays(6)->toDateString());

    $textos = [];
    foreach ($libro->getAllSheets() as $hoja) {
        foreach ($hoja->getCellCollection()->getCoordinates() as $coordenada) {
            $celda = $hoja->getCell($coordenada);

            // Ni una fórmula: lo que escribió un tercero se guarda como texto.
            expect($celda->getDataType())->not->toBe(DataType::TYPE_FORMULA, $hoja->getTitle().'!'.$coordenada);

            $textos[] = (string) $celda->getValue();
        }

        $textos[] = $hoja->getHeaderFooter()->getOddHeader().$hoja->getHeaderFooter()->getOddFooter();
    }

    $todo = implode("\n", $textos);

    expect($todo)->not->toContain('Residente Secreta')
        ->and($todo)->not->toContain('Vecino Confidencial')
        ->and($todo)->not->toContain('Visitante Anónimo')
        // Sí están los domiciliarios, y la torre maliciosa tal cual, como texto.
        ->and($todo)->toContain('Carlos Repartidor')
        ->and($todo)->toContain('=HYPERLINK("http://malo.test","T")');

    $torres = $libro->getSheetByName('Por torre');
    $maliciosa = collect(range(7, 9))->map(fn ($f) => $torres->getCell("A{$f}"))
        ->first(fn ($celda) => str_starts_with((string) $celda->getValue(), '='));

    expect($maliciosa)->not->toBeNull()
        ->and($maliciosa->getDataType())->toBe(DataType::TYPE_STRING);
});
