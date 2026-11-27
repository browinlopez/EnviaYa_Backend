<?php

declare(strict_types=1);

namespace App\Exports\Inversionista\Hojas;

/** Ventas, ingreso de la plataforma y su composición. */
class HojaIngresos extends HojaDelInforme
{
    public function title(): string
    {
        return 'Ingresos';
    }

    protected function encabezado(): string
    {
        return 'Ingresos';
    }

    protected function columnas(): array
    {
        return ['A' => 34, 'B' => 18, 'C' => 18, 'D' => 14];
    }

    protected function cuerpo(): void
    {
        $i = $this->d['ingresos'];
        $c = $i['cifras'];

        $this->seccion('Las cifras');
        $this->cifras([
            ['Ventas totales', $c['ventas'], self::PESOS],
            ['Comisiones de los negocios', $c['comisiones'], self::PESOS],
            ['Margen de los domicilios', $c['margen_domicilio'], self::PESOS],
            ['Ingreso de la plataforma', $c['ingreso'], self::PESOS],
            ['Comisión efectiva (de cada $100)', $c['take_rate'], self::PORCENTAJE],
            ['Subsidios al domicilio', $c['subsidios'], self::PESOS],
        ]);
        $this->fila();

        $this->seccion('Por tipo de negocio');
        $this->tabla(
            ['Tipo', 'Ventas', 'Ingreso', 'Comisión efectiva'],
            array_map(fn ($t) => [$t['nombre'], $t['ventas'], $t['ingreso'], $this->pct($t['take_rate'])], $i['por_tipo']),
            [1 => self::PESOS, 2 => self::PESOS, 3 => self::PORCENTAJE],
        );
        $this->fila();
        $this->nota('Ingreso = comisiones (platform_fee) + margen de los domicilios (lo que pagó el comprador por el domicilio menos lo que se lleva el domiciliario). Comisión efectiva = ingreso ÷ ventas. Los subsidios ya están descontados del margen del domicilio: se informan aparte, no se restan dos veces.');
    }
}
