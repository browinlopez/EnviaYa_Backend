<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Models\Order\OrdersSales;
use App\Queries\PedidosDelPanel;
use App\Services\Ajustes;
use App\Services\ResumenDelPanel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminApiController extends Controller
{
    public function overview(Request $request, ResumenDelPanel $resumen)
    {
        $dias = $resumen->rango($request);
        $desde = Carbon::now()->subDays($dias - 1)->startOfDay();
        $desdePrevio = (clone $desde)->subDays($dias);

        // Un solo recorrido de la tabla para los dos periodos: pedir dos
        // veces lo mismo con distinto WHERE duplica el escaneo sin ganar
        // nada.
        $ordenes = DB::table('orderssales')
            ->where('sale_date', '>=', $desdePrevio)
            ->get(['orderSales_id', 'state', 'total', 'subtotal', 'domicilio', 'domiciliary_fee', 'sale_date', 'payment_state']);

        $enPeriodo = fn ($o) => Carbon::parse($o->sale_date)->gte($desde);
        $entregadas = $ordenes->where('state', OrdersSales::ENTREGADO);

        $actual = $entregadas->filter($enPeriodo);
        $previo = $entregadas->reject($enPeriodo);

        $totales = [
            'revenue' => round($actual->sum('total'), 2),
            'revenue_prev' => round($previo->sum('total'), 2),
            'orders' => $ordenes->filter($enPeriodo)->count(),
            'orders_prev' => $ordenes->reject($enPeriodo)->count(),
            'avg_ticket' => $actual->count() ? round($actual->sum('total') / $actual->count(), 2) : 0,
            'delivery_fees' => round($actual->sum('domicilio'), 2),
            'courier_earnings' => round($actual->sum('domiciliary_fee'), 2),
        ];

        /*
         * Conteos globales: no dependen del rango, describen el estado actual.
         *
         * Tres de estos los VUELVE A PEDIR un bloque de abajo con otro nombre
         * —`businesses_total`, `products_total`, `unpaid_orders`—, así que los
         * cuenta `ResumenDelPanel` y los dos lados leen el mismo número. La
         * respuesta no cambia: eran la misma cifra con dos etiquetas, y la
         * sonda de consultas las veía salir dos veces cada una.
         */
        $totales += [
            'users' => DB::table('user')->count(),
            'businesses' => $resumen->negociosEnTotal(),
            'products' => $resumen->productosEnTotal(),
            'active_orders' => DB::table('orderssales')->whereIn('state', OrdersSales::ACTIVOS)->count(),
            'pending_payment' => $resumen->pedidosSinPagoAprobado(),
            // Pedidos que llevan más de un día sin llegar a entregado: la
            // señal más barata de que algo se atascó.
            'stalled_orders' => DB::table('orderssales')
                ->whereIn('state', OrdersSales::ACTIVOS)
                ->where('sale_date', '<', Carbon::now()->subDay())
                ->count(),
        ];

        $panel = new PedidosDelPanel;
        $repartidores = $panel->resumenDomiciliarios();
        $totales['couriers_total'] = $repartidores->count();
        $totales['couriers_available'] = $repartidores->where('available', 1)->count();
        $totales['couriers_at_limit'] = $repartidores
            ->where('active_deliveries', '>=', (int) Ajustes::valor('operacion.entregas_simultaneas'))
            ->count();

        return response()->json([
            'range' => $dias,
            'totals' => $totales,
            'series' => $resumen->serieDiaria($entregadas, $desde, $dias),
            'orders_by_state' => DB::table('orderssales')
                ->selectRaw('state, COUNT(*) as count')
                ->groupBy('state')
                ->orderBy('state')
                ->get(),
            'top_businesses' => $resumen->topNegocios($desde),
            'couriers' => $repartidores->sortByDesc('deliveries')->values(),
            'recent_orders' => $panel->ordenesBase()->orderByDesc('o.sale_date')->limit(10)->get(),
        ] + $resumen->bloquesDelInicio($request));
    }
}
