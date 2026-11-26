<?php

declare(strict_types=1);

namespace App\Exports\Conjunto\Hojas;

use App\Exports\Conjunto\CifrasDelReporte;
use Carbon\CarbonImmutable;

/**
 * Qué hizo cada celador en el periodo, la misma tarjeta «Por celador» de la
 * pantalla: entradas que anotó, la parte de domiciliarios que pasó con QR,
 * salidas que marcó y cuándo hizo algo por última vez.
 *
 * El nombre del celador lo escribió el administrador: pasa por el binder sin
 * fórmulas como todo lo demás (`HojaDelReporte` hereda de `SinFormulas`).
 */
class HojaCeladores extends HojaDelReporte
{
    public const TITULO = 'Celadores';

    public function title(): string
    {
        return self::TITULO;
    }

    protected function encabezado(): string
    {
        return 'Por celador';
    }

    protected function colorDePestana(): string
    {
        return '2F855A';
    }

    protected function columnas(): array
    {
        return ['A' => 9, 'B' => 30, 'C' => 13, 'D' => 11, 'E' => 12, 'F' => 13, 'G' => 22];
    }

    protected function cuerpo(): void
    {
        $filas = [];

        foreach ($this->c->celadores as $i => $c) {
            $ultima = $c['ultima_actividad']
                ? CifrasDelReporte::fecha(CarbonImmutable::parse($c['ultima_actividad']))
                : 'Nunca';

            $filas[] = [
                $i + 1,
                $c['nombre'],
                $c['activo'] ? 'Activo' : 'Desactivado',
                $c['entradas'],
                $c['por_qr'] ?? '—',
                $c['salidas'],
                $ultima,
            ];
        }

        if ($filas === []) {
            $filas[] = [null, 'El conjunto no tiene celadores', null, 0, '—', 0, null];
        }

        $this->tabla(
            ['Puesto', 'Celador', 'Estado', 'Entradas', '% por QR', 'Salidas', 'Última actividad'],
            $filas,
            null,
            [0 => '0', 3 => self::ENTERO, 4 => self::PORCENTAJE, 5 => self::ENTERO],
        );

        $this->fila();
        $this->nota('Entradas y salidas que cada celador registró en el periodo. «% por QR» es la parte de los domiciliarios de VeciPa\'Ya que dejó pasar con el código de la app y no con la cédula. «Última actividad» es la última entrada o salida que anotó, aunque sea de antes del periodo.');
    }
}
