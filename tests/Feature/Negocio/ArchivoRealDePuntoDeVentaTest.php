<?php

use App\Imports\LectorDeArchivoDeCatalogo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * LA VALIDACIÓN DEL ARCHIVO, CONTRA UN ARCHIVO DE VERDAD.
 *
 * Esta prueba existe por un fallo que NINGUNA otra podía ver.
 *
 * El resto de pruebas de carga usan `UploadedFile::fake()`, y un archivo falso
 * declara el tipo que uno le diga. La regla `mimes:xls` no mira la extensión:
 * adivina el tipo por el CONTENIDO y comprueba que ese tipo corresponda a la
 * extensión. Contra un archivo falso siempre corresponde, así que todo pasaba.
 *
 * Contra el archivo de verdad, no. El .xls que exportan los puntos de venta de
 * las tiendas es BIFF2 —el formato de Excel 2.0, de 1987— y nadie lo tiene en
 * su tabla de tipos: se adivina como `application/octet-stream`, que no
 * corresponde a `xls`. La carga se rechazaba con «debe ser un archivo de tipo
 * xlsx, xls, csv, txt» sobre un archivo .xls que Excel abre sin protestar, y
 * eso desde el otro lado no hay forma de entenderlo.
 *
 * Así que acá se construye un BIFF2 real en disco —con sus bytes, no con un
 * `fake()`— y se comprueba lo único que importa: que la regla lo deje pasar y
 * que el lector lo entienda.
 */

/** Escribe un .xls BIFF2 de verdad en disco y devuelve su ruta. */
function archivoBiff2EnDisco(array $encabezados, array $filas): string
{
    $texto = function (int $fila, int $col, string $v): string {
        // CP1252: BIFF2 es anterior a Unicode.
        $v = mb_convert_encoding($v, 'CP1252', 'UTF-8');
        $cuerpo = pack('vv', $fila, $col) . "\x00\x00\x00" . chr(strlen($v)) . $v;

        return pack('vv', 0x04, strlen($cuerpo)) . $cuerpo;
    };

    $numero = function (int $fila, int $col, float $v): string {
        $cuerpo = pack('vv', $fila, $col) . "\x00\x00\x00" . pack('d', $v);

        return pack('vv', 0x03, strlen($cuerpo)) . $cuerpo;
    };

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

    $bytes .= pack('vv', 0x0A, 0);

    $ruta = sys_get_temp_dir() . '/ARTICULOS-' . uniqid() . '.XLS';
    file_put_contents($ruta, $bytes);

    return $ruta;
}

test('la validacion deja pasar un xls de punto de venta de verdad', function () {
    $ruta = archivoBiff2EnDisco(
        ['codigo', 'barras', 'nombre', 'existencia', 'venta1'],
        [['101608', '7707430870618', '3 CORDILLERAS 269 ML LATA', -62.0, 6000.0]],
    );

    // `test: true` en el último argumento: se comporta como un archivo subido
    // de verdad, con la detección de tipo real que hace Symfony.
    $subido = new UploadedFile($ruta, 'ARTICULOS.XLS', null, null, true);

    /*
     * Con `mimes` esto fallaba. Se deja la comprobación del tipo adivinado
     * escrita para que, si alguien vuelve a poner `mimes`, la prueba explique
     * por qué no.
     */
    expect($subido->getMimeType())->toBe('application/octet-stream');

    $v = Validator::make(
        ['archivo' => $subido],
        ['archivo' => ['required', 'file', 'extensions:xlsx,xls,csv,txt', 'max:10240']],
    );

    expect($v->passes())->toBeTrue();

    unlink($ruta);
});

test('el lector entiende el xls de punto de venta y respeta las tildes', function () {
    $ruta = archivoBiff2EnDisco(
        ['codigo', 'barras', 'nombre', 'existencia', 'venta1'],
        [
            ['101608', '7707430870618', 'AREPA PEQUEÑA', -62.0, 3000.0],
            ['100774', '7702191163498', 'PAÑAL WINNY ETAPA 3', -130.0, 2500.0],
        ],
    );

    $filas = (new LectorDeArchivoDeCatalogo())->leer($ruta);

    expect($filas)->toHaveCount(2);

    // Los encabezados llegan normalizados, igual que con `WithHeadingRow`.
    expect(array_keys($filas[0]))->toBe(['codigo', 'barras', 'nombre', 'existencia', 'venta1']);

    expect($filas[0]['nombre'])->toBe('AREPA PEQUEÑA');
    expect($filas[1]['nombre'])->toBe('PAÑAL WINNY ETAPA 3');
    expect($filas[0]['barras'])->toBe('7707430870618');
    expect((float) $filas[1]['venta1'])->toBe(2500.0);

    unlink($ruta);
});

test('el lector reconoce que un csv NO es biff2 y lo deja a la libreria', function () {
    $ruta = sys_get_temp_dir() . '/catalogo-' . uniqid() . '.csv';
    file_put_contents($ruta, "codigo_barras,nombre,precio\n7702011000011,ARROZ,4800\n");

    $lector = new LectorDeArchivoDeCatalogo();

    expect($lector->esBiff2($ruta))->toBeFalse();

    $filas = $lector->leer($ruta);

    expect($filas)->toHaveCount(1);
    expect($filas[0]['nombre'])->toBe('ARROZ');

    unlink($ruta);
});
