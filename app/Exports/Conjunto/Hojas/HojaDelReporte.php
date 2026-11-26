<?php

declare(strict_types=1);

namespace App\Exports\Conjunto\Hojas;

use App\Exports\Compartido\SinFormulas;
use App\Exports\Conjunto\CifrasDelReporte;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lo que tienen en común las hojas del reporte: la cabecera de marca, el
 * título, las tablas con su estilo y la hoja lista para imprimir.
 *
 * Cada hoja sólo dice QUÉ filas lleva (`cuerpo()`); dónde caen y cómo se
 * visten lo resuelve esta clase. Así las cinco hojas se ven iguales sin que
 * cada una repita cuarenta líneas de estilos, y la que se agregue mañana
 * sale con el mismo traje y —lo que importa— con el mismo binder sin fórmulas.
 *
 * La disposición es fija y las gráficas del Consolidado cuentan con ella:
 *
 *   1  franja de marca
 *   2  título
 *   3  conjunto y periodo
 *   4  cuándo se generó · sin nombres de residentes
 *   5  (en blanco)
 *   6  cabecera de la tabla           ← FILA_CABECERA
 *   7  primera fila de datos          ← PRIMERA_FILA
 *
 * Las cifras se escriben como VALORES, no como fórmulas: son las mismas que
 * devuelve el API, ya calculadas, y un total que Excel recalcula al abrir es
 * un total que se puede descuadrar con la pantalla si alguien borra una fila.
 */
abstract class HojaDelReporte extends SinFormulas implements FromArray, WithEvents, WithStrictNullComparison, WithTitle
{
    public const MARCA = '1B1464';

    public const CORAL = 'FF6B5B';

    public const AMBAR = 'F5A524';

    public const GRIS = 'B8B6C9';

    public const FILA_CABECERA = 6;

    public const PRIMERA_FILA = 7;

    /* Formatos de número. Excel pone el separador de miles del equipo que
       abre el archivo: en un equipo en español, «1.000». */
    public const ENTERO = '#,##0';

    public const DECIMAL = '#,##0.0';

    public const PORCENTAJE = '0%';

    public const PORCENTAJE_1 = '0.0%';

    public const CAMBIO = '+0%;-0%;0%';

    /** Fecha real (se ordena y se filtra) pintada en español, sea cual sea el idioma del equipo. */
    public const FECHA = '[$-240A]d mmm yyyy';

    private ?array $filas = null;

    /** @var list<array{cabecera: int, desde: int, hasta: int, total: ?int, columnas: int, filtro: bool, derecha: list<int>}> */
    private array $tablas = [];

    /** @var list<array{0: string, 1: string}> */
    private array $formatos = [];

    /** @var list<int> */
    private array $resaltadas = [];

    /** @var list<int> */
    private array $secciones = [];

    /** @var list<int> */
    private array $notas = [];

    /** @var list<string> */
    private array $fusiones = [];

    public function __construct(protected CifrasDelReporte $c) {}

    /** Lo que dice la fila 2. */
    abstract protected function encabezado(): string;

    /** Ancho de cada columna. La última llave es la última columna de la hoja. */
    abstract protected function columnas(): array;

    abstract protected function colorDePestana(): string;

    /** Las filas desde la 6. */
    abstract protected function cuerpo(): void;

    protected function subtitulo(): string
    {
        return $this->c->conjunto.' · '.$this->c->periodo();
    }

    protected function apaisada(): bool
    {
        return false;
    }

    /** Hasta dónde queda quieto al bajar: la cabecera de la tabla. */
    protected function filaCongelada(): ?int
    {
        return self::PRIMERA_FILA;
    }

    /** Lo que se repite arriba en cada página impresa. */
    protected function filaQueSeRepite(): ?int
    {
        return self::FILA_CABECERA;
    }

    /** Para lo propio de cada hoja (una columna oculta, una fusión). */
    protected function alTerminar(Worksheet $hoja): void {}

    public function array(): array
    {
        if ($this->filas === null) {
            $this->filas = [
                ["VeciPa'Ya · Reporte de la portería"],
                [$this->encabezado()],
                [$this->subtitulo()],
                [$this->c->generadoEl().' · Sin nombres de residentes'],
                [null],
            ];
            $this->cuerpo();
        }

        return $this->filas;
    }

    /* ----------------------------- ARMAR FILAS ----------------------------- */

    protected function fila(array $valores = []): int
    {
        /* Una fila en blanco va como `[null]` y no como `[]`: Maatwebsite
           aplana las filas y una vacía desaparece, corriendo una fila todo lo
           de abajo —y con ello los rangos de las gráficas—. */
        $this->filas[] = $valores === [] ? [null] : $valores;

        return count($this->filas);
    }

    protected function seccion(string $titulo): int
    {
        return $this->secciones[] = $this->fila([$titulo]);
    }

    protected function nota(string $texto): int
    {
        return $this->notas[] = $this->fila([$texto]);
    }

    protected function resaltar(int $fila): void
    {
        $this->resaltadas[] = $fila;
    }

    protected function fusionar(string $rango): void
    {
        $this->fusiones[] = $rango;
    }

    /** Un formato de número para un rango puntual, además de los de cada tabla. */
    protected function formato(string $rango, string $formato): void
    {
        $this->formatos[] = [$rango, $formato];
    }

    /**
     * Hasta qué columna llegan las secciones y las notas. Por defecto toda la
     * hoja; el Consolidado lo acota para no subrayar debajo de las gráficas.
     */
    protected function columnaDeTexto(): string
    {
        return array_key_last($this->columnas());
    }

    /**
     * Una tabla: cabecera, filas y, si se da, la fila de totales.
     *
     * @param  array<int, string>  $formatos  índice de columna (desde 0) => formato de número
     * @return array{cabecera: int, desde: int, hasta: int, total: ?int}
     */
    protected function tabla(array $cabecera, array $cuerpo, ?array $total = null, array $formatos = [], bool $filtro = true): array
    {
        $cab = $this->fila($cabecera);

        foreach ($cuerpo as $f) {
            $this->fila($f);
        }

        $hasta = count($this->filas);
        $tot = $total === null ? null : $this->fila($total);

        foreach ($formatos as $i => $formato) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $this->formatos[] = [$col.($cab + 1).':'.$col.max($cab + 1, $tot ?? $hasta), $formato];
        }

        $this->tablas[] = [
            'cabecera' => $cab,
            'desde' => $cab + 1,
            'hasta' => $hasta,
            'total' => $tot,
            'columnas' => count($cabecera),
            'filtro' => $filtro,
            'derecha' => array_keys($formatos),
        ];

        return ['cabecera' => $cab, 'desde' => $cab + 1, 'hasta' => $hasta, 'total' => $tot];
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
        $hoja->getTabColor()->setRGB($this->colorDePestana());

        // La franja de marca.
        $hoja->mergeCells("A1:{$ultima}1");
        $hoja->getStyle("A1:{$ultima}1")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::MARCA]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
        $hoja->getRowDimension(1)->setRowHeight(24);

        $hoja->getStyle('A2')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::MARCA);
        $hoja->getRowDimension(2)->setRowHeight(26);
        $hoja->getStyle('A3')->getFont()->setSize(11)->getColor()->setRGB('4A4867');
        $hoja->getStyle('A4')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('8A88A3');

        $texto = $this->columnaDeTexto();

        foreach ($this->secciones as $f) {
            $hoja->getStyle("A{$f}:{$texto}{$f}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => self::MARCA]],
                'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => self::MARCA]]],
            ]);
            $hoja->getRowDimension($f)->setRowHeight(20);
        }

        $conFiltro = false;

        foreach ($this->tablas as $t) {
            $fin = Coordinate::stringFromColumnIndex($t['columnas']);

            $hoja->getStyle("A{$t['cabecera']}:{$fin}{$t['cabecera']}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::MARCA]],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'wrapText' => true,
                ],
            ]);
            $hoja->getRowDimension($t['cabecera'])->setRowHeight(30);

            $abajo = $t['total'] ?? $t['hasta'];

            if ($abajo > $t['cabecera']) {
                $hoja->getStyle("A{$t['desde']}:{$fin}{$abajo}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'DCDAEA']]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);

                foreach ($t['derecha'] as $i) {
                    $col = Coordinate::stringFromColumnIndex($i + 1);
                    $hoja->getStyle("{$col}{$t['desde']}:{$col}{$abajo}")
                        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
            }

            // Cebra: se sigue la fila de un lado a otro sin regla.
            for ($f = $t['desde'] + 1; $f <= $t['hasta']; $f += 2) {
                $hoja->getStyle("A{$f}:{$fin}{$f}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F5F4FB');
            }

            if ($t['total'] !== null) {
                $hoja->getStyle("A{$t['total']}:{$fin}{$t['total']}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => self::MARCA]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E9E7F5']],
                    'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => self::MARCA]]],
                ]);
            }

            // Un solo autofiltro por hoja: el de la tabla de detalle.
            if ($t['filtro'] && ! $conFiltro && $t['hasta'] >= $t['desde']) {
                $hoja->setAutoFilter("A{$t['cabecera']}:{$fin}{$t['hasta']}");
                $conFiltro = true;
            }
        }

        foreach ($this->resaltadas as $f) {
            $hoja->getStyle("A{$f}:{$texto}{$f}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => '9A2A1E']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFE6E2']],
            ]);
        }

        foreach ($this->notas as $f) {
            $hoja->mergeCells("A{$f}:{$texto}{$f}");
            $hoja->getStyle("A{$f}")->applyFromArray([
                'font' => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '6E6C88']],
                'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP],
            ]);
            $hoja->getRowDimension($f)->setRowHeight(28);
        }

        foreach ($this->formatos as [$rango, $formato]) {
            $hoja->getStyle($rango)->getNumberFormat()->setFormatCode($formato);
        }

        foreach ($this->fusiones as $rango) {
            $hoja->mergeCells($rango);
        }

        if ($fila = $this->filaCongelada()) {
            $hoja->freezePane('A'.$fila);
        }

        $this->paraImprimir($hoja);
        $this->alTerminar($hoja);
    }

    private function paraImprimir(Worksheet $hoja): void
    {
        $pagina = $hoja->getPageSetup();
        $pagina->setOrientation($this->apaisada() ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT);
        // Carta: es el papel de las impresoras de oficina en Colombia.
        $pagina->setPaperSize(PageSetup::PAPERSIZE_LETTER);
        $pagina->setFitToWidth(1);
        $pagina->setFitToHeight(0);
        $pagina->setHorizontalCentered(true);

        if ($fila = $this->filaQueSeRepite()) {
            $pagina->setRowsToRepeatAtTopByStartAndEnd($fila, $fila);
        }

        $hoja->getPageMargins()->setTop(0.6)->setBottom(0.6)->setLeft(0.4)->setRight(0.4)
            ->setHeader(0.3)->setFooter(0.3);

        // En los códigos de encabezado «&» es una orden: el texto ajeno lo duplica.
        $texto = fn (string $t) => str_replace('&', '&&', $t);

        $hoja->getHeaderFooter()
            ->setOddHeader('&L&B'.$texto($this->c->conjunto).'&B&R&9'.$texto($this->title().' · '.$this->c->periodo()))
            ->setOddFooter('&L&8'.$texto($this->c->generadoEl()).' · Sin nombres de residentes&R&8Página &P de &N');
    }
}
