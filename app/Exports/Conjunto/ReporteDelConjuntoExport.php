<?php

namespace App\Exports\Conjunto;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * El reporte del conjunto, en Excel.
 *
 * Recibe los datos YA CALCULADOS por `PulsoDelConjunto` en vez de volver a
 * consultarlos. Es lo que garantiza que el archivo diga exactamente lo mismo
 * que la pantalla desde la que se descargó: con dos consultas separadas basta
 * un `where` distinto para que el Excel traiga otra cosa parecida, y eso no se
 * nota hasta que alguien compara los dos en una reunión.
 *
 * Cuatro hojas, ninguna con nombres de residentes: el reporte dice cuántos
 * pedidos llegaron a la torre 4, no quién pidió qué.
 */
class ReporteDelConjuntoExport implements WithMultipleSheets
{
    public function __construct(
        private array $datos,
        private string $conjunto,
    ) {
    }

    public function sheets(): array
    {
        return [
            new HojaResumen($this->datos, $this->conjunto),
            new HojaPorTorre($this->datos),
            new HojaPorDia($this->datos),
            new HojaDomiciliarios($this->datos),
        ];
    }
}

class HojaResumen implements FromArray, WithTitle
{
    public function __construct(private array $d, private string $conjunto)
    {
    }

    public function array(): array
    {
        $t = $this->d['totales'];
        $p = $this->d['periodo'];
        $i = $this->d['identificacion'];

        return [
            ['Conjunto', $this->conjunto],
            ['Desde', $p['desde']],
            ['Hasta', $p['hasta']],
            ['Días', $p['dias']],
            [],
            ['Indicador', 'Valor'],
            ['Pedidos que llegaron', $t['pedidos']],
            ['Entregados', $t['entregados']],
            ['Cancelados', $t['cancelados']],
            ['Promedio de pedidos por día', $t['promedio_diario']],
            // Se rotula "valor de los pedidos" y no "ingresos": el conjunto no
            // cobra ni recibe nada. Es una medida de cuánto se movió.
            ['Valor de los pedidos', $t['valor_pedidos']],
            [],
            ['Entradas de domiciliarios', $t['entradas']],
            ['Domiciliarios distintos', $t['domiciliarios']],
            ['Identificados con código', $i['codigo']],
            ['Identificados con cédula', $i['cedula']],
        ];
    }

    public function title(): string
    {
        return 'Resumen';
    }
}

class HojaPorTorre implements FromArray, WithTitle
{
    public function __construct(private array $d)
    {
    }

    public function array(): array
    {
        $filas = [['Torre', 'Pedidos', 'Valor']];

        foreach ($this->d['por_torre'] as $t) {
            $filas[] = [$t->torre, (int) $t->pedidos, (float) $t->valor];
        }

        return $filas;
    }

    public function title(): string
    {
        return 'Por torre';
    }
}

class HojaPorDia implements FromArray, WithTitle
{
    public function __construct(private array $d)
    {
    }

    public function array(): array
    {
        $filas = [['Día', 'Pedidos']];

        foreach ($this->d['por_dia'] as $f) {
            $filas[] = [$f->dia, (int) $f->pedidos];
        }

        return $filas;
    }

    public function title(): string
    {
        return 'Por día';
    }
}

class HojaDomiciliarios implements FromArray, WithTitle
{
    public function __construct(private array $d)
    {
    }

    public function array(): array
    {
        $filas = [['Domiciliario', 'Cédula', 'Entradas', 'Pedidos que trajo']];

        foreach ($this->d['domiciliarios'] as $f) {
            $filas[] = [
                $f->nombre ?? 'Sin nombre',
                $f->document ?? '',
                (int) $f->entradas,
                (int) ($f->pedidos ?? 0),
            ];
        }

        return $filas;
    }

    public function title(): string
    {
        return 'Domiciliarios';
    }
}
