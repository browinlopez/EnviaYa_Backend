<?php

declare(strict_types=1);

namespace App\Services\Inversionista;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;

/**
 * INGRESOS: CUÁNTO SE VENDE POR LA APP Y CUÁNTO SE QUEDA VECIPA'YA.
 *
 * Es el reparto de la liquidación (`LiquidacionService::repartir`), sumado:
 * de cada pedido entregado y pagado, el negocio recibe productos menos
 * descuento menos comisión, el domiciliario su parte del domicilio, y lo que
 * queda es de la plataforma.
 *
 *  · Ventas (GMV) = Σ `total`: lo que pagaron los compradores.
 *  · Comisiones = Σ `platform_fee`: lo que se le cobra al negocio sobre su
 *    venta (productos menos descuento), congelado en el pedido.
 *  · Margen del domicilio = Σ (`domicilio` − `domiciliary_fee`): lo que pagó
 *    el comprador por el domicilio menos lo que se lleva el domiciliario.
 *  · Ingreso de la plataforma = comisiones + margen del domicilio.
 *  · Tasa de comisión efectiva (take rate) = ingreso ÷ ventas.
 *  · Subsidios = Σ `delivery_subsidy`: lo que la plataforma rebajó del
 *    domicilio en promociones. YA ESTÁ DESCONTADO del margen del domicilio
 *    —`domicilio` es la tarifa menos la rebaja, y el domiciliario cobra
 *    sobre la tarifa entera (`EconomiaDelPedido`)—, así que se informa para
 *    medir cuánto cuestan las campañas, no se resta otra vez.
 *
 * Los descuentos de cupones y promociones no tocan el ingreso: en la
 * liquidación se le descuentan al negocio.
 *
 * Sensible por diseño (es la plata de la empresa), sin una puerta aparte: lo
 * ve quien entra al panel del inversionista.
 */
class IngresosDelInversionista extends BaseDelInversionista
{
    public function datos(array $v, array $f): array
    {
        $ahora = $this->cifrasDe($this->entregados($f, $v['desde'], $v['hasta']));
        $antes = $this->cifrasDe($this->entregados($f, $v['desde_antes'], $v['hasta_antes']));

        $cifras = [];
        foreach ($ahora as $k => $valor) {
            $cifras[$k] = $this->cifra($valor, $antes[$k]);
        }

        return [
            'cifras' => $cifras,
            'por_tipo' => $this->porTipo($v, $f),
            'meses' => $this->meses($v['hasta'], $f),
        ];
    }

    private function sumas(Builder $q, string $prefijo = ''): Builder
    {
        return $q->selectRaw($prefijo.'
            COUNT(*) as pedidos,
            COALESCE(SUM(o.total), 0) as ventas,
            COALESCE(SUM(o.platform_fee), 0) as comisiones,
            COALESCE(SUM(o.domicilio), 0) as domicilios,
            COALESCE(SUM(o.domiciliary_fee), 0) as pago_domiciliarios,
            COALESCE(SUM(o.delivery_subsidy), 0) as subsidios');
    }

    private function cifrasDe(Builder $q): array
    {
        return $this->armar($this->sumas($q)->first());
    }

    private function armar(?object $s): array
    {
        $ventas = $this->pesos($s->ventas ?? 0);
        $comisiones = $this->pesos($s->comisiones ?? 0);
        $margen = $this->pesos(($s->domicilios ?? 0) - ($s->pago_domiciliarios ?? 0));
        $ingreso = round($comisiones + $margen, 2);

        return [
            'ventas' => $ventas,
            'comisiones' => $comisiones,
            'domicilios' => $this->pesos($s->domicilios ?? 0),
            'pago_domiciliarios' => $this->pesos($s->pago_domiciliarios ?? 0),
            'margen_domicilio' => $margen,
            'ingreso' => $ingreso,
            'take_rate' => $ventas > 0 ? round($ingreso / $ventas * 100, 2) : null,
            'subsidios' => $this->pesos($s->subsidios ?? 0),
            'ingreso_por_pedido' => $this->cociente($ingreso, (int) ($s->pedidos ?? 0), 2),
        ];
    }

    /** El ingreso de cada oficio. Sin el filtro de tipo, como el reparto del Resumen. */
    private function porTipo(array $v, array $f): array
    {
        $sinTipo = ['municipality_id' => $f['municipality_id'], 'tipo' => null];

        $filas = $this->sumas($this->entregados($sinTipo, $v['desde'], $v['hasta'], true), 'b.type as tipo,')
            ->groupBy('b.type')
            ->get()
            ->keyBy('tipo');

        $total = 0.0;
        $lista = array_map(function (array $t) use ($filas, &$total) {
            $c = $this->armar($filas[$t['id']] ?? null);
            $total += $c['ingreso'];

            return $t + [
                'ventas' => $c['ventas'],
                'ingreso' => $c['ingreso'],
                'take_rate' => $c['take_rate'],
            ];
        }, $this->tipos());

        return array_map(fn ($t) => $t + [
            'participacion' => $total > 0 ? round($t['ingreso'] / $total * 100, 1) : 0,
        ], $lista);
    }

    private function meses(Carbon $hasta, array $f): array
    {
        [$inicio, $fin, $meses] = $this->mesesHasta($hasta);

        $filas = $this->sumas($this->entregados($f, $inicio, $fin), "{$this->mesDe('o.sale_date')} as mes,")
            ->groupBy('mes')
            ->get()
            ->keyBy('mes');

        return array_map(function ($m) use ($filas) {
            $c = $this->armar($filas[$m['mes']] ?? null);

            return $this->rotuloDeMes($m) + [
                'ventas' => $c['ventas'],
                'comisiones' => $c['comisiones'],
                'margen_domicilio' => $c['margen_domicilio'],
                'ingreso' => $c['ingreso'],
                'take_rate' => $c['take_rate'],
                'subsidios' => $c['subsidios'],
            ];
        }, $meses);
    }
}
