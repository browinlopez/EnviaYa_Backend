<?php

declare(strict_types=1);

namespace App\Exports\Inversionista\Hojas;

use App\Exports\Compartido\SinFormulas;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lo común a las hojas del informe del inversionista: el MEMBRETE (la franja
 * de marca con el logo de VeciPa'Ya, el título del documento, el periodo y la
 * fecha en que se generó; al imprimir, el mismo encabezado arriba y, abajo, la
 * página y «Solo cifras agregadas»), las tablas con su estilo y la hoja lista
 * para imprimir.
 *
 * Cada hoja solo dice QUÉ filas lleva (`cuerpo()`); cómo se visten lo
 * resuelve esta clase. Hereda el binder sin fórmulas como todas las de
 * `app/Exports`, y las cifras se escriben como VALORES ya calculados por el
 * servicio: un total que Excel recalcula al abrir se puede descuadrar con la
 * pantalla si alguien borra una fila.
 *
 * Los porcentajes del servicio vienen en puntos (67,3); acá se escriben como
 * fracción (0,673) con formato de porcentaje, que es lo que Excel entiende.
 */
abstract class HojaDelInforme extends SinFormulas implements FromArray, WithEvents, WithStrictNullComparison, WithTitle
{
    public const MARCA = '1B1464';

    public const ENTERO = '#,##0';

    public const DECIMAL = '#,##0.0';

    public const PESOS = '"$"#,##0';

    public const PORCENTAJE = '0.0%';

    public const CAMBIO = '+0.0%;-0.0%;0.0%';

    private ?array $filas = null;

    /** @var list<array{cabecera:int, hasta:int, columnas:int}> */
    private array $tablas = [];

    /** @var list<array{0:string, 1:string}> */
    private array $formatos = [];

    /** @var list<int> */
    private array $secciones = [];

    /** @var list<int> */
    private array $notas = [];

    public function __construct(protected array $d) {}

    /** Lo que dice la fila 2. */
    abstract protected function encabezado(): string;

    /** Ancho de cada columna. La última llave es la última columna. */
    abstract protected function columnas(): array;

    abstract protected function cuerpo(): void;

    public function array(): array
    {
        if ($this->filas === null) {
            $this->filas = [
                ['Informe para inversionistas'],
                [$this->encabezado()],
                [$this->alcance()],
                ['Generado el '.Carbon::parse($this->d['generado'])->locale('es')->isoFormat('D [de] MMMM [de] YYYY, h:mm a').' · Solo cifras agregadas, sin datos de personas'],
                [null],
            ];
            $this->cuerpo();
        }

        return $this->filas;
    }

    /** «Del 1 nov 2026 al 30 nov 2026 · Barranquilla · Tienda». */
    protected function alcance(): string
    {
        $p = $this->d['periodo'];
        $fecha = fn (string $f) => Carbon::parse($f)->locale('es')->isoFormat('D MMM YYYY');
        $partes = ['Del '.$fecha($p['desde']).' al '.$fecha($p['hasta'])];
        $partes[] = $this->d['alcance']['ciudad'] ?? 'Todas las ciudades';
        $partes[] = $this->d['alcance']['tipo'] ?? 'Todos los tipos de negocio';

        return implode(' · ', $partes);
    }

    /* ----------------------------- ARMAR FILAS ----------------------------- */

    protected function fila(array $valores = []): int
    {
        // Una fila en blanco va como `[null]`: Maatwebsite aplana las vacías.
        $this->filas[] = $valores === [] ? [null] : $valores;

        return count($this->filas);
    }

    protected function seccion(string $titulo): void
    {
        $this->secciones[] = $this->fila([$titulo]);
    }

    protected function nota(string $texto): void
    {
        $this->notas[] = $this->fila([$texto]);
    }

    /**
     * Una tabla con cabecera.
     *
     * @param  array<int, string>  $formatos  índice de columna (desde 0) => formato
     */
    protected function tabla(array $cabecera, array $cuerpo, array $formatos = []): void
    {
        $cab = $this->fila($cabecera);
        foreach ($cuerpo as $f) {
            $this->fila($f);
        }
        $hasta = count($this->filas);

        foreach ($formatos as $i => $formato) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $this->formatos[] = [$col.($cab + 1).':'.$col.max($cab + 1, $hasta), $formato];
        }

        $this->tablas[] = ['cabecera' => $cab, 'hasta' => $hasta, 'columnas' => count($cabecera)];
    }

    /**
     * Indicadores contra el periodo anterior: rótulo, actual, anterior y
     * variación, cada uno con su formato.
     *
     * @param  list<array{0:string, 1:?array, 2:string}>  $filas  [rótulo, cifra {valor, anterior, variacion}, formato]
     */
    protected function cifras(array $filas): void
    {
        $cab = $this->fila(['Indicador', 'Este periodo', 'Periodo anterior', 'Variación']);

        foreach ($filas as [$rotulo, $cifra, $formato]) {
            $esPct = $formato === self::PORCENTAJE;
            $valor = fn ($x) => $x === null ? null : ($esPct ? (float) $x / 100 : $x);
            $n = $this->fila([
                $rotulo,
                $valor($cifra['valor'] ?? null),
                $valor($cifra['anterior'] ?? null),
                isset($cifra['variacion']) ? (float) $cifra['variacion'] / 100 : null,
            ]);
            $this->formatos[] = ["B{$n}:C{$n}", $formato];
            $this->formatos[] = ["D{$n}", self::CAMBIO];
        }

        $this->tablas[] = ['cabecera' => $cab, 'hasta' => count($this->filas), 'columnas' => 4];
    }

    /** Un porcentaje del servicio (en puntos) como fracción para Excel. */
    protected function pct(mixed $v): ?float
    {
        return $v === null ? null : (float) $v / 100;
    }

    /* -------------------------------- VESTIR -------------------------------- */

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $e) => $this->vestir($e->sheet->getDelegate()),
        ];
    }

    private function vestir(Worksheet $hoja): void
    {
        $columnas = $this->columnas();
        $ultima = array_key_last($columnas);

        foreach ($columnas as $col => $ancho) {
            $hoja->getColumnDimension($col)->setWidth($ancho);
        }

        $hoja->setShowGridlines(false);
        $hoja->getTabColor()->setRGB(self::MARCA);

        // El membrete: el logo blanco sobre la franja de marca y el nombre del
        // documento a la derecha.
        $hoja->mergeCells("A1:{$ultima}1");
        $hoja->getStyle("A1:{$ultima}1")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::MARCA]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_RIGHT, 'indent' => 1],
        ]);
        $hoja->getRowDimension(1)->setRowHeight(42);
        $this->logo($hoja);
        $hoja->getStyle('A2')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::MARCA);
        $hoja->getRowDimension(2)->setRowHeight(26);
        $hoja->getStyle('A3')->getFont()->setSize(11)->getColor()->setRGB('4A4867');
        $hoja->getStyle('A4')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('8A88A3');

        foreach ($this->secciones as $f) {
            $hoja->getStyle("A{$f}:{$ultima}{$f}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => self::MARCA]],
                'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => self::MARCA]]],
            ]);
            $hoja->getRowDimension($f)->setRowHeight(20);
        }

        foreach ($this->tablas as $t) {
            $fin = Coordinate::stringFromColumnIndex($t['columnas']);

            $hoja->getStyle("A{$t['cabecera']}:{$fin}{$t['cabecera']}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::MARCA]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            ]);
            $hoja->getRowDimension($t['cabecera'])->setRowHeight(28);

            if ($t['hasta'] > $t['cabecera']) {
                $desde = $t['cabecera'] + 1;
                $hoja->getStyle("A{$desde}:{$fin}{$t['hasta']}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'DCDAEA']]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);
                if ($t['columnas'] > 1) {
                    $hoja->getStyle("B{$desde}:{$fin}{$t['hasta']}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
                for ($f = $desde + 1; $f <= $t['hasta']; $f += 2) {
                    $hoja->getStyle("A{$f}:{$fin}{$f}")->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F5F4FB');
                }
            }
        }

        foreach ($this->notas as $f) {
            $hoja->mergeCells("A{$f}:{$ultima}{$f}");
            $hoja->getStyle("A{$f}")->applyFromArray([
                'font' => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '6E6C88']],
                'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP],
            ]);
            $hoja->getRowDimension($f)->setRowHeight(40);
        }

        foreach ($this->formatos as [$rango, $formato]) {
            $hoja->getStyle($rango)->getNumberFormat()->setFormatCode($formato);
        }

        $pagina = $hoja->getPageSetup();
        $pagina->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
        // Carta: es el papel de las impresoras de oficina en Colombia.
        $pagina->setPaperSize(PageSetup::PAPERSIZE_LETTER);
        $pagina->setFitToWidth(1);
        $pagina->setFitToHeight(0);
        // En los códigos de encabezado «&» es una orden: se duplica.
        $texto = fn (string $t) => str_replace('&', '&&', $t);
        $hoja->getHeaderFooter()
            ->setOddHeader('&L&B'.$texto("VeciPa'Ya · Informe para inversionistas").'&B&R&9'.$texto($this->title().' · '.$this->alcance()))
            ->setOddFooter('&L&8Solo cifras agregadas, sin datos de personas&R&8Página &P de &N');
    }

    /** El logo de VeciPa'Ya, en blanco, en la esquina de la franja de marca. */
    private function logo(Worksheet $hoja): void
    {
        $archivo = resource_path('marca/logotipo-blanco.png');
        if (! is_file($archivo)) {
            return;
        }

        $logo = new Drawing;
        $logo->setName("VeciPa'Ya");
        $logo->setDescription("Logo de VeciPa'Ya");
        $logo->setPath($archivo);
        $logo->setHeight(52);
        $logo->setCoordinates('A1');
        $logo->setOffsetX(6);
        $logo->setOffsetY(2);
        $logo->setWorksheet($hoja);
    }
}
