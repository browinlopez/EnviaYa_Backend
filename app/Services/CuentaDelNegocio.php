<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Negocio\GastoDelNegocio;
use App\Models\Order\OrdersSales;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * «CUÁNTO TE QUEDA»: EL ESTADO DE PÉRDIDAS Y GANANCIAS, SIN CONTABILIDAD.
 *
 *     Lo que vendiste                         (productos − promociones)
 *   − Lo que te costaron esos productos       (costo × cantidad vendida)
 *   = Lo que le ganaste a los productos
 *   − Lo que cobra VeciPa'Ya                  (la comisión, `platform_fee`)
 *   − Los gastos del negocio                  (luz, agua, arriendo…)
 *   = Lo que te queda
 *
 * Se cuentan los pedidos ENTREGADOS en el periodo, por el día en que se
 * entregaron, y las ventas hechas en el MOSTRADOR. El domicilio no entra: es
 * del domiciliario. Las de mostrador no pagan comisión: no pasaron por la app.
 *
 * El costo de cada línea es el CONGELADO al vender (`unit_cost`); para las
 * ventas de antes de que existiera, el costo que tiene hoy el producto. Las
 * líneas sin ningún costo no se inventan: se cuentan aparte para decirle al
 * tendero cuáles le faltan, porque sin ellas la ganancia sale de más.
 */
class CuentaDelNegocio
{
    /**
     * @param  'mes'|'semana'  $periodo
     * @return array<string, mixed>
     */
    public function para(int $negocio, string $periodo, CarbonInterface $dia): array
    {
        $dia = CarbonImmutable::instance($dia);
        [$desde, $hasta] = $periodo === 'semana'
            ? [$dia->startOfWeek(), $dia->endOfWeek()]
            : [$dia->startOfMonth(), $dia->endOfMonth()];

        return $this->cuenta($negocio, $periodo, $desde, $hasta);
    }

    /**
     * La misma cuenta entre dos días cualesquiera (el calendario del panel:
     * hoy, esta semana, un rango a mano). Los gastos fijos cuentan por los
     * días del rango que caen en cada mes.
     *
     * @return array<string, mixed>
     */
    public function entre(int $negocio, CarbonInterface $desde, CarbonInterface $hasta): array
    {
        return $this->cuenta(
            $negocio,
            'rango',
            CarbonImmutable::instance($desde)->startOfDay(),
            CarbonImmutable::instance($hasta)->endOfDay(),
        );
    }

    /** @return array<string, mixed> */
    private function cuenta(int $negocio, string $periodo, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {

        $pedidos = OrdersSales::where('busines_id', $negocio)
            ->where('state', OrdersSales::ENTREGADO)
            ->whereBetween('delivery_date', [$desde, $hasta])
            ->get(['orderSales_id', 'subtotal', 'discount', 'platform_fee']);

        $ventasApp = (float) $pedidos->sum(fn ($o) => (float) $o->subtotal - (float) $o->discount);
        $comision = (float) $pedidos->sum(fn ($o) => (float) $o->platform_fee);

        [$costo, $sinCosto] = $this->costoDeLoVendido($negocio, $pedidos->pluck('orderSales_id')->all());

        [$ventasMostrador, $costoMostrador, $cuantasMostrador] = $this->mostrador($negocio, $desde, $hasta, $sinCosto);
        // Lo que cobró Bold por los QR del mostrador también sale de la cuenta.
        $comision += (float) DB::table('ventas_mostrador')
            ->where('busines_id', $negocio)
            ->where('estado_pago', 'pagada')
            ->whereBetween('vendida_at', [$desde, $hasta])
            ->sum('comision_pasarela');
        $ventas = $ventasApp + $ventasMostrador;
        $costo += $costoMostrador;
        [$gastos, $detalle] = $this->gastos($negocio, $desde, $hasta);

        $margen = $ventas - $costo;
        $queda = $margen - $comision - $gastos;

        return [
            'periodo' => $periodo,
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'pedidos' => $pedidos->count(),
            'ventas' => round($ventas, 2),
            // De dónde salió: la app y el mostrador.
            'ventas_app' => round($ventasApp, 2),
            'ventas_mostrador' => round($ventasMostrador, 2),
            'ventas_en_mostrador' => $cuantasMostrador,
            'costo_de_lo_vendido' => round($costo, 2),
            'margen' => round($margen, 2),
            'comision' => round($comision, 2),
            'gastos' => round($gastos, 2),
            'gastos_detalle' => $detalle,
            'queda' => round($queda, 2),
            'sin_costo' => $sinCosto,
        ];
    }

    /**
     * @param  list<int>  $pedidos
     * @return array{0: float, 1: array{lineas: int, ventas: float, productos: list<array{products_id: int, nombre: string}>}}
     */
    private function costoDeLoVendido(int $negocio, array $pedidos): array
    {
        if (! $pedidos) {
            return [0.0, ['lineas' => 0, 'ventas' => 0.0, 'productos' => []]];
        }

        $lineas = DB::table('orderssales_detail as d')
            ->leftJoin('products_business as pb', function ($j) use ($negocio) {
                $j->on('pb.products_id', '=', 'd.product_id')->where('pb.busines_id', $negocio);
            })
            ->leftJoin('products as p', 'p.products_id', '=', 'd.product_id')
            ->whereIn('d.orderSales_id', $pedidos)
            ->get([
                'd.product_id', 'd.amount', 'd.unit_price', 'd.unit_cost', 'd.line_total',
                'd.qty_requested', 'd.qty_final', 'pb.costo', 'p.name',
            ]);

        $costo = 0.0;
        $faltan = ['lineas' => 0, 'ventas' => 0.0, 'productos' => []];

        foreach ($lineas as $l) {
            // Lo que va al peso se cuenta por lo que se pesó (o lo pedido).
            $cantidad = $l->line_total !== null
                ? (float) ($l->qty_final ?? $l->qty_requested ?? 1)
                : (float) $l->amount;
            $unitario = $l->unit_cost ?? $l->costo;

            if ($unitario === null) {
                $faltan['lineas']++;
                $faltan['ventas'] += $l->line_total !== null
                    ? (float) $l->line_total
                    : (float) $l->unit_price * (float) $l->amount;
                $faltan['productos'][$l->product_id] = [
                    'products_id' => (int) $l->product_id,
                    'nombre' => (string) $l->name,
                ];

                continue;
            }

            $costo += (float) $unitario * $cantidad;
        }

        $faltan['ventas'] = round($faltan['ventas'], 2);
        $faltan['productos'] = array_values($faltan['productos']);

        return [$costo, $faltan];
    }

    /**
     * Lo vendido en el mostrador: plata, costo y cuántas ventas. Las líneas
     * sin costo se suman a las que ya faltaban en los pedidos.
     *
     * @param  array{lineas: int, ventas: float, productos: list<array{products_id: int, nombre: string}>}  $sinCosto
     * @return array{0: float, 1: float, 2: int}
     */
    private function mostrador(int $negocio, CarbonImmutable $desde, CarbonImmutable $hasta, array &$sinCosto): array
    {
        $ventas = DB::table('ventas_mostrador')
            ->where('busines_id', $negocio)
            ->where('estado_pago', 'pagada')
            ->whereBetween('vendida_at', [$desde, $hasta]);

        $ids = (clone $ventas)->pluck('venta_id')->all();
        if (! $ids) {
            return [0.0, 0.0, 0];
        }

        $lineas = DB::table('ventas_mostrador_lineas as l')
            ->leftJoin('products_business as pb', function ($j) use ($negocio) {
                $j->on('pb.products_id', '=', 'l.products_id')->where('pb.busines_id', $negocio);
            })
            ->whereIn('l.venta_id', $ids)
            ->get(['l.products_id', 'l.nombre', 'l.cantidad', 'l.unit_cost', 'l.line_total', 'pb.costo']);

        $costo = 0.0;
        $yaListados = array_column($sinCosto['productos'], 'products_id');

        foreach ($lineas as $l) {
            $unitario = $l->unit_cost ?? $l->costo;

            if ($unitario === null) {
                $sinCosto['lineas']++;
                $sinCosto['ventas'] = round($sinCosto['ventas'] + (float) $l->line_total, 2);
                if (! in_array((int) $l->products_id, $yaListados, true)) {
                    $sinCosto['productos'][] = ['products_id' => (int) $l->products_id, 'nombre' => (string) $l->nombre];
                    $yaListados[] = (int) $l->products_id;
                }

                continue;
            }

            $costo += (float) $unitario * (float) $l->cantidad;
        }

        return [(float) $ventas->sum('total'), $costo, count($ids)];
    }

    /**
     * Los gastos del periodo. Un fijo mensual cuenta entero en su mes; en una
     * semana, la parte de los días que caen en ella.
     *
     * @return array{0: float, 1: list<array{gasto_id: int, tipo: string, nombre: string, monto: float}>}
     */
    private function gastos(int $negocio, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $gastos = GastoDelNegocio::where('busines_id', $negocio)->with('meses')->get();
        $detalle = [];
        $total = 0.0;

        foreach ($gastos as $g) {
            $monto = 0.0;

            if ($g->frecuencia === GastoDelNegocio::UNA_VEZ) {
                if ($g->desde->between($desde, $hasta)) {
                    $monto = (float) $g->monto;
                }
            } else {
                $mes = $desde->startOfMonth();
                while ($mes->lte($hasta)) {
                    $finDelMes = $mes->endOfMonth();
                    $activo = $g->desde->lte($finDelMes)
                        && ($g->hasta === null || $g->hasta->gte($mes));

                    if ($activo) {
                        $delMes = $g->meses->firstWhere('periodo', $mes->format('Y-m'));
                        $valor = (float) ($delMes->monto ?? $g->monto);
                        $ini = $desde->gt($mes) ? $desde : $mes;
                        $fin = $hasta->lt($finDelMes) ? $hasta : $finDelMes;
                        $dias = (int) round(abs($ini->startOfDay()->diffInDays($fin->startOfDay()))) + 1;
                        $monto += $valor * $dias / $mes->daysInMonth;
                    }

                    $mes = $mes->addMonth();
                }
            }

            if ($monto > 0) {
                $detalle[] = [
                    'gasto_id' => (int) $g->gasto_id,
                    'tipo' => (string) $g->tipo,
                    'nombre' => (string) $g->nombre,
                    'monto' => round($monto, 2),
                ];
                $total += $monto;
            }
        }

        return [$total, $detalle];
    }
}
