<?php

namespace App\Http\Controllers\Api;

use App\Exports\Comercials\ReportGeneralComercial;
use App\Exports\Financial\ReportGeneralExport;
use App\Exports\operational\ReportesOperativosExport;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Domiciliary;
use App\Models\OrderSale;
use App\Models\Payment;
use App\Models\Category;
use App\Models\BusinessReview;
use App\Models\DomiciliaryReview;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{

    public function generalFinancial(Request $request)
    {
        $businesses = Business::select('busines_id', 'name')->orderBy('name')->get();
        $domiciliaries = Domiciliary::with('user')->get();

        $business_id = $request->business_id ?? null;
        $domiciliary_id = $request->domiciliary_id ?? null;

        $date_start = $request->date_start
            ? Carbon::parse($request->date_start)
            : Carbon::now()->startOfWeek();

        $date_end = $request->date_end
            ? Carbon::parse($request->date_end)
            : Carbon::now()->endOfWeek();

        // ingresos negocio
        $incomeBusiness = Payment::select(
            DB::raw('DATE(payment_date) as date'),
            DB::raw('SUM(total) as total_income')
        )
            ->when($business_id, fn($q) => $q->whereHas('order', fn($sub) => $sub->where('busines_id', $business_id)))
            ->whereBetween('payment_date', [$date_start, $date_end])
            ->groupBy('date')->orderBy('date')->get();

        // pagos domiciliarios
        $incomeDomiciliary = Payment::select(
            DB::raw('DATE(payment_date) as date'),
            DB::raw('SUM(domicilio) as total_domicilio')
        )
            ->when($domiciliary_id, fn($q) => $q->whereHas('order', fn($sub) => $sub->where('domiciliary_id', $domiciliary_id)))
            ->whereBetween('payment_date', [$date_start, $date_end])
            ->groupBy('date')->orderBy('date')->get();

        // pagos tenderos
        $paymentsToStore = Payment::select(
            DB::raw('DATE(payment_date) as date'),
            DB::raw('SUM(total - domicilio) as total_tendero')
        )
            ->when($business_id, fn($q) => $q->whereHas('order', fn($sub) => $sub->where('busines_id', $business_id)))
            ->whereBetween('payment_date', [$date_start, $date_end])
            ->groupBy('date')->orderBy('date')->get();

        // rentabilidad
        $profitPerOrder = Payment::select(
            'order_sale_id',
            DB::raw('SUM(total - domicilio - valor_promocion) as profit')
        )
            ->when($business_id, fn($q) => $q->whereHas('order', fn($sub) => $sub->where('busines_id', $business_id)))
            ->whereBetween('payment_date', [$date_start, $date_end])
            ->groupBy('order_sale_id')->get();

        return view('admin.Reportes.Financiero.general', compact(
            'businesses',
            'domiciliaries',
            'business_id',
            'domiciliary_id',
            'date_start',
            'date_end',
            'incomeBusiness',
            'incomeDomiciliary',
            'paymentsToStore',
            'profitPerOrder'
        ));
    }

    public function exportFinancial(Request $request)
    {
        $business_id = $request->business_id ?? null;
        $domiciliary_id = $request->domiciliary_id ?? null;
        $date_start = $request->date_start ?? now()->startOfWeek();
        $date_end = $request->date_end ?? now()->endOfWeek();

        return Excel::download(
            new ReportGeneralExport($business_id, $domiciliary_id, $date_start, $date_end),
            'Reporte_Financiero.xlsx'
        );
    }

    public function generalCommercials(Request $request)
    {
        $start = $request->date_start ? Carbon::parse($request->date_start) : now()->subMonth();
        $end = $request->date_end ? Carbon::parse($request->date_end) : now();

        // 1. Número de pedidos por día
        $ordersByDay = OrderSale::whereBetween('sale_date', [$start, $end])
            ->selectRaw('DATE(sale_date) as date, COUNT(*) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // 2. Ticket promedio global
        $avgTicket = OrderSale::whereBetween('sale_date', [$start, $end])
            ->avg('total');

        // 3. Usuarios activos (nuevos vs recurrentes) usando buyer_id
        $buyersIds = OrderSale::whereBetween('sale_date', [$start, $end])
            ->pluck('buyer_id')
            ->unique();

        $totalActiveUsers = $buyersIds->count();

        // Nuevos = buyers cuyo primer pedido está en el periodo
        $newUsers = OrderSale::select('buyer_id')
            ->whereIn('buyer_id', $buyersIds)
            ->groupBy('buyer_id')
            ->havingRaw('MIN(sale_date) BETWEEN ? AND ?', [$start, $end])
            ->count();

        $recurrentUsers = $totalActiveUsers - $newUsers;

        // 4. Tiendas activas vs inactivas
        $businessWithOrders = OrderSale::whereBetween('sale_date', [$start, $end])
            ->distinct('busines_id')
            ->count('busines_id');

        $totalBusinesses = Business::count();
        $inactiveBusinesses = $totalBusinesses - $businessWithOrders;

        // 5. Top tiendas
        $topBusinesses = OrderSale::whereBetween('sale_date', [$start, $end])
            ->selectRaw('busines_id, COUNT(*) as total_orders, SUM(total) as total_amount')
            ->groupBy('busines_id')
            ->orderByDesc('total_orders')
            ->with('business') // asegúrate de que OrderSale tenga relación business()
            ->take(10)
            ->get();

        // 6. Top categorías
        $topCategories = Category::selectRaw('category.name, COUNT(OrderSale_detail.orderDet_id) as total_items')
            ->join('products', 'products.category_id', '=', 'category.category_id')
            ->join('OrderSale_detail', 'OrderSale_detail.product_id', '=', 'products.products_id')
            ->join('OrderSale', 'OrderSale.order_sale_id', '=', 'OrderSale_detail.order_sale_id')
            ->whereBetween('OrderSale.sale_date', [$start, $end])
            ->groupBy('category.name')
            ->orderByDesc('total_items')
            ->take(10)
            ->get();

        // 7. Tasa de repetición de clientes (buyer_id)
        $repeatCustomers = OrderSale::whereBetween('sale_date', [$start, $end])
            ->select('buyer_id')
            ->groupBy('buyer_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $totalCustomers = OrderSale::whereBetween('sale_date', [$start, $end])
            ->distinct('buyer_id')
            ->count('buyer_id');

        $repeatRate = $totalCustomers > 0 ? round(($repeatCustomers / $totalCustomers) * 100, 2) : 0;

        // 8. CAC simple
        $adSpend = 1000000; // valor gasto en publicidad (traer de DB o config)
        $CAC = $totalActiveUsers > 0 ? $adSpend / $totalActiveUsers : 0;

        return view('admin.Reportes.Comercial.general', compact(
            'ordersByDay',
            'avgTicket',
            'newUsers',
            'recurrentUsers',
            'businessWithOrders',
            'inactiveBusinesses',
            'topBusinesses',
            'topCategories',
            'repeatRate',
            'CAC',
            'start',
            'end'
        ));
    }

    public function exportComercial(Request $request)
    {
        $start = $request->date_start ?? now()->subMonth();
        $end = $request->date_end ?? now();

        return Excel::download(new ReportGeneralComercial($start, $end), 'ComercialReporte.xlsx');
    }

    public function OperationalCommercials(Request $request)
    {
        $start = $request->input('start');
        $end = $request->input('end');

        // si vienen fechas, conviértelas a Carbon
        if ($start && $end) {
            $start = \Carbon\Carbon::parse($start)->startOfDay();
            $end = \Carbon\Carbon::parse($end)->endOfDay();
        }

        $filtro = $request->input('filtro', 'pedidos'); // pedidos|negocios|domiciliarios
        $business_id = $request->input('business_id');
        $domiciliary_id = $request->input('domiciliary_id');

        // listas para selects
        $businesses = Business::select('busines_id', 'name', 'latitude', 'longitude')->orderBy('name')->get();
        $domiciliaries = Domiciliary::with('user')->get();


        // Base orders query (aplicable a KPIs cuando haya filtros de negocio/domiciliario)
        $baseOrders = OrderSale::query()
            ->whereBetween('sale_date', [$start, $end])
            ->when($business_id, fn($q) => $q->where('busines_id', $business_id))
            ->when($domiciliary_id, fn($q) => $q->where('domiciliary_id', $domiciliary_id));

        // KPIs
        $totalPedidos = (clone $baseOrders)->count();
        $cancelados = (clone $baseOrders)->where('state', 'cancelado')->count(); // ajusta el valor 'cancelado' si tu estado es otro
        $tasaCancelaciones = $totalPedidos ? round(($cancelados / $totalPedidos) * 100, 2) : 0;

        $satisfaccionNegocios = round(
            BusinessReview::whereBetween('created_at', [$start, $end])
                ->when($business_id, fn($q) => $q->where('busines_id', $business_id))
                ->avg('qualification') ?? 0,
            2
        );

        $satisfaccionDomiciliarios = round(
            DomiciliaryReview::whereBetween('created_at', [$start, $end])
                ->when($domiciliary_id, fn($q) => $q->where('domiciliary_id', $domiciliary_id))
                ->avg('qualification') ?? 0,
            2
        );

        $totalDomiciliarios = Domiciliary::count();
        $disponibles = Domiciliary::where('available', true)->count();

        // --- Coordenadas según filtro ---
        $coordenadas = collect();

        if ($filtro === 'negocios') {
            // negocios (usa lat/lng guardados en business)
            $coordenadas = Business::when($business_id, fn($q) => $q->where('busines_id', $business_id))
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->get()
                ->map(fn($b) => [
                    'lat' => (float) $b->latitude,
                    'lng' => (float) $b->longitude,
                    'label' => $b->name,
                    'type' => 'negocio',
                    'id' => $b->busines_id
                ]);
        } elseif ($filtro === 'domiciliarios') {
            // domiciliarios: intentamos obtener la última geolocalización desde order_geolocation
            // -> tabla asumida: order_geolocation (domiciliary_id, latitude, longitude, created_at)
            try {
                $geoRows = DB::table('order_geolocation')
                    ->select('domiciliary_id', 'latitude', 'longitude', 'created_at')
                    ->when($domiciliary_id, fn($q) => $q->where('domiciliary_id', $domiciliary_id))
                    ->whereBetween('created_at', [$start, $end])
                    ->orderByDesc('created_at')
                    ->get()
                    ->groupBy('domiciliary_id')
                    ->map(fn($rows) => $rows->first());

                $domMap = Domiciliary::with('user')->whereIn('domiciliary_id', $geoRows->keys()->toArray())->get()->keyBy('domiciliary_id');

                foreach ($geoRows as $dId => $row) {
                    if ($row->latitude && $row->longitude) {
                        $label = $domMap[$dId]->user->name ?? "Domiciliario {$dId}";
                        $coordenadas->push([
                            'lat' => (float) $row->latitude,
                            'lng' => (float) $row->longitude,
                            'label' => $label,
                            'type' => 'domiciliario',
                            'id' => $dId
                        ]);
                    }
                }
            } catch (\Exception $e) {
                // si la tabla no existe o hay error, dejamos coordenadas vacío
                $coordenadas = collect();
            }
        } else {
            // pedidos: usamos la dirección relacionada (user_address.latitude/longitude)
            $orders = OrderSale::with(['address', 'business'])
                ->whereBetween('sale_date', [$start, $end])
                ->when($business_id, fn($q) => $q->where('busines_id', $business_id))
                ->when($domiciliary_id, fn($q) => $q->where('domiciliary_id', $domiciliary_id))
                ->get();

            $coordenadas = $orders->map(fn($o) => [
                'lat' => $o->address?->latitude ? (float) $o->address->latitude : null,
                'lng' => $o->address?->longitude ? (float) $o->address->longitude : null,
                'label' => 'Pedido ' . $o->order_sale_id . ($o->business ? ' - ' . $o->business->name : ''),
                'type' => 'pedido',
                'id' => $o->order_sale_id
            ])->filter(fn($p) => $p['lat'] && $p['lng'])->values();
        }

        $businessReviewsList = BusinessReview::with(['business', 'buyer.user'])
            ->when($start && $end, fn($q) => $q->whereBetween('created_at', [$start, $end]))
            ->when($business_id, fn($q) => $q->where('busines_id', $business_id))
            ->orderByDesc('created_at')
            ->get();

        // --- Domiciliary reviews ---
        $domiciliaryReviewsList = DomiciliaryReview::with(['domiciliary.user', 'buyer.user'])
            ->when($start && $end, fn($q) => $q->whereBetween('created_at', [$start, $end]))
            ->when($domiciliary_id, fn($q) => $q->where('domiciliary_id', $domiciliary_id))
            ->orderByDesc('created_at')
            ->get();

        $distBusiness = BusinessReview::select('qualification', DB::raw('count(*) as total'))
            ->whereBetween('created_at', [$start, $end])
            ->when($business_id, fn($q) => $q->where('busines_id', $business_id))
            ->groupBy('qualification')
            ->orderBy('qualification')
            ->pluck('total', 'qualification')
            ->toArray();

        // Distribución de calificaciones domiciliarios
        $distDomiciliary = DomiciliaryReview::select('qualification', DB::raw('count(*) as total'))
            ->whereBetween('created_at', [$start, $end])
            ->when($domiciliary_id, fn($q) => $q->where('domiciliary_id', $domiciliary_id))
            ->groupBy('qualification')
            ->orderBy('qualification')
            ->pluck('total', 'qualification')
            ->toArray();

        // --- Centro dinámico del mapa (fallback a centroid de negocios o coordenada por defecto) ---
        if ($coordenadas->isNotEmpty()) {
            $centerLat = $coordenadas->avg('lat');
            $centerLng = $coordenadas->avg('lng');
        } else {
            // si no hay puntos, intentar centro por negocios
            $biz = Business::whereNotNull('latitude')->whereNotNull('longitude')->get();
            if ($biz->isNotEmpty()) {
                $centerLat = $biz->avg(fn($b) => (float) $b->latitude);
                $centerLng = $biz->avg(fn($b) => (float) $b->longitude);
            } else {
                // fallback Bogotá aprox.
                $centerLat = 4.6;
                $centerLng = -74.08;
            }
        }

        // Pasar variables al blade
        return view('admin.Reportes.Operacional.General', compact(
            'start',
            'end',
            'filtro',
            'business_id',
            'domiciliary_id',
            'businesses',
            'domiciliaries',
            'tasaCancelaciones',
            'satisfaccionNegocios',
            'satisfaccionDomiciliarios',
            'totalDomiciliarios',
            'disponibles',
            'coordenadas',
            'centerLat',
            'centerLng',
            'businessReviewsList',
            'domiciliaryReviewsList',
            'distBusiness',
            'distDomiciliary'
        ));
    }

    public function exportOperational(Request $request)
    {
        $start = $request->input('start');
        $end = $request->input('end');

        return Excel::download(new ReportesOperativosExport($start, $end), 'reporte_operacional.xlsx');
    }
}
