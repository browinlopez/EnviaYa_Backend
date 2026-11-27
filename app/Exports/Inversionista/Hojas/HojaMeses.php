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
        return ['A' => 12, 'B' => 11, 'C' => 12, 'D' => 12, 'E' => 13, 'F' => 12, 'G' => 12, 'H' => 12];
    }

    protected function cuerpo(): void
    {
        $usuarios = collect($this->d['usuarios']['meses'])->keyBy('mes');
        $operacion = collect($this->d['operacion']['meses'])->keyBy('mes');

        $filas = array_map(function ($m) use ($usuarios, $operacion) {
            $u = $usuarios[$m['mes']] ?? [];
            $o = $operacion[$m['mes']] ?? [];

            return [
                $m['etiqueta'].' '.$m['anio'].($m['en_curso'] ? ' *' : ''),
                $m['pedidos'],
                $m['negocios_nuevos'],
                $m['usuarios_nuevos'],
                $u['activos'] ?? 0,
                $o['minutos_en_camino'] ?? null,
                $this->pct($o['a_tiempo'] ?? null),
                $this->pct($o['porcentaje_cancelados'] ?? null),
            ];
        }, $this->d['resumen']['meses']);

        $this->seccion('Mes a mes');
        $this->tabla(
            ['Mes', 'Pedidos', 'Negocios nuevos', 'Usuarios nuevos', 'Compradores activos', 'Minutos en camino', 'A tiempo', 'Cancelados'],
            $filas,
            [1 => self::ENTERO, 2 => self::ENTERO, 3 => self::ENTERO, 4 => self::ENTERO, 5 => self::DECIMAL, 6 => self::PORCENTAJE, 7 => self::PORCENTAJE],
        );
        $this->fila();
        $this->nota('* Mes en curso: todavía no termina, así que no se compara entero contra un mes cerrado. La serie no depende del periodo elegido, solo de dónde termina.');
    }
}
