<?php

declare(strict_types=1);

namespace App\Services\Inversionista;

use App\Models\Order\OrdersSales;
use App\Services\GeneradorDeReportes;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * NEGOCIOS: CUÁNTOS HAY, CUÁNTOS VENDEN Y CUÁNTO TARDAN EN ARRANCAR.
 *
 * Todo en agregados, sin el nombre de un solo negocio:
 *
 *  · El reparto por tipo (el mismo del Resumen) con la parte de las ventas
 *    que se lleva cada oficio.
 *  · Activos frente a «con ventas»: activo es visible en la app; con ventas,
 *    que tuvo al menos un pedido entregado y pagado en el periodo. La
 *    diferencia son negocios abiertos que no están vendiendo.
 *  · Los negocios nuevos de cada mes y los que hicieron su PRIMERA venta ese
 *    mes (la activación).
 *  · Días desde el alta hasta la primera venta: de `business.created_at` al
 *    primer pedido entregado y pagado. Solo los negocios con fecha de alta
 *    (existe desde agosto de 2026); los demás se cuentan aparte.
 */
class NegociosDelInversionista extends BaseDelInversionista
{
    public function __construct(GeneradorDeReportes $reportes, private ResumenDelInversionista $resumen)
    {
        parent::__construct($reportes);
    }

    public function datos(array $v, array $f): array
    {
        $primeras = $this->primerasVentas($f, $v['hasta']->copy()->endOfMonth());

        return [
            'actividad' => $this->actividad($v, $f),
            'por_tipo' => $this->resumen->porTipo($v, $f),
            'arranque' => $this->arranque($primeras, $v['hasta']),
            'meses' => $this->meses($v['hasta'], $f, $primeras),
        ];
    }

    /* ---------------------------- ACTIVIDAD ----------------------------- */

    private function actividad(array $v, array $f): array
    {
        $activos = $this->negociosAl($f, $v['hasta'])->count();
        $activosAntes = $this->negociosAl($f, $v['hasta_antes'])->count();

        $conVentas = fn (Carbon $desde, Carbon $hasta) => $this->entregados($f, $desde, $hasta, true)
            ->where('b.state', 1)
            ->selectRaw('COUNT(DISTINCT o.busines_id) as n, COUNT(*) as pedidos')
            ->first();

        $ahora = $conVentas($v['desde'], $v['hasta']);
        $antes = $conVentas($v['desde_antes'], $v['hasta_antes']);
        $n = (int) ($ahora->n ?? 0);
        $nAntes = (int) ($antes->n ?? 0);

        return [
            'activos' => $this->cifra($activos, $activosAntes),
            'con_ventas' => $this->cifra($n, $nAntes),
            'sin_ventas' => max(0, $activos - $n),
            'porcentaje_con_ventas' => $this->porcentaje($n, $activos),
            /*
             * Cuántos pedidos entrega, en promedio, un negocio que sí vendió.
             * En pedidos y no en pesos: el inversionista ve crecimiento, no
             * la plata de cada negocio.
             */
            'pedidos_por_negocio' => $this->cifra(
                $this->cociente((int) ($ahora->pedidos ?? 0), $n, 1),
                $this->cociente((int) ($antes->pedidos ?? 0), $nAntes, 1),
            ),
            'nuevos' => $this->negocios($f)->whereBetween('b.created_at', [$v['desde'], $v['hasta']])->count(),
        ];
    }

    /* -------------------------- PRIMERA VENTA --------------------------- */

    /**
     * Por cada negocio activo (con los filtros), su fecha de alta y la de su
     * primer pedido entregado y pagado hasta el corte. Son filas de trabajo:
     * solo llevan las dos FECHAS, nunca el negocio, y de acá solo salen
     * promedios y conteos.
     *
     * @return list<array{alta: ?Carbon, primera: ?Carbon}>
     */
    private function primerasVentas(array $f, Carbon $corte): array
    {
        $primera = DB::table('orderssales as o')
            ->where('o.state', OrdersSales::ENTREGADO)
            ->whereNotIn('o.payment_state', OrdersSales::SIN_PAGO)
            ->where('o.sale_date', '<=', $corte)
            ->groupBy('o.busines_id')
            ->selectRaw('o.busines_id, MIN(o.sale_date) as primera');

        return $this->negocios($f)
            ->leftJoinSub($primera, 'p', 'p.busines_id', '=', 'b.busines_id')
            ->get(['b.created_at as alta', 'p.primera'])
            ->map(fn ($r) => [
                'alta' => $r->alta ? Carbon::parse($r->alta) : null,
                'primera' => $r->primera ? Carbon::parse($r->primera) : null,
            ])
            ->all();
    }

    /**
     * Cuánto tarda un negocio en vender por primera vez.
     *
     * Días calendario de la fecha de alta a la del primer pedido. Uno que
     * vendió el mismo día de su alta cuenta 0. Si la primera venta quedó
     * antes del alta (un negocio que se dio de alta otra vez), también 0:
     * no hay días negativos.
     */
    private function arranque(array $filas, Carbon $hasta): array
    {
        $conAlta = array_filter($filas, fn ($r) => $r['alta'] && $r['alta']->lte($hasta));
        $dias = [];
        $enEspera = 0;

        foreach ($conAlta as $r) {
            if ($r['primera'] && $r['primera']->lte($hasta)) {
                $dias[] = max(0, (int) $r['alta']->copy()->startOfDay()->diffInDays($r['primera']->copy()->startOfDay()));
            } else {
                $enEspera++;
            }
        }

        sort($dias);
        $n = count($dias);
        $mediana = null;
        if ($n > 0) {
            $mitad = intdiv($n, 2);
            $mediana = $n % 2 ? (float) $dias[$mitad] : ($dias[$mitad - 1] + $dias[$mitad]) / 2;
        }

        return [
            'promedio_dias' => $n ? round(array_sum($dias) / $n, 1) : null,
            'mediana_dias' => $mediana === null ? null : round($mediana, 1),
            'medidos' => $n,
            // Con fecha de alta pero sin ninguna venta todavía.
            'en_espera' => $enEspera,
            // Sin fecha de alta (los de antes de agosto de 2026): no se miden.
            'sin_fecha_de_alta' => count(array_filter($filas, fn ($r) => $r['alta'] === null)),
            'tramos' => [
                ['clave' => 'semana', 'rotulo' => 'La primera semana', 'negocios' => count(array_filter($dias, fn ($d) => $d <= 7))],
                ['clave' => 'mes', 'rotulo' => 'Entre 8 y 30 días', 'negocios' => count(array_filter($dias, fn ($d) => $d > 7 && $d <= 30))],
                ['clave' => 'mas', 'rotulo' => 'Más de 30 días', 'negocios' => count(array_filter($dias, fn ($d) => $d > 30))],
            ],
        ];
    }

    /* ------------------------------ MESES ------------------------------- */

    private function meses(Carbon $hasta, array $f, array $primeras): array
    {
        [$inicio, $fin, $meses] = $this->mesesHasta($hasta);

        $nuevos = $this->negocios($f)
            ->whereBetween('b.created_at', [$inicio, $fin])
            ->selectRaw("{$this->mesDe('b.created_at')} as mes, COUNT(*) as n")
            ->groupBy('mes')
            ->pluck('n', 'mes');

        $conVentas = $this->entregados($f, $inicio, $fin, true)
            ->where('b.state', 1)
            ->selectRaw("{$this->mesDe('o.sale_date')} as mes, COUNT(DISTINCT o.busines_id) as n")
            ->groupBy('mes')
            ->pluck('n', 'mes');

        $activaciones = [];
        foreach ($primeras as $r) {
            if ($r['primera']) {
                $k = $r['primera']->format('Y-m');
                $activaciones[$k] = ($activaciones[$k] ?? 0) + 1;
            }
        }

        return array_map(fn ($m) => $this->rotuloDeMes($m) + [
            'negocios_nuevos' => (int) ($nuevos[$m['mes']] ?? 0),
            'primeras_ventas' => (int) ($activaciones[$m['mes']] ?? 0),
            'con_ventas' => (int) ($conVentas[$m['mes']] ?? 0),
        ], $meses);
    }
}
