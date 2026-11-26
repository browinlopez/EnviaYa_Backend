<?php

declare(strict_types=1);

namespace App\Exports\Conjunto\Hojas;

use App\Exports\Conjunto\CifrasDelReporte;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\WithCharts;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\GridLines;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * La hoja que imprime el administrador para la asamblea.
 *
 * Arriba, las cifras de la pantalla (las cuatro de la cabecera más el
 * promedio y el día más movido); debajo, tablas cortas con lo más alto de
 * cada detalle; a la derecha, tres gráficas nativas de Excel.
 *
 * Las gráficas LEEN las hojas de detalle —no una copia escondida acá—: si
 * alguien corrige una cifra en «Por torre», la gráfica la sigue. Cada una
 * lleva además los valores ya puestos (la caché de la gráfica), para que se
 * vea completa aunque el programa que abre el archivo no recalcule nada.
 */
class HojaConsolidado extends HojaDelReporte implements WithCharts
{
    public const TITULO = 'Consolidado';

    /** Cuántas filas llevan las tablas cortas del resumen. */
    private const TOPE = 5;

    public function title(): string
    {
        return self::TITULO;
    }

    protected function encabezado(): string
    {
        return $this->c->conjunto;
    }

    protected function subtitulo(): string
    {
        return 'Periodo: '.$this->c->periodo().' · '.$this->c->dias().' días';
    }

    protected function colorDePestana(): string
    {
        return self::MARCA;
    }

    protected function apaisada(): bool
    {
        return true;
    }

    /* Sólo el bloque del título queda quieto: abajo no hay una tabla larga
       cuya cabecera haya que repetir. */
    protected function filaCongelada(): ?int
    {
        return 5;
    }

    protected function filaQueSeRepite(): ?int
    {
        return null;
    }

    protected function columnaDeTexto(): string
    {
        return 'D';
    }

    protected function columnas(): array
    {
        // A–D las cifras; E de respiro; F–N las gráficas.
        return ['A' => 44, 'B' => 15, 'C' => 12, 'D' => 34, 'E' => 3]
            + array_fill_keys(range('F', 'N'), 11);
    }

    protected function cuerpo(): void
    {
        $c = $this->c;

        /* ------------------------------ CIFRAS ------------------------------ */

        $this->seccion('Cifras del periodo');

        $pico = $c->diaPico;
        $diaPico = $pico ? CarbonImmutable::parse($pico['dia']) : null;

        $kpis = [
            ['Entradas a la portería', $c->entradas, 'Todo lo que registró la portería en el periodo', self::ENTERO],
            [
                'Cambio frente al periodo anterior',
                $c->cambio ?? '—',
                $c->cambio === null
                    ? 'El periodo anterior tiene muy pocas entradas para comparar'
                    : 'Contra los '.$c->dias().' días anteriores ('.number_format($c->entradasAnterior, 0, ',', '.').' entradas)',
                self::CAMBIO,
            ],
            ["Pedidos de VeciPa'Ya recibidos por la portería", $c->pedidosRecibidos, 'Los que traían los domiciliarios al entrar', self::ENTERO],
            [
                'Verificados por QR',
                $c->parteQr ?? '—',
                'De todas las entradas ('.number_format($c->porCodigo, 0, ',', '.').' de '.number_format($c->todas, 0, ',', '.').')',
                self::PORCENTAJE,
            ],
            ['Visitantes anotados', $c->visitantes, 'Visitas, servicio y domicilios de otras apps', self::ENTERO],
            ['Promedio de entradas por día', round($c->promedio, 1), 'En los '.$c->dias().' días del periodo', self::DECIMAL],
            [
                'Día más movido',
                $diaPico ? Date::PHPToExcel($diaPico) : '—',
                $diaPico
                    ? ucfirst(CifrasDelReporte::diaDeLaSemana($diaPico)).' · '.number_format($pico['entradas'], 0, ',', '.').' entradas'
                    : 'Nadie entró en este periodo',
                self::FECHA,
            ],
        ];

        $t = $this->tabla(
            ['Indicador', 'Valor', 'Detalle', null],
            array_map(fn ($k) => [$k[0], $k[1], $k[2], null], $kpis),
            null,
            [1 => self::ENTERO],
            false,
        );

        $this->fusionar("C{$t['cabecera']}:D{$t['cabecera']}");
        foreach ($kpis as $i => $k) {
            $f = $t['desde'] + $i;
            $this->formato("B{$f}", $k[3]);
            $this->fusionar("C{$f}:D{$f}");
        }

        /* --------------------------- IDENTIFICACIÓN --------------------------- */

        $this->fila();
        $this->seccion('Cómo se identificaron');
        $this->tabla(
            ['Método', 'Entradas', '%', 'Confianza'],
            HojaIdentificacion::filas($c, false),
            ['Total', $c->todas, $c->todas ? 1 : 0, null],
            [1 => self::ENTERO, 2 => self::PORCENTAJE],
            false,
        );

        /* ------------------------------- TORRES ------------------------------- */

        $this->fila();
        $this->seccion('Torres con más pedidos');

        $torres = [];
        foreach (array_slice($c->torres, 0, self::TOPE) as $i => $torre) {
            $torres[] = [
                $torre['torre'],
                $torre['pedidos'],
                $c->pedidosEnTorres ? $torre['pedidos'] / $c->pedidosEnTorres : 0,
                'Puesto '.($i + 1),
            ];
        }
        if ($torres === []) {
            $torres[] = ['Sin pedidos en el periodo', 0, 0, null];
        }

        $this->tabla(
            ['Torre', 'Pedidos', '%', 'Puesto'],
            $torres,
            null,
            [1 => self::ENTERO, 2 => self::PORCENTAJE],
            false,
        );

        if (count($c->torres) > self::TOPE) {
            $this->nota('Las '.self::TOPE.' de '.count($c->torres).' torres. Todas están en la hoja «Por torre».');
        }

        /* ---------------------------- DOMICILIARIOS ---------------------------- */

        $this->fila();
        $this->seccion('Domiciliarios que más entran');

        $domis = array_map(
            fn ($f) => [$f[1], $f[2], $f[3], is_int($f[4]) ? $f[4].' min adentro' : '—'],
            HojaDomiciliarios::filas($c, self::TOPE),
        );
        if ($domis === []) {
            $domis[] = ['Ningún domiciliario entró en el periodo', 0, 0, '—'];
        }

        $this->tabla(
            ['Domiciliario', 'Entradas', '% por QR', 'Tiempo promedio'],
            $domis,
            null,
            [1 => self::ENTERO, 2 => self::PORCENTAJE],
            false,
        );

        $this->fila();
        $this->nota('Mismos datos y misma consulta que la pantalla de Reportes del panel. Sin nombres de residentes: el reporte dice cuántos pedidos llegaron a cada torre, no quién pidió qué. Las gráficas leen las hojas de detalle.');
    }

    /* ------------------------------ GRÁFICAS ------------------------------ */

    public function charts(): array
    {
        $graficas = [$this->graficaPorDia()];

        if ($this->c->todas > 0) {
            $graficas[] = $this->graficaIdentificacion();
        }

        if ($this->c->torres !== []) {
            $graficas[] = $this->graficaPorTorre();
        }

        return $graficas;
    }

    /** `'Entradas por día'!$C$7:$C$36`. */
    private static function rango(string $hoja, string $columna, int $desde, int $hasta): string
    {
        return "'".str_replace("'", "''", $hoja)."'!\${$columna}\${$desde}:\${$columna}\${$hasta}";
    }

    private function graficaPorDia(): Chart
    {
        $c = $this->c;
        $n = max(1, $c->dias());
        $desde = self::PRIMERA_FILA;
        $hasta = $desde + $n - 1;
        $hoja = HojaEntradasPorDia::TITULO;

        $etiquetas = array_map(fn ($d) => CifrasDelReporte::fechaCorta(CarbonImmutable::parse($d['dia'])), $c->porDia);
        $valores = array_column($c->porDia, 'entradas');

        $serie = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
            self::rango($hoja, HojaEntradasPorDia::COLUMNA_ENTRADAS, $desde, $hasta), self::ENTERO, $n, $valores);
        // El día más movido en coral, como en la pantalla.
        $serie->setFillColor(array_map(
            fn ($d) => $c->diaPico && $d['dia'] === $c->diaPico['dia'] ? self::CORAL : self::MARCA,
            $c->porDia,
        ));

        $grafica = new Chart(
            'entradas_por_dia',
            new Title('Entradas por día'),
            null,
            new PlotArea(null, [$this->serieDeColumnas(
                [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING,
                    self::rango($hoja, HojaEntradasPorDia::COLUMNA_ETIQUETA, $desde, $hasta), null, $n, $etiquetas)],
                $serie,
                self::rango($hoja, HojaEntradasPorDia::COLUMNA_ENTRADAS, self::FILA_CABECERA, self::FILA_CABECERA),
            )]),
            // La etiqueta vive en una columna oculta: sin esto Excel no la pinta.
            false,
            DataSeries::EMPTY_AS_GAP,
            null,
            null,
            null,
            null,
            new GridLines,
        );

        $grafica->setTopLeftPosition('F6');
        $grafica->setBottomRightPosition('N23');

        return $grafica;
    }

    private function graficaIdentificacion(): Chart
    {
        $c = $this->c;
        $hoja = HojaIdentificacion::TITULO;
        $desde = self::PRIMERA_FILA;
        $hasta = $desde + HojaIdentificacion::METODOS - 1;
        $filas = HojaIdentificacion::filas($c, false);

        $valores = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
            self::rango($hoja, 'B', $desde, $hasta), self::ENTERO, HojaIdentificacion::METODOS, array_column($filas, 1));
        $valores->setFillColor([self::MARCA, self::AMBAR, self::GRIS]);

        $serie = new DataSeries(
            DataSeries::TYPE_DOUGHNUTCHART,
            null,
            [0],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, self::rango($hoja, 'B', self::FILA_CABECERA, self::FILA_CABECERA), null, 1, ['Entradas'])],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, self::rango($hoja, 'A', $desde, $hasta), null, HojaIdentificacion::METODOS, array_column($filas, 0))],
            [$valores],
        );

        $etiquetas = new Layout;
        $etiquetas->setShowPercent(true);
        $etiquetas->setShowVal(false);
        $etiquetas->setShowCatName(false);
        $etiquetas->setShowSerName(false);
        $etiquetas->setShowLegendKey(false);

        $grafica = new Chart(
            'identificacion',
            new Title('Cómo se identificaron · '.($c->parteQr === null ? '—' : round($c->parteQr * 100).' % por QR')),
            new Legend(Legend::POSITION_RIGHT, null, false),
            new PlotArea($etiquetas, [$serie]),
            true,
        );

        $grafica->setTopLeftPosition('F25');
        $grafica->setBottomRightPosition('N41');

        return $grafica;
    }

    private function graficaPorTorre(): Chart
    {
        $c = $this->c;
        $hoja = HojaPorTorre::TITULO;
        $n = count($c->torres);
        $desde = self::PRIMERA_FILA;
        $hasta = $desde + $n - 1;

        $serie = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER,
            self::rango($hoja, 'B', $desde, $hasta), self::ENTERO, $n, array_column($c->torres, 'pedidos'));
        // La torre que más recibe en coral, como la barra de la pantalla.
        $serie->setFillColor(array_map(fn ($i) => $i === 0 ? self::CORAL : self::MARCA, array_keys($c->torres)));

        $valores = new Layout;
        $valores->setShowVal(true);

        $grafica = new Chart(
            'pedidos_por_torre',
            new Title('Pedidos por torre'),
            null,
            new PlotArea($valores, [$this->serieDeColumnas(
                [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING,
                    self::rango($hoja, 'A', $desde, $hasta), null, $n, array_column($c->torres, 'torre'))],
                $serie,
                self::rango($hoja, 'B', self::FILA_CABECERA, self::FILA_CABECERA),
            )]),
            true,
            DataSeries::EMPTY_AS_GAP,
            null,
            null,
            null,
            null,
            new GridLines,
        );

        $grafica->setTopLeftPosition('F43');
        $grafica->setBottomRightPosition('N60');

        return $grafica;
    }

    /** Una serie de columnas verticales, agrupadas. */
    private function serieDeColumnas(array $categorias, DataSeriesValues $valores, string $rotulo): DataSeries
    {
        $serie = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            [0],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $rotulo, null, 1)],
            $categorias,
            [$valores],
        );
        $serie->setPlotDirection(DataSeries::DIRECTION_COL);

        return $serie;
    }
}
