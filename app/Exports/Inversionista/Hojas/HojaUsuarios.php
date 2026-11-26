<?php

declare(strict_types=1);

namespace App\Exports\Inversionista\Hojas;

/** Registrados, activos, recompra y frecuencia. */
class HojaUsuarios extends HojaDelInforme
{
    public function title(): string
    {
        return 'Usuarios';
    }

    protected function encabezado(): string
    {
        return 'Usuarios';
    }

    protected function columnas(): array
    {
        return ['A' => 34, 'B' => 18, 'C' => 18, 'D' => 14];
    }

    protected function cuerpo(): void
    {
        $u = $this->d['usuarios'];
        $c = $u['cifras'];

        $this->seccion('Las cifras');
        $this->cifras([
            ['Usuarios registrados', $c['registrados'], self::ENTERO],
            ['Usuarios nuevos', $c['nuevos'], self::ENTERO],
            ['Compradores activos', $c['activos'], self::ENTERO],
            ['Recompra (2 o más pedidos)', $c['recompra'], self::PORCENTAJE],
            ['Pedidos por comprador activo', $c['pedidos_por_comprador'], self::DECIMAL],
        ]);
        $this->fila();

        $this->seccion('Viven en un conjunto');
        $this->tabla(
            ['Indicador', 'Compradores', '% de los registrados'],
            [['Vinculados a un conjunto activo', $c['en_conjuntos']['valor'], $this->pct($c['en_conjuntos']['porcentaje'])]],
            [1 => self::ENTERO, 2 => self::PORCENTAJE],
        );
        $this->fila();

        $this->seccion('Cuántos pedidos hizo cada comprador activo');
        $this->tabla(
            ['Pedidos en el periodo', 'Compradores', '% de los activos'],
            array_map(fn ($t) => [$t['rotulo'], $t['compradores'], $this->pct($t['porcentaje'])], $u['frecuencia']),
            [1 => self::ENTERO, 2 => self::PORCENTAJE],
        );
        $this->fila();
        $this->nota('Activo = comprador con al menos un pedido entregado y pagado en el periodo. Recompra = de los activos, los que hicieron dos o más pedidos.');
    }
}
