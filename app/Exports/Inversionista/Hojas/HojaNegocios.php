<?php

declare(strict_types=1);

namespace App\Exports\Inversionista\Hojas;

/** Activos y con ventas, la primera venta y los nuevos de cada mes. */
class HojaNegocios extends HojaDelInforme
{
    public function title(): string
    {
        return 'Negocios';
    }

    protected function encabezado(): string
    {
        return 'Negocios';
    }

    protected function columnas(): array
    {
        return ['A' => 38, 'B' => 18, 'C' => 18, 'D' => 14];
    }

    protected function cuerpo(): void
    {
        $n = $this->d['negocios'];
        $a = $n['actividad'];
        $r = $n['arranque'];

        $this->seccion('Actividad');
        $this->cifras([
            ['Negocios activos', $a['activos'], self::ENTERO],
            ['Con al menos una venta', $a['con_ventas'], self::ENTERO],
            ['Ventas por negocio que vendió', $a['ventas_por_negocio'], self::PESOS],
        ]);
        $this->fila();

        $this->seccion('De la fecha de alta a la primera venta');
        $this->tabla(['Indicador', 'Valor'], [
            ['Promedio de días', $r['promedio_dias']],
            ['Mediana de días', $r['mediana_dias']],
            ['Negocios medidos', $r['medidos']],
            ['Con fecha de alta y sin ventas todavía', $r['en_espera']],
            ['Sin fecha de alta (no se miden)', $r['sin_fecha_de_alta']],
            ...array_map(fn ($t) => ['Primera venta: '.mb_strtolower($t['rotulo']), $t['negocios']], $r['tramos']),
        ], [1 => self::DECIMAL]);
        $this->fila();

        $this->seccion('Nuevos y activados por mes');
        $this->tabla(
            ['Mes', 'Negocios nuevos', 'Primera venta', 'Con ventas'],
            array_map(fn ($m) => [$m['etiqueta'].' '.$m['anio'], $m['negocios_nuevos'], $m['primeras_ventas'], $m['con_ventas']], $n['meses']),
            [1 => self::ENTERO, 2 => self::ENTERO, 3 => self::ENTERO],
        );
        $this->fila();
        $this->nota('Con ventas = al menos un pedido entregado y pagado en el periodo. La fecha de alta existe desde agosto de 2026: los negocios anteriores no entran en el cálculo de días.');
    }
}
