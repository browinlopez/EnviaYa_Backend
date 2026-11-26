<?php

declare(strict_types=1);

namespace App\Exports\Conjunto\Hojas;

use App\Exports\Conjunto\CifrasDelReporte;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Un renglón por día del periodo, también los días sin nadie: sin ellos, un
 * fin de semana muerto no se ve y el promedio parece más alto de lo que es.
 *
 * Junto a las entradas van los PEDIDOS de ese día al conjunto: si la
 * portería sube cuando sube lo que se pide, o si hay movimiento que no viene
 * de pedidos (visitas, otras apps).
 *
 * La columna I —oculta— es la etiqueta corta («28 oct») que usa la gráfica
 * del Consolidado. Va aparte porque el eje de una gráfica no siempre respeta
 * el formato de fecha en español de la columna A.
 */
class HojaEntradasPorDia extends HojaDelReporte
{
    public const TITULO = 'Entradas por día';

    /** Dónde lee la gráfica del Consolidado. */
    public const COLUMNA_ENTRADAS = 'C';

    public const COLUMNA_PEDIDOS = 'D';

    public const COLUMNA_ETIQUETA = 'I';

    private ?int $filaPromedio = null;

    public function title(): string
    {
        return self::TITULO;
    }

    protected function encabezado(): string
    {
        return 'Entradas por día';
    }

    protected function colorDePestana(): string
    {
        return '3D3A8C';
    }

    protected function columnas(): array
    {
        return ['A' => 15, 'B' => 13, 'C' => 11, 'D' => 11, 'E' => 13, 'F' => 15, 'G' => 22, 'H' => 3, 'I' => 10];
    }

    protected function cuerpo(): void
    {
        $c = $this->c;
        $filas = [];
        $pico = null;

        foreach ($c->porDia as $i => $d) {
            $dia = CarbonImmutable::parse($d['dia']);
            $esPico = $c->diaPico !== null && $d['dia'] === $c->diaPico['dia'];

            if ($esPico) {
                $pico = self::PRIMERA_FILA + $i;
            }

            $filas[] = [
                Date::PHPToExcel($dia),
                CifrasDelReporte::diaDeLaSemana($dia),
                $d['entradas'],
                $d['pedidos'],
                $c->entradas ? $d['entradas'] / $c->entradas : 0,
                $d['entradas'] - $c->promedio,
                $esPico ? 'Día más movido' : ($d['entradas'] === 0 ? 'Sin entradas' : null),
                null,
                CifrasDelReporte::fechaCorta($dia),
            ];
        }

        $this->tabla(
            ['Fecha', 'Día', 'Entradas', 'Pedidos', '% del periodo', 'Frente al promedio', 'Nota'],
            $filas,
            ['Total', $c->dias().' días', $c->entradas, $c->pedidosDelPeriodo, $c->entradas ? 1 : 0, null, null],
            [0 => self::FECHA, 2 => self::ENTERO, 3 => self::ENTERO, 4 => self::PORCENTAJE_1, 5 => '+#,##0.0;-#,##0.0;0.0'],
        );

        $this->filaPromedio = $this->fila(['Promedio por día', null, round($c->promedio, 1)]);

        if ($pico) {
            $this->resaltar($pico);
        }

        $this->fila();
        $this->nota('Cuenta todas las entradas que registró la portería: domiciliarios de VeciPa\'Ya, visitas, servicio y domicilios de otras apps. «Pedidos» son los de la app con entrega en el conjunto ese día. «Frente al promedio» es cuántas entradas más (o menos) que un día normal del periodo.');
    }

    protected function alTerminar(Worksheet $hoja): void
    {
        $hoja->getColumnDimension(self::COLUMNA_ETIQUETA)->setVisible(false);

        if ($this->filaPromedio) {
            $f = $this->filaPromedio;
            $hoja->getStyle("A{$f}:G{$f}")->getFont()->setBold(true)->getColor()->setRGB(self::MARCA);
            $hoja->getStyle("C{$f}")->getNumberFormat()->setFormatCode(self::DECIMAL);
            $hoja->getStyle("C{$f}")->getAlignment()->setHorizontal('right');
        }
    }
}
