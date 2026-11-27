<?php

declare(strict_types=1);

namespace App\Services\Inversionista;

use App\Models\Order\OrdersSales;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * OPERACIÓN: QUÉ TAN RÁPIDO Y QUÉ TAN BIEN SE ENTREGA.
 *
 * LAS DEFINICIONES (sobre pedidos entregados y pagados, salvo la cancelación):
 *
 *  · Tiempo en camino = minutos de `dispatched_at` (sale del negocio) a
 *    `delivery_date` (lo recibe el comprador). Es el tramo que se le promete
 *    al comprador y el que se compara contra el plazo.
 *  · Tiempo total = minutos de `sale_date` (hace el pedido) a `delivery_date`:
 *    lo que espera el comprador de punta a punta, preparación incluida. Sin
 *    los programados, que se piden con horas de anticipación a propósito.
 *  · En los dos: solo domicilios (no lo que se recoge en el local), solo con
 *    las dos marcas y entre 0 y 24 horas. Una marca que falta no se estima, y
 *    un pedido cerrado a mano días después no es un tiempo de entrega.
 *  · A tiempo = de las entregas con las dos marcas y con plazo prometido
 *    (`promised_minutes`, congelado al despachar), las que llegaron en ese
 *    plazo o antes. El porcentaje sale sobre las que se pueden juzgar, igual
 *    que en el reporte operacional del panel interno.
 *  · Cancelados = pedidos en estado cancelado (5) sobre TODOS los pedidos
 *    hechos en el periodo, en cualquier estado.
 *  · Domiciliarios activos = domiciliarios distintos con al menos una entrega
 *    en el periodo. Pedidos por domiciliario = entregas con domiciliario ÷
 *    activos.
 *  · Domiciliarios disponibles = cuentas de domiciliario habilitadas y
 *    marcadas como disponibles AHORA (es una foto, no depende del periodo).
 *    Con filtros, las vinculadas a un negocio de esa ciudad o tipo.
 */
class OperacionDelInversionista extends BaseDelInversionista
{
    /** Más de un día no es un tiempo de entrega: es un pedido cerrado tarde. */
    private const TOPE_MINUTOS = 1440;

    public function datos(array $v, array $f): array
    {
        $ahora = $this->cifrasDe($f, $v['desde'], $v['hasta']);
        $antes = $this->cifrasDe($f, $v['desde_antes'], $v['hasta_antes']);

        $cifras = [];
        foreach ($ahora as $k => $valor) {
            $cifras[$k] = $this->cifra($valor, $antes[$k]);
        }
        $cifras['entregas_medidas'] = $ahora['medidas'];
        unset($cifras['medidas']);

        return [
            'cifras' => $cifras,
            'flota' => $this->flota($f),
            'meses' => $this->meses($v['hasta'], $f),
        ];
    }

    /* ------------------------------ CIFRAS ------------------------------ */

    private function cifrasDe(array $f, Carbon $desde, Carbon $hasta): array
    {
        $e = $this->tiempos($this->entregados($f, $desde, $hasta))->first();
        $p = $this->cancelaciones($this->pedidos($f, $desde, $hasta))->first();

        return $this->armar($e, $p);
    }

    /**
     * Las columnas de tiempos, plazo y flota, para agregarse como se quiera
     * (el periodo entero o mes a mes).
     */
    private function tiempos(Builder $q, ?string $mes = null): Builder
    {
        $camino = $this->minutosEntre('o.dispatched_at', 'o.delivery_date');
        $total = $this->minutosEntre('o.sale_date', 'o.delivery_date');
        $tope = self::TOPE_MINUTOS;
        $conMarcas = 'o.dispatched_at IS NOT NULL AND o.delivery_date IS NOT NULL';
        $domicilio = 'COALESCE(o.pickup, 0) = 0';

        return $q->selectRaw(($mes ? "{$mes} as mes, " : '')."
            COUNT(*) as entregados,
            AVG(CASE WHEN {$domicilio} AND {$conMarcas} AND {$camino} BETWEEN 0 AND {$tope} THEN {$camino} END) as en_camino,
            AVG(CASE WHEN {$domicilio} AND COALESCE(o.is_scheduled, 0) = 0 AND o.delivery_date IS NOT NULL
                AND {$total} BETWEEN 0 AND {$tope} THEN {$total} END) as total,
            SUM(CASE WHEN {$conMarcas} AND o.promised_minutes IS NOT NULL THEN 1 ELSE 0 END) as medidas,
            SUM(CASE WHEN {$conMarcas} AND o.promised_minutes IS NOT NULL AND {$camino} <= o.promised_minutes THEN 1 ELSE 0 END) as a_tiempo,
            COUNT(DISTINCT o.domiciliary_id) as domiciliarios,
            SUM(CASE WHEN o.domiciliary_id IS NOT NULL THEN 1 ELSE 0 END) as con_domiciliario");
    }

    private function cancelaciones(Builder $q, ?string $mes = null): Builder
    {
        return $q->selectRaw(($mes ? "{$mes} as mes, " : '').'COUNT(*) as hechos, SUM(CASE WHEN o.state = ? THEN 1 ELSE 0 END) as cancelados', [OrdersSales::CANCELADO]);
    }

    private function armar(?object $e, ?object $p): array
    {
        $domiciliarios = (int) ($e->domiciliarios ?? 0);
        $medidas = (int) ($e->medidas ?? 0);

        return [
            'entregados' => (int) ($e->entregados ?? 0),
            'minutos_en_camino' => $e?->en_camino === null ? null : round((float) $e->en_camino, 1),
            'minutos_totales' => $e?->total === null ? null : round((float) $e->total, 1),
            'a_tiempo' => $this->porcentaje((int) ($e->a_tiempo ?? 0), $medidas),
            'medidas' => $medidas,
            'pedidos_hechos' => (int) ($p->hechos ?? 0),
            'cancelados' => (int) ($p->cancelados ?? 0),
            'porcentaje_cancelados' => $this->porcentaje((int) ($p->cancelados ?? 0), (int) ($p->hechos ?? 0)),
            'domiciliarios_activos' => $domiciliarios,
            'pedidos_por_domiciliario' => $this->cociente((int) ($e->con_domiciliario ?? 0), $domiciliarios),
        ];
    }

    /* ------------------------------- FLOTA ------------------------------ */

    /** La flota de hoy: habilitados y, de ellos, los disponibles. */
    private function flota(array $f): array
    {
        $q = DB::table('domiciliary as d')->where('d.state', 1);

        if ($f['municipality_id'] || $f['tipo']) {
            $q->whereExists(fn ($s) => $s->from('business_domiciliary as bd')
                ->join('business as b', 'b.busines_id', '=', 'bd.busines_id')
                ->whereColumn('bd.domiciliary_id', 'd.domiciliary_id')
                ->where('bd.state', 1)
                ->when($f['municipality_id'], fn ($w, $m) => $w->where('b.municipality_id', $m))
                ->when($f['tipo'], fn ($w, $t) => $w->where('b.type', $t))
                ->selectRaw('1'));
        }

        $fila = $q->selectRaw('COUNT(*) as habilitados, COALESCE(SUM(CASE WHEN d.available = 1 THEN 1 ELSE 0 END), 0) as disponibles')->first();

        return [
            'habilitados' => (int) ($fila->habilitados ?? 0),
            'disponibles' => (int) ($fila->disponibles ?? 0),
        ];
    }

    /* ------------------------------ MESES ------------------------------- */

    private function meses(Carbon $hasta, array $f): array
    {
        [$inicio, $fin, $meses] = $this->mesesHasta($hasta);
        $mes = $this->mesDe('o.sale_date');

        $entregas = $this->tiempos($this->entregados($f, $inicio, $fin), $mes)
            ->groupBy('mes')->get()->keyBy('mes');
        $pedidos = $this->cancelaciones($this->pedidos($f, $inicio, $fin), $mes)
            ->groupBy('mes')->get()->keyBy('mes');

        return array_map(function ($m) use ($entregas, $pedidos) {
            $c = $this->armar($entregas[$m['mes']] ?? null, $pedidos[$m['mes']] ?? null);
            unset($c['medidas'], $c['pedidos_hechos']);

            return $this->rotuloDeMes($m) + $c;
        }, $meses);
    }
}
