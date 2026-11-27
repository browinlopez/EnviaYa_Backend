<?php

declare(strict_types=1);

namespace Database\Seeders\DemostracionQa;

use Carbon\CarbonImmutable;

// Las ventas de mostrador de los últimos cuatro meses: el menudeo sin pedido de la app.
final class Mostrador
{
    private const DIAS = 120;

    // Ventas por día ya maduro, por oficio: [mín, máx].
    private const POR_DIA = ['tienda' => [5, 9], 'drogueria' => [2, 5], 'restaurante' => [4, 8], 'repuestos' => [1, 3]];

    private const HORAS = [7 => 5, 8 => 7, 9 => 7, 10 => 6, 11 => 7, 12 => 9, 13 => 8, 14 => 5, 15 => 5, 16 => 6, 17 => 8, 18 => 9, 19 => 8, 20 => 5];

    public function __construct(private Escenario $e) {}

    public function sembrar(): void
    {
        foreach ($this->e->negocios as $n) {
            if ($n['diarios'] <= 0) {
                continue;
            }

            $desde = $n['primera']->max($this->e->hoy->subDays(self::DIAS));

            for ($dia = $desde; $dia->lte($this->e->hoy); $dia = $dia->addDay()) {
                $edad = (int) round($n['primera']->diffInDays($dia, true));
                [$min, $max] = self::POR_DIA[$n['oficio']];
                $cuantas = (int) round(mt_rand($min, $max) * min(1.0, 0.3 + 0.7 * $edad / 60));

                for ($k = 0; $k < $cuantas; $k++) {
                    $cuando = $dia->setTime((int) Azar::elegir(self::HORAS), mt_rand(0, 59), mt_rand(0, 59));

                    if ($cuando->lt($this->e->ahora->subMinutes(10))) {
                        $this->venta($n, $cuando);
                    }
                }
            }

            $this->e->lote->guardar();
        }
    }

    private function venta(array $n, CarbonImmutable $cuando): void
    {
        $pesos = array_column($n['surtido'], 'peso', 'id');
        $porId = array_column($n['surtido'], null, 'id');
        $elegidos = [];

        for ($k = mt_rand(1, $n['oficio'] === 'tienda' ? 4 : 2); $k > 0; $k--) {
            $id = (int) Azar::elegir($pesos);
            $elegidos[$id] = ($elegidos[$id] ?? 0) + ($n['oficio'] === 'tienda' ? (int) Azar::elegir([1 => 70, 2 => 25, 3 => 5]) : 1);
        }

        $id = $this->e->lote->id('ventas_mostrador', 'venta_id');
        $total = 0.0;

        foreach ($elegidos as $productoId => $cantidad) {
            $p = $porId[$productoId];
            $total += $p['precio'] * $cantidad;

            $this->e->lote->poner('ventas_mostrador_lineas', [
                'venta_id' => $id, 'products_id' => $productoId, 'nombre' => $p['nombre'], 'sale_unit' => 'unidad',
                'pedido_por' => null, 'cantidad' => $cantidad, 'unit_price' => $p['precio'], 'unit_cost' => $p['costo'],
                'line_total' => $p['precio'] * $cantidad,
            ]);
        }

        $forma = (string) Azar::elegir(['efectivo' => 66, 'transferencia' => 16, 'qr' => 18]);
        $recibido = $forma === 'efectivo' && Azar::si(55) ? ceil($total / 10000) * 10000 : null;

        $this->e->lote->poner('ventas_mostrador', [
            'venta_id' => $id, 'busines_id' => $n['id'], 'user_id' => $n['dueno_user'], 'total' => $total,
            'forma_de_pago' => $forma, 'estado_pago' => 'pagada',
            'comision_pasarela' => $forma === 'qr' ? round($total * (float) config('operacion.comision_qr', 0.0289), 2) : 0,
            'cash_tendered' => $recibido, 'change_given' => $recibido === null ? null : $recibido - $total,
            'vendida_at' => $cuando, 'created_at' => $cuando, 'updated_at' => $cuando,
        ]);
    }
}
