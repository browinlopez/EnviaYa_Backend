<?php

declare(strict_types=1);

namespace App\Exports\Conjunto\Hojas;

use App\Exports\Conjunto\CifrasDelReporte;

/**
 * Los domiciliarios de VeciPa'Ya que más entraron, los mismos que enseña la
 * pantalla. El nombre lo escribió cada domiciliario: pasa por el binder sin
 * fórmulas.
 */
class HojaDomiciliarios extends HojaDelReporte
{
    public const TITULO = 'Domiciliarios';

    public function title(): string
    {
        return self::TITULO;
    }

    protected function encabezado(): string
    {
        return 'Domiciliarios que más entran';
    }

    protected function colorDePestana(): string
    {
        return 'B7791F';
    }

    protected function columnas(): array
    {
        return ['A' => 9, 'B' => 34, 'C' => 11, 'D' => 12, 'E' => 18];
    }

    /** Las filas, también para el Consolidado: puesto, nombre, entradas, % QR, minutos. */
    public static function filas(CifrasDelReporte $c, ?int $cuantos = null): array
    {
        $filas = [];

        foreach (array_slice($c->domiciliarios, 0, $cuantos) as $i => $d) {
            $filas[] = [$i + 1, $d['nombre'], $d['entradas'], $d['por_qr'], $d['minutos'] ?? '—'];
        }

        return $filas;
    }

    protected function cuerpo(): void
    {
        $filas = self::filas($this->c);

        if ($filas === []) {
            $filas[] = [null, 'Ningún domiciliario entró en el periodo', 0, 0, '—'];
        }

        $this->tabla(
            ['Puesto', 'Domiciliario', 'Entradas', '% por QR', 'Tiempo promedio adentro (min)'],
            $filas,
            null,
            [0 => '0', 2 => self::ENTERO, 3 => self::PORCENTAJE, 4 => self::ENTERO],
        );

        $this->fila();
        $this->nota('Los que más entraron en el periodo, igual que en el panel. «% por QR» es la parte de sus entradas en que mostró el código de la app; el tiempo adentro va de la entrada a la salida que anotó la portería.');
    }
}
