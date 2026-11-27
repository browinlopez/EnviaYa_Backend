<?php

declare(strict_types=1);

namespace App\Exports\Inversionista\Hojas;

/** Los doce meses que terminan en el del periodo, con las series de cada módulo. */
class HojaMeses extends HojaDelInforme
{
    public function title(): string
    {
        return 'Mes a mes';
    }

    protected function encabezado(): string
    {
        return 'Los últimos doce meses';
    }

    protected function columnas(): array
    {
        return ['A' => 12, 'B' => 11, 'C' => 16, 'D' => 12, 'E' => 12, 'F' => 13, 'G' => 16, 'H' => 12, 'I' => 12, 'J' => 12, 'K' => 12];
    }

    protected function cuerpo(): void
    {
        $usuarios = collect($this->d['usuarios']['meses'])->keyBy('mes');
        $operacion = collect($this->d['operacion']['meses'])->keyBy('mes');
        $ingresos = collect($this->d['ingresos']['meses'])->keyBy('mes');

        $filas = array_map(function ($m) use ($usuarios, $operacion, $ingresos) {
            $u = $usuarios[$m['mes']] ?? [];
            $o = $operacion[$m['mes']] ?? [];
            $i = $ingresos[$m['mes']] ?? [];

            return [
                $m['etiqueta'].' '.$m['anio'].($m['en_curso'] ? ' *' : ''),
                $m['pedidos'],
                $m['ventas'],
                $m['negocios_nuevos'],
                $m['usuarios_nuevos'],
                $u['activos'] ?? 0,
                $i['ingreso'] ?? 0,
                $this->pct($i['take_rate'] ?? null),
                $o['minutos_en_camino'] ?? null,
                $this->pct($o['a_tiempo'] ?? null),
                $this->pct($o['porcentaje_cancelados'] ?? null),
            ];
        }, $this->d['resumen']['meses']);

        $this->seccion('Mes a mes');
        $this->tabla(
            ['Mes', 'Pedidos', 'Ventas', 'Negocios nuevos', 'Usuarios nuevos', 'Compradores activos', 'Ingreso plataforma', 'Comisión efectiva', 'Minutos en camino', 'A tiempo', 'Cancelados'],
            $filas,
            [1 => self::ENTERO, 2 => self::PESOS, 3 => self::ENTERO, 4 => self::ENTERO, 5 => self::ENTERO, 6 => self::PESOS, 7 => self::PORCENTAJE, 8 => self::DECIMAL, 9 => self::PORCENTAJE, 10 => self::PORCENTAJE],
        );
        $this->fila();
        $this->nota('* Mes en curso: todavía no termina, así que no se compara entero contra un mes cerrado. La serie no depende del periodo elegido, solo de dónde termina.');
    }
}
