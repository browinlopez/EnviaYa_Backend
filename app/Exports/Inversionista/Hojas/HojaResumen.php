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
            ['Usuarios activos (con un pedido en el periodo)', $c['usuarios_activos'], self::ENTERO],
        ]);
        $this->fila();

        $this->seccion('Negocios por tipo');
        $this->tabla(
            ['Tipo', 'Negocios activos', '% de los negocios', 'Pedidos', '% de los pedidos'],
            array_map(fn ($t) => [
                $t['nombre'], $t['negocios'], $this->pct($t['porcentaje']), $t['pedidos'], $this->pct($t['participacion_pedidos']),
            ], $this->d['resumen']['por_tipo']),
            [1 => self::ENTERO, 2 => self::PORCENTAJE, 3 => self::ENTERO, 4 => self::PORCENTAJE],
        );
        $this->fila();
        $this->nota('Pedido = entregado y con el pago confirmado; ni los cancelados ni los que esperan un pago en línea. Negocio activo = visible en la app. Usuario registrado = cuenta de comprador. El periodo anterior es uno de igual número de días, justo antes.');
    }
}
