<?php

declare(strict_types=1);

namespace App\Exports\Conjunto\Hojas;

/**
 * Cuántos pedidos llegaron a cada torre. Cuántos, no quién pidió.
 *
 * La torre sale de los pedidos que traía cada domiciliario al entrar, y la
 * escribió el COMPRADOR en su dirección: por eso la columna A pasa por el
 * binder sin fórmulas como cualquier otra.
 */
class HojaPorTorre extends HojaDelReporte
{
    public const TITULO = 'Por torre';

    public function title(): string
    {
        return self::TITULO;
    }

    protected function encabezado(): string
    {
        return 'Pedidos por torre';
    }

    protected function colorDePestana(): string
    {
        return 'C24E3E';
    }

    protected function columnas(): array
    {
        return ['A' => 18, 'B' => 12, 'C' => 13, 'D' => 10];
    }

    protected function cuerpo(): void
    {
        $c = $this->c;
        $filas = [];

        foreach ($c->torres as $i => $t) {
            $filas[] = [
                $t['torre'],
                $t['pedidos'],
                $c->pedidosEnTorres ? $t['pedidos'] / $c->pedidosEnTorres : 0,
                $i + 1,
            ];
        }

        if ($filas === []) {
            $filas[] = ['Sin pedidos en el periodo', 0, 0, null];
        }

        $this->tabla(
            ['Torre', 'Pedidos', '% del total', 'Puesto'],
            $filas,
            ['Total', $c->pedidosEnTorres, $c->pedidosEnTorres ? 1 : 0, null],
            [1 => self::ENTERO, 2 => self::PORCENTAJE_1, 3 => '0'],
        );

        $this->fila();
        $this->nota('Pedidos de VeciPa\'Ya que los domiciliarios traían al entrar, por la torre de entrega. Sin nombres de residentes: dice cuántos pedidos llegaron a cada torre, no quién pidió qué.');
    }
}
