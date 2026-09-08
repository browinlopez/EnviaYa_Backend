<?php

namespace App\Services;

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

/**
 * Los cuatro reportes del panel: financiero, comercial, operacional y por negocio. Eran 475 de las 587 lineas del controlador, que solo tenia que leer la peticion y responder. Aca se pueden probar sin levantar una peticion HTTP, y el libro de Excel puede pedir las mismas cifras en vez de repetir las consultas.
 */
class GeneradorDeReportes
{
    /**
     * `orderssales.state` = 4. Un reporte cuenta lo ENTREGADO: un pedido en
     * curso todavia puede cancelarse, y sumarlo inflaria las cifras del dia.
     *
     * Se declara aca y no se importa del controlador a proposito: el servicio
     * tiene que poder usarse sin el.
     */
    private const ENTREGADO = 4;

    /* ------------------------- VENTANA Y FILTROS ----------------------- */

    /**
     * El periodo que se pide y el inmediatamente anterior de igual tamaño.
     *
     * `from`/`to` mandan sobre `range`: quien escribe dos fechas quiere esas
     * dos fechas. Si vienen al revés se enderezan en vez de devolver un
     * periodo vacío — es un error de dedo, no una consulta legítima.
     *
     * @return array{desde:Carbon, hasta:Carbon, dias:int, desde_antes:Carbon, hasta_antes:Carbon}
     */
    public function ventanaDelReporte(Request $request): array
    {
        $desde = $this->fechaValida($request->query('from'));
        $hasta = $this->fechaValida($request->query('to'));

        if ($desde && $hasta) {
            if ($desde->gt($hasta)) {
                [$desde, $hasta] = [$hasta, $desde];
            }
        } else {
            $dias  = max(1, min((int) $request->query('range', 30), 365));
            $hasta = Carbon::today();
            $desde = $hasta->copy()->subDays($dias - 1);
        }

        // Un año y un día de margen: pedir cinco años de golpe tumba la
        // consulta y casi siempre es un cero de más en el formulario.
        $dias = min($desde->diffInDays($hasta) + 1, 366);
        $desde = $hasta->copy()->subDays($dias - 1);

        return [
            'desde'       => $desde->copy()->startOfDay(),
            'hasta'       => $hasta->copy()->endOfDay(),
            'dias'        => $dias,
            'desde_antes' => $desde->copy()->subDays($dias)->startOfDay(),
            'hasta_antes' => $desde->copy()->subDay()->endOfDay(),
        ];
    }

    private function fechaValida(?string $valor): ?Carbon
    {
        if (!$valor) {
            return null;
        }

        try {
            return Carbon::parse($valor)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{business_id:?int, municipality_id:?int, business_category:?int, complex_id:?int}
     */
    public function filtrosDelReporte(Request $request): array
    {
        $entero = fn ($v) => ($v === null || $v === '' || (int) $v <= 0) ? null : (int) $v;

        return [
            'business_id'       => $entero($request->query('business_id')),
            'municipality_id'   => $entero($request->query('municipality_id')),
            'business_category' => $entero($request->query('business_category')),
            'complex_id'        => $entero($request->query('complex_id')),
        ];
    }

    /**
     * Los filtros con su nombre, para que la pantalla y el CSV puedan decir
     * qué se está mirando. Un reporte filtrado que no dice que lo está es la
     * forma más fácil de sacar una conclusión equivocada.
     */
    public function filtrosLegibles(array $f): array
    {
        return array_values(array_filter([
            $f['business_id'] ? [
                'key'   => 'business_id',
                'label' => 'Negocio',
                'value' => DB::table('business')->where('busines_id', $f['business_id'])->value('name'),
            ] : null,
            $f['municipality_id'] ? [
                'key'   => 'municipality_id',
                'label' => 'Municipio',
                'value' => DB::table('municipalities')->where('id', $f['municipality_id'])->value('name'),
            ] : null,
            $f['business_category'] ? [
                'key'   => 'business_category',
                'label' => 'Tipo de negocio',
                'value' => DB::table('category_business')->where('id', $f['business_category'])->value('name'),
            ] : null,
            $f['complex_id'] ? [
                'key'   => 'complex_id',
                'label' => 'Conjunto',
                'value' => DB::table('residential_complexes')
                    ->where('complex_id', $f['complex_id'])->value('name'),
            ] : null,
        ]));
    }

    /**
     * Pedidos del periodo con los filtros aplicados.
     *
     * El join contra `business` solo se agrega si algún filtro lo necesita:
     * cargarlo siempre haría más lenta la consulta más común, que es la que no
     * filtra por nada.
     */
    private function pedidosDelPeriodo(array $v, array $f, bool $anterior = false)
    {
        $q = DB::table('orderssales as o')->whereBetween('o.sale_date', $anterior
            ? [$v['desde_antes'], $v['hasta_antes']]
            : [$v['desde'], $v['hasta']]);

        if ($f['business_id']) {
            $q->where('o.busines_id', $f['business_id']);
        }

        if ($f['municipality_id'] || $f['business_category']) {
            $q->join('business as b', 'b.busines_id', '=', 'o.busines_id');

            if ($f['municipality_id']) {
                $q->where('b.municipality_id', $f['municipality_id']);
            }

            if ($f['business_category']) {
                $q->where('b.type', $f['business_category']);
            }
        }

        /*
         * CONJUNTO RESIDENCIAL.
         *
         * No es un `where` más: `orderssales` no tiene columna de conjunto. Se
         * llega por el comprador, `o.buyer_id → buyer_complex`.
         *
         * Alias propio (`bcx`) y no `b`, que ya lo usa el join CONDICIONAL de
         * arriba, ni `neg`/`d`, que los usan los generadores. Reutilizar un
         * alias acá haría que el reporte fallara solo cuando se combinan dos
         * filtros, que es el tipo de error que aparece en producción.
         *
         * Se usa EXISTS y no un join para no multiplicar filas: `buyer_complex`
         * no tiene índice único, así que un comprador con la pareja duplicada
         * contaría su pedido dos veces y el total saldría inflado.
         */
        if ($f['complex_id']) {
            $q->whereExists(function ($sub) use ($f) {
                $sub->from('buyer_complex as bcx')
                    ->whereColumn('bcx.buyer_id', 'o.buyer_id')
                    ->where('bcx.complex_id', $f['complex_id'])
                    ->selectRaw('1');
            });
        }

        return $q;
    }

    /**
     * Minutos entre dos marcas de tiempo, en el dialecto del motor.
     *
     * `TIMESTAMPDIFF` es de MySQL y las pruebas corren sobre SQLite: sin esto,
     * el reporte funciona en producción y revienta en la única parte donde se
     * comprobaría que funciona.
     */
    private function expresionMinutos(string $a, string $b): string
    {
        $driver = DB::connection()->getDriverName();

        return in_array($driver, ['mysql', 'mariadb'], true)
            ? "TIMESTAMPDIFF(MINUTE, {$a}, {$b})"
            : "(julianday({$b}) - julianday({$a})) * 1440";
    }

    /* ---------------------------- FINANCIERO --------------------------- */

    public function reporteFinanciero(array $v, array $f): array
    {
        $totales = fn (bool $antes) => $this->cifrasFinancieras(
            $this->pedidosDelPeriodo($v, $f, $antes)
                ->where('o.state', self::ENTREGADO)
                ->get(['o.total', 'o.subtotal', 'o.domicilio', 'o.domiciliary_fee', 'o.discount', 'o.sale_date'])
        );

        $actual = $this->pedidosDelPeriodo($v, $f)
            ->where('o.state', self::ENTREGADO)
            ->get(['o.total', 'o.subtotal', 'o.domicilio', 'o.domiciliary_fee', 'o.discount', 'o.sale_date']);

        $porDia = $actual->groupBy(fn ($o) => Carbon::parse($o->sale_date)->toDateString());

        $serie = [];
        for ($d = $v['desde']->copy(); $d->lte($v['hasta']); $d->addDay()) {
            $delDia = $porDia[$d->toDateString()] ?? collect();
            $serie[] = [
                'date'     => $d->toDateString(),
                'label'    => $d->format('d M'),
                'revenue'  => round($delDia->sum('total'), 2),
                'delivery' => round($delDia->sum('domicilio'), 2),
                'orders'   => $delDia->count(),
            ];
        }

        return [
            'totals'   => $this->cifrasFinancieras($actual),
            'previous' => $totales(true),
            'series'   => $serie,
        ];
    }

    private function cifrasFinancieras($pedidos): array
    {
        $domicilios = $pedidos->sum('domicilio');
        $comisiones = $pedidos->sum('domiciliary_fee');

        return [
            'orders'           => $pedidos->count(),
            'revenue'          => round($pedidos->sum('total'), 2),
            'subtotal'         => round($pedidos->sum('subtotal'), 2),
            'delivery_fees'    => round($domicilios, 2),
            'courier_earnings' => round($comisiones, 2),
            'discounts'        => round($pedidos->sum('discount'), 2),
            // Lo que queda para la plataforma y el negocio una vez descontada
            // la comisión del domiciliario.
            'business_net'     => round($pedidos->sum('subtotal') + ($domicilios - $comisiones), 2),
            'avg_ticket'       => $pedidos->count()
                ? round($pedidos->sum('total') / $pedidos->count(), 2)
                : 0,
        ];
    }

    /* ---------------------------- COMERCIAL ---------------------------- */

    public function reporteComercial(array $v, array $f): array
    {
        $detalle = fn (bool $antes) => $this->pedidosDelPeriodo($v, $f, $antes)
            ->join('orderssales_detail as od', 'od.orderSales_id', '=', 'o.orderSales_id')
            ->leftJoin('products as p', 'p.products_id', '=', 'od.product_id')
            ->where('o.state', self::ENTREGADO);

        $productos = $detalle(false)
            ->groupBy('p.products_id', 'p.name')
            ->orderByDesc(DB::raw('SUM(od.amount)'))
            ->get([
                'p.products_id',
                'p.name',
                DB::raw('SUM(od.amount) as sold'),
                DB::raw('SUM(od.amount * od.unit_price) as revenue'),
            ]);

        $categorias = $detalle(false)
            ->leftJoin('category as c', 'c.category_id', '=', 'p.category_id')
            ->groupBy('c.category_id', 'c.name')
            ->orderByDesc(DB::raw('SUM(od.amount * od.unit_price)'))
            ->get([
                DB::raw("COALESCE(c.name, 'Sin categoría') as name"),
                DB::raw('SUM(od.amount) as units'),
                DB::raw('SUM(od.amount * od.unit_price) as revenue'),
            ]);

        $cifras = function (bool $antes) use ($v, $f, $detalle) {
            $filas = $detalle($antes)->get([
                DB::raw('SUM(od.amount) as units'),
                DB::raw('COUNT(DISTINCT od.product_id) as refs'),
            ])->first();

            $pedidos = $this->pedidosDelPeriodo($v, $f, $antes)
                ->where('o.state', self::ENTREGADO)
                ->count();

            $unidades = (int) ($filas->units ?? 0);

            return [
                'units'             => $unidades,
                'distinct_products' => (int) ($filas->refs ?? 0),
                'orders'            => $pedidos,
                'units_per_order'   => $pedidos ? round($unidades / $pedidos, 1) : 0,
            ];
        };

        return [
            'totals'   => $cifras(false) + ['catalog_size' => DB::table('products')->count()],
            'previous' => $cifras(true),
            'top_products' => $productos->take(10)->values(),
            'by_category'  => $categorias,
        ];
    }

    /* --------------------------- OPERACIONAL --------------------------- */

    public function reporteOperacional(array $v, array $f): array
    {
        $columnas = ['o.orderSales_id', 'o.state', 'o.sale_date', 'o.dispatched_at',
                     'o.delivery_date', 'o.promised_minutes'];

        $ordenes = $this->pedidosDelPeriodo($v, $f)->get($columnas);
        $antes   = $this->pedidosDelPeriodo($v, $f, true)->get($columnas);

        $porDia = $ordenes->groupBy(fn ($o) => Carbon::parse($o->sale_date)->toDateString());

        $serie = [];
        for ($d = $v['desde']->copy(); $d->lte($v['hasta']); $d->addDay()) {
            $delDia = $porDia[$d->toDateString()] ?? collect();
            $serie[] = [
                'date'      => $d->toDateString(),
                'label'     => $d->format('d M'),
                'delivered' => $delDia->where('state', self::ENTREGADO)->count(),
                'cancelled' => $delDia->where('state', '!=', self::ENTREGADO)->count(),
            ];
        }

        $minutos = $this->expresionMinutos('o.dispatched_at', 'o.delivery_date');

        $repartidores = $this->pedidosDelPeriodo($v, $f)
            ->join('domiciliary as d', 'd.domiciliary_id', '=', 'o.domiciliary_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->where('o.state', self::ENTREGADO)
            ->groupBy('d.domiciliary_id', 'u.name')
            ->orderByDesc(DB::raw('COUNT(o.orderSales_id)'))
            ->get([
                'd.domiciliary_id',
                'u.name',
                DB::raw('COUNT(o.orderSales_id) as delivered'),
                DB::raw('COALESCE(SUM(o.domicilio), 0) as delivery_fees'),
                DB::raw('COALESCE(SUM(o.domiciliary_fee), 0) as earnings'),
                DB::raw("AVG(CASE WHEN o.dispatched_at IS NOT NULL AND o.delivery_date IS NOT NULL
                          THEN {$minutos} END) as avg_minutes"),
                /*
                 * Cumplimiento del plazo. Se mide contra `promised_minutes`,
                 * que quedó congelado en el pedido al despacharlo, y no contra
                 * el ajuste de hoy: cambiar el estándar en el panel no debe
                 * reescribir el rendimiento de nadie.
                 *
                 * `measured` cuenta solo las entregas que se PUEDEN juzgar —con
                 * las dos marcas y con plazo—; las anteriores al compromiso no
                 * cuentan ni a favor ni en contra, así que el porcentaje sale
                 * sobre esa base y no sobre el total entregado.
                 */
                DB::raw("SUM(CASE WHEN o.dispatched_at IS NOT NULL AND o.delivery_date IS NOT NULL
                          AND o.promised_minutes IS NOT NULL THEN 1 ELSE 0 END) as measured"),
                DB::raw("SUM(CASE WHEN o.dispatched_at IS NOT NULL AND o.delivery_date IS NOT NULL
                          AND o.promised_minutes IS NOT NULL
                          AND {$minutos} <= o.promised_minutes THEN 1 ELSE 0 END) as on_time"),
            ]);

        $repartidores = $repartidores->map(function ($r) {
            $r->avg_minutes  = $r->avg_minutes === null ? null : round((float) $r->avg_minutes, 1);
            $r->measured     = (int) $r->measured;
            $r->on_time      = (int) $r->on_time;
            $r->late         = $r->measured - $r->on_time;
            // Null y no 0 cuando no hay nada medido: "0 % a tiempo" y "todavía
            // no se le puede medir" son cosas muy distintas para quien lo lee.
            $r->on_time_rate = $r->measured
                ? round(($r->on_time / $r->measured) * 100, 1)
                : null;

            return $r;
        });

        return [
            'totals'   => $this->cifrasOperacionales($ordenes, $repartidores->count()),
            'previous' => $this->cifrasOperacionales($antes, null),
            'series'   => $serie,
            'couriers' => $repartidores,
        ];
    }

    private function cifrasOperacionales($ordenes, ?int $repartidores): array
    {
        $entregadas = $ordenes->where('state', self::ENTREGADO);

        // Solo cuentan los pedidos con las dos marcas: estimar las que faltan
        // inventaría datos.
        $minutos = $entregadas
            ->filter(fn ($o) => $o->dispatched_at && $o->delivery_date)
            ->map(fn ($o) => Carbon::parse($o->dispatched_at)->diffInMinutes(Carbon::parse($o->delivery_date)));

        // Cumplimiento del plazo en el periodo, sobre las entregas que se
        // pueden juzgar (con las dos marcas y con plazo prometido).
        $medibles = $entregadas->filter(
            fn ($o) => $o->dispatched_at && $o->delivery_date && $o->promised_minutes
        );

        $aTiempo = $medibles->filter(
            fn ($o) => Carbon::parse($o->dispatched_at)->diffInMinutes(Carbon::parse($o->delivery_date))
                <= $o->promised_minutes
        );

        return [
            'delivered'          => $entregadas->count(),
            'total_orders'       => $ordenes->count(),
            'avg_minutes'        => $minutos->count() ? round($minutos->avg(), 1) : null,
            'measured'           => $medibles->count(),
            'on_time'            => $aTiempo->count(),
            'on_time_rate'       => $medibles->count()
                ? round(($aTiempo->count() / $medibles->count()) * 100, 1)
                : null,
            'fulfillment_rate'   => $ordenes->count()
                ? round(($entregadas->count() / $ordenes->count()) * 100, 1)
                : 0,
            'orders_per_courier' => $repartidores
                ? round($entregadas->count() / $repartidores, 1)
                : null,
        ];
    }

    /* -------------------------- POR NEGOCIO ---------------------------- */

    /**
     * Qué aporta cada tienda.
     *
     * Es el reporte que faltaba: los otros tres agregan la plataforma entera y
     * respondían "cómo vamos", no "quién nos está sosteniendo y quién está
     * flojo". Los pedidos cancelados van al lado de los entregados a propósito
     * — un negocio con mucha venta y un 30 % de cancelación no es un buen
     * negocio, y con solo la columna de ingresos lo parecía.
     */
    public function reportePorNegocio(array $v, array $f): array
    {
        $filas = $this->pedidosDelPeriodo($v, $f)
            ->join('business as neg', 'neg.busines_id', '=', 'o.busines_id')
            ->leftJoin('municipalities as m', 'm.id', '=', 'neg.municipality_id')
            ->leftJoin('category_business as cb', 'cb.id', '=', 'neg.type')
            ->groupBy('neg.busines_id', 'neg.name', 'neg.qualification', 'm.name', 'cb.name')
            ->get([
                'neg.busines_id',
                'neg.name',
                'neg.qualification',
                DB::raw('m.name as municipality'),
                DB::raw('cb.name as type_name'),
                DB::raw('COUNT(o.orderSales_id) as orders'),
                DB::raw('SUM(CASE WHEN o.state = ' . self::ENTREGADO . ' THEN 1 ELSE 0 END) as delivered'),
                DB::raw('SUM(CASE WHEN o.state = 5 THEN 1 ELSE 0 END) as cancelled'),
                DB::raw('COALESCE(SUM(CASE WHEN o.state = ' . self::ENTREGADO . ' THEN o.total ELSE 0 END), 0) as revenue'),
                DB::raw('COALESCE(SUM(CASE WHEN o.state = ' . self::ENTREGADO . ' THEN o.subtotal ELSE 0 END), 0) as subtotal'),
                DB::raw('COUNT(DISTINCT o.buyer_id) as buyers'),
            ])
            ->map(function ($n) {
                $entregados = (int) $n->delivered;

                $n->revenue     = round((float) $n->revenue, 2);
                $n->subtotal    = round((float) $n->subtotal, 2);
                $n->avg_ticket  = $entregados ? round($n->revenue / $entregados, 2) : 0;
                $n->cancel_rate = (int) $n->orders
                    ? round(((int) $n->cancelled / (int) $n->orders) * 100, 1)
                    : 0;

                return $n;
            })
            ->sortByDesc('revenue')
            ->values();

        $ingresoAnterior = (float) $this->pedidosDelPeriodo($v, $f, true)
            ->where('o.state', self::ENTREGADO)
            ->sum('o.total');

        $activos = $filas->where('orders', '>', 0)->count();

        return [
            'totals' => [
                'businesses'  => $filas->count(),
                'active'      => $activos,
                'revenue'     => round($filas->sum('revenue'), 2),
                'orders'      => (int) $filas->sum('orders'),
                'cancelled'   => (int) $filas->sum('cancelled'),
                // Cuánto del total aporta la tienda que más vende. Una
                // plataforma donde un solo negocio hace el 70 % no tiene un
                // buen mes: tiene un riesgo.
                'top_share'   => $filas->sum('revenue') > 0
                    ? round(((float) ($filas->first()->revenue ?? 0)) / $filas->sum('revenue') * 100, 1)
                    : 0,
            ],
            'previous' => [
                'revenue' => round($ingresoAnterior, 2),
            ],
            'businesses' => $filas,
        ];
    }
}
