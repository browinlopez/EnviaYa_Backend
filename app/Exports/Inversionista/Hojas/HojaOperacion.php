<?php

declare(strict_types=1);

namespace App\Exports\Inversionista\Hojas;

/** Tiempos, puntualidad, cancelación y flota. */
class HojaOperacion extends HojaDelInforme
{
    public function title(): string
    {
        return 'Operación';
    }

    protected function encabezado(): string
    {
        return 'Operación';
    }

    protected function columnas(): array
    {
        return ['A' => 36, 'B' => 18, 'C' => 18, 'D' => 14];
    }

    protected function cuerpo(): void
    {
        $o = $this->d['operacion'];
        $c = $o['cifras'];

        $this->seccion('Las cifras');
        $this->cifras([
            ['Minutos en camino (promedio)', $c['minutos_en_camino'], self::DECIMAL],
            ['Minutos de punta a punta (promedio)', $c['minutos_totales'], self::DECIMAL],
            ['Entregas a tiempo', $c['a_tiempo'], self::PORCENTAJE],
            ['Pedidos cancelados', $c['porcentaje_cancelados'], self::PORCENTAJE],
            ['Domiciliarios activos', $c['domiciliarios_activos'], self::ENTERO],
            ['Pedidos por domiciliario', $c['pedidos_por_domiciliario'], self::DECIMAL],
        ]);
        $this->fila();

        $this->seccion('La flota hoy');
        $this->tabla(['Indicador', 'Valor'], [
            ['Domiciliarios habilitados', $o['flota']['habilitados']],
            ['Disponibles ahora', $o['flota']['disponibles']],
        ], [1 => self::ENTERO]);
        $this->fila();
        $this->nota('En camino = de la salida del negocio a la entrega. Punta a punta = de que se hace el pedido a la entrega, sin los programados. Solo domicilios con las dos marcas y menos de 24 horas. A tiempo = dentro del plazo prometido, sobre las entregas que se pueden juzgar. Cancelados = sobre todos los pedidos hechos en el periodo.');
    }
}
