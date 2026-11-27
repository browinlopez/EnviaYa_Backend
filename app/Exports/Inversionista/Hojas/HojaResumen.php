<?php

declare(strict_types=1);

namespace App\Exports\Inversionista\Hojas;

/** La portada: las cifras clave contra el periodo anterior y el reparto por tipo. */
class HojaResumen extends HojaDelInforme
{
    public function title(): string
    {
        return 'Resumen';
    }

    protected function encabezado(): string
    {
        return 'Resumen del periodo';
    }

    protected function columnas(): array
    {
        return ['A' => 34, 'B' => 18, 'C' => 18, 'D' => 14, 'E' => 16, 'F' => 16];
    }

    protected function cuerpo(): void
    {
        $c = $this->d['resumen']['cifras'];

        $this->seccion('Las cifras clave');
        $this->cifras([
            ['Negocios activos', $c['negocios_activos'], self::ENTERO],
            ['Usuarios registrados', $c['usuarios_registrados'], self::ENTERO],
            ['Pedidos realizados', $c['pedidos'], self::ENTERO],
            ['Ventas', $c['ventas'], self::PESOS],
            ['Ticket promedio', $c['ticket_promedio'], self::PESOS],
            ['Ingreso de la plataforma', $this->d['ingresos']['cifras']['ingreso'], self::PESOS],
        ]);
        $this->fila();

        $this->seccion('Negocios por tipo');
        $this->tabla(
            ['Tipo', 'Negocios activos', '% de los negocios', 'Pedidos', 'Ventas', '% de las ventas'],
            array_map(fn ($t) => [
                $t['nombre'], $t['negocios'], $this->pct($t['porcentaje']), $t['pedidos'], $t['ventas'], $this->pct($t['participacion_ventas']),
            ], $this->d['resumen']['por_tipo']),
            [1 => self::ENTERO, 2 => self::PORCENTAJE, 3 => self::ENTERO, 4 => self::PESOS, 5 => self::PORCENTAJE],
        );
        $this->fila();
        $this->nota('Pedido = entregado y con el pago confirmado; ni los cancelados ni los que esperan un pago en línea. Negocio activo = visible en la app. Usuario registrado = cuenta de comprador. El periodo anterior es uno de igual número de días, justo antes.');
    }
}
