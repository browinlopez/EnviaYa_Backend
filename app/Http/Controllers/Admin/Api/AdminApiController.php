<?php

namespace App\Http\Controllers\Admin\Api;

use App\Services\ResumenDelPanel;
use App\Support\Consultas\AyudasDeAdmin;
use App\Services\Ajustes;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Conjunto\ComplexStaff;
use App\Models\Operacion\DomiciliaryDocument;
use App\Models\Reviews\BusinessReview;
use App\Models\Reviews\DomiciliaryReview;
use App\Models\User;
use App\Services\BusinessMediaService;
use App\Services\ContratoService;
use App\Services\MediaService;
use App\Services\VinculosDelRol;
use App\Support\ListadoPaginado;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminApiController extends Controller
{
    use AyudasDeAdmin;

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

        $enPeriodo = fn($o) => Carbon::parse($o->sale_date)->gte($desde);
        $entregadas = $ordenes->where('state', self::ENTREGADO);

        $actual = $entregadas->filter($enPeriodo);
        $previo = $entregadas->reject($enPeriodo);

        $totales = [
            'revenue'          => round($actual->sum('total'), 2),
            'revenue_prev'     => round($previo->sum('total'), 2),
            'orders'           => $ordenes->filter($enPeriodo)->count(),
            'orders_prev'      => $ordenes->reject($enPeriodo)->count(),
            'avg_ticket'       => $actual->count() ? round($actual->sum('total') / $actual->count(), 2) : 0,
            'delivery_fees'    => round($actual->sum('domicilio'), 2),
            'courier_earnings' => round($actual->sum('domiciliary_fee'), 2),
        ];

        // Conteos globales: no dependen del rango, describen el estado actual.
        $totales += [
            'users'          => DB::table('user')->count(),
            'businesses'     => DB::table('business')->count(),
            'products'       => DB::table('products')->count(),
            'active_orders'  => DB::table('orderssales')->whereIn('state', self::ACTIVOS)->count(),
            // "Pendiente de pago" se resuelve contra la tabla `payments`, que
            // es el registro real del dinero recibido, y no contra
            // `orderssales.payment_state`, que es una copia denormalizada que
            // puede quedar desincronizada. Usar la bandera hacía que el panel
            // contara como pendientes pedidos que la pantalla de Pagos
            // mostraba aprobados: dos pantallas, dos respuestas.
            'pending_payment' => DB::table('orderssales as o')
                ->whereIn('o.state', self::ACTIVOS)
                ->whereNotExists(fn($q) => $this->pagoAprobado($q))
                ->count(),
            // Pedidos que llevan más de un día sin llegar a entregado: la
            // señal más barata de que algo se atascó.
            'stalled_orders' => DB::table('orderssales')
                ->whereIn('state', self::ACTIVOS)
                ->where('sale_date', '<', Carbon::now()->subDay())
                ->count(),
        ];

        $repartidores = $this->resumenDomiciliarios();
        $totales['couriers_total'] = $repartidores->count();
        $totales['couriers_available'] = $repartidores->where('available', 1)->count();
        $totales['couriers_at_limit'] = $repartidores
            ->where('active_deliveries', '>=', (int) Ajustes::valor('operacion.entregas_simultaneas'))
            ->count();

        return response()->json([
            'range'           => $dias,
            'totals'          => $totales,
            'series'          => $resumen->serieDiaria($entregadas, $desde, $dias),
            'orders_by_state' => DB::table('orderssales')
                ->selectRaw('state, COUNT(*) as count')
                ->groupBy('state')
                ->orderBy('state')
                ->get(),
            'top_businesses'  => $resumen->topNegocios($desde),
            'couriers'        => $repartidores->sortByDesc('deliveries')->values(),
            'recent_orders'   => $this->ordenesBase()->orderByDesc('o.sale_date')->limit(10)->get(),
        ] + $resumen->bloquesDelInicio($request));
    }

}
