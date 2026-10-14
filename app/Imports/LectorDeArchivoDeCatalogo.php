<?php

namespace App\Imports;

use Maatwebsite\Excel\Facades\Excel;

/**
 * LEER EL ARCHIVO QUE EL TENDERO TIENE, NO EL QUE NOS GUSTARÍA QUE TUVIERA.
 *
 * El importador leía con `maatwebsite/excel`, que por debajo es PhpSpreadsheet,
 * y eso cubre .xlsx, .csv y el .xls moderno. Lo que no cubre es el .xls que
 * exportan los puntos de venta viejos que hay en las tiendas: BIFF2, el
 * formato de Excel 2.0, de 1987. PhpSpreadsheet ni siquiera lo reconoce —
 * `IOFactory::identify()` responde «Unable to identify a reader for this file»—
 * así que la carga moría con «No se pudo leer el archivo» y el tendero no
 * tenía forma de saber por qué: su archivo se llama .xls y Excel lo abre.
 *
 * El inventario de Mercadito Búho, 1.010 productos, es exactamente ese
 * formato. No es un caso raro: es el que sale del software de facturación que
 * usan las tiendas de barrio.
 *
 * BIFF2 ES SENCILLO y por eso se lee acá en vez de añadir una dependencia: una
 * cadena de registros, cada uno con tipo, largo y cuerpo. De los veintitantos
 * tipos que existen solo hacen falta tres —texto, número entero y número
 * decimal— porque una hoja de inventario no tiene nada más. Los demás se
 * saltan por su largo, que es justo para lo que está ese campo.
 *
 * Si el archivo no es BIFF2 se delega en la librería, que es lo correcto para
 * los formatos que sí sabe leer.
 */
class LectorDeArchivoDeCatalogo
{
    /** Primer registro de un archivo BIFF2: BOF con cuerpo de 4 bytes. */
    private const BIFF2_BOF = "\x09\x00\x04\x00";

    private const REGISTRO_ENTERO = 0x02;
    private const REGISTRO_DECIMAL = 0x03;
    private const REGISTRO_TEXTO = 0x04;

    /**
     * Devuelve las filas como arreglos asociativos, con los encabezados ya
     * normalizados a minúsculas y guion bajo —igual que hace `WithHeadingRow`—
     * para que al importador le dé lo mismo de dónde salieron.
     */
    public function leer(string $ruta): array
    {
        if ($this->esBiff2($ruta)) {
            return $this->leerBiff2($ruta);
        }

        $lector = new FilasDeCatalogo();
        Excel::import($lector, $ruta);

        return $lector->filas->map(fn ($f) => is_array($f) ? $f : $f->toArray())->all();
    }

    public function esBiff2(string $ruta): bool
    {
        $f = @fopen($ruta, 'rb');

        if (!$f) {
            return false;
        }

        $cabecera = fread($f, 4);
        fclose($f);

        return $cabecera === self::BIFF2_BOF;
    }

    private function leerBiff2(string $ruta): array
    {
        $datos = file_get_contents($ruta);

        if ($datos === false) {
            return [];
        }

        $celdas = [];
        $maxFila = -1;
        $maxCol = -1;
        $pos = 0;
        $largoTotal = strlen($datos);

        while ($pos + 4 <= $largoTotal) {
            $cabecera = unpack('vtipo/vlargo', substr($datos, $pos, 4));
            $cuerpo = substr($datos, $pos + 4, $cabecera['largo']);
            $pos += 4 + $cabecera['largo'];

            /*
             * Fila, columna y tres bytes de formato: la misma cabecera en los
             * tres tipos de celda. Menos de siete bytes no es una celda.
             */
            if (strlen($cuerpo) < 7) {
                continue;
            }

            $valor = null;

            switch ($cabecera['tipo']) {
                case self::REGISTRO_TEXTO:
                    $largoTexto = ord($cuerpo[7]);
                    $texto = substr($cuerpo, 8, $largoTexto);
                    /*
                     * BIFF2 es anterior a Unicode: el texto viene en la página
                     * de códigos de Windows. Sin convertirlo, la Ñ de «PAÑAL»
                     * llega rota y acaba guardada así en el catálogo.
                     */
                    $valor = mb_convert_encoding($texto, 'UTF-8', 'CP1252');
                    break;

                case self::REGISTRO_DECIMAL:
                    $d = unpack('d', substr($cuerpo, 7, 8));
                    $valor = $d[1] ?? null;
                    break;

                case self::REGISTRO_ENTERO:
                    $e = unpack('v', substr($cuerpo, 7, 2));
                    $valor = $e[1] ?? null;
                    break;

                default:
                    continue 2;
            }

            $rc = unpack('vfila/vcol', substr($cuerpo, 0, 4));
            $celdas[$rc['fila']][$rc['col']] = $valor;
            $maxFila = max($maxFila, $rc['fila']);
            $maxCol = max($maxCol, $rc['col']);
        }

        if ($maxFila < 1) {
            return [];
        }

        $encabezados = [];
        for ($c = 0; $c <= $maxCol; $c++) {
            $encabezados[$c] = $this->comoLlave($celdas[0][$c] ?? '');
        }

        $filas = [];
        for ($f = 1; $f <= $maxFila; $f++) {
            $fila = [];
            $tieneAlgo = false;

            for ($c = 0; $c <= $maxCol; $c++) {
                $llave = $encabezados[$c];

                if ($llave === '') {
                    continue;
                }

                $v = $celdas[$f][$c] ?? null;
                $fila[$llave] = $v;

                if ($v !== null && $v !== '') {
                    $tieneAlgo = true;
                }
            }

            // Una fila vacía no es un error: es el final de la hoja.
            if ($tieneAlgo) {
                $filas[] = $fila;
            }
        }

        return $filas;
    }

    /** `Venta 1` y `VENTA_1` son la misma columna. */
    private function comoLlave($texto): string
    {
        $t = mb_strtolower(trim((string) $texto), 'UTF-8');
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t) ?: $t;
        $t = preg_replace('/[^a-z0-9]+/', '_', $t);

        return trim((string) $t, '_');
    }
}
