<?php

declare(strict_types=1);

namespace App\Exports\Conjunto\Hojas;

use App\Exports\Conjunto\CifrasDelReporte;

/**
 * Con qué se identificó la gente que entró.
 *
 * Sobre TODAS las entradas del periodo, como la dona de la pantalla: el QR lo
 * genera la app del domiciliario y caduca en cinco minutos; la cédula sólo
 * prueba que alguien dijo un número; lo anotado a mano es lo que la portería
 * deja pasar sin que el sistema verifique nada.
 */
class HojaIdentificacion extends HojaDelReporte
{
    public const TITULO = 'Identificación';

    /** QR, cédula y a mano, en este orden, desde PRIMERA_FILA. */
    public const METODOS = 3;

    public function title(): string
    {
        return self::TITULO;
    }

    protected function encabezado(): string
    {
        return 'Cómo se identificaron';
    }

    protected function colorDePestana(): string
    {
        return '2E7D5B';
    }

    protected function columnas(): array
    {
        return ['A' => 22, 'B' => 12, 'C' => 12, 'D' => 20, 'E' => 60];
    }

    /** Las filas, también para el Consolidado. */
    public static function filas(CifrasDelReporte $c, bool $conExplicacion = true): array
    {
        $parte = fn (int $n) => $c->todas ? $n / $c->todas : 0;

        $filas = [
            ['QR de la app', $c->porCodigo, $parte($c->porCodigo), 'Alta',
                'Código que genera la app del domiciliario. Caduca en cinco minutos.'],
            ['Cédula', $c->porCedula, $parte($c->porCedula), 'Menor',
                'Sólo prueba que alguien dijo un número de cédula.'],
            ['Anotado a mano', $c->anotadas, $parte($c->anotadas), 'La anota el celador',
                'Visitas, servicio y domicilios de otras apps. El sistema no verifica nada.'],
        ];

        return $conExplicacion ? $filas : array_map(fn ($f) => array_slice($f, 0, 4), $filas);
    }

    protected function cuerpo(): void
    {
        $c = $this->c;

        $this->tabla(
            ['Método', 'Entradas', '% del total', 'Confianza', 'Qué significa'],
            self::filas($c),
            ['Total', $c->todas, $c->todas ? 1 : 0, null, null],
            [1 => self::ENTERO, 2 => self::PORCENTAJE],
        );

        $this->fila();
        $this->nota('El % se calcula sobre todas las entradas del periodo, igual que en el panel. Una portería donde casi todo entra por cédula o a mano está controlando menos de lo que cree.');
    }
}
