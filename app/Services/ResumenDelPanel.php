<?php

namespace App\Services;

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

/**
 * El tablero de inicio del panel: la serie del periodo, los mejores negocios y los bloques que cada area ve segun sus modulos. Eran 380 de las 497 lineas de AdminApiController, cuyo unico trabajo era responder /admin/overview.
 */
class ResumenDelPanel
{
    use AyudasDeAdmin;

    /** Serie por día con el periodo anterior alineado al mismo índice. */
    public function serieDiaria($entregadas, Carbon $desde, int $dias): array
    {
        $porDia = $entregadas->groupBy(fn($o) => Carbon::parse($o->sale_date)->toDateString());
        $serie = [];

        for ($i = 0; $i < $dias; $i++) {
            $dia = (clone $desde)->addDays($i);
            $diaPrevio = (clone $dia)->subDays($dias);

            $serie[] = [
                'date'         => $dia->toDateString(),
                'label'        => $dia->format('d M'),
                'revenue'      => round(($porDia[$dia->toDateString()] ?? collect())->sum('total'), 2),
                'revenue_prev' => round(($porDia[$diaPrevio->toDateString()] ?? collect())->sum('total'), 2),
                'orders'       => ($porDia[$dia->toDateString()] ?? collect())->count(),
            ];
        }

        return $serie;
    }

    public function topNegocios(Carbon $desde)
    {
        return DB::table('business as b')
            ->leftJoin('orderssales as o', function ($j) use ($desde) {
                $j->on('o.busines_id', '=', 'b.busines_id')
                    ->where('o.state', '=', self::ENTREGADO)
                    ->where('o.sale_date', '>=', $desde);
            })
            ->groupBy('b.busines_id', 'b.name', 'b.logo', 'b.qualification')
            ->orderByDesc(DB::raw('COALESCE(SUM(o.total), 0)'))
            ->limit(8)
            ->get([
                'b.busines_id',
                'b.name',
                'b.logo',
                'b.qualification',
                DB::raw('COUNT(o.orderSales_id) as orders'),
                DB::raw('COALESCE(SUM(o.total), 0) as revenue'),
            ]);
    }

    /* ==================================================================
       USUARIOS
       ================================================================== */

    /** Permisos ya recortados por el nivel de quien pregunta. */
    private function permisosDe(Request $request): array
    {
        $user = $request->user();
        $area = $user && $user->area_id ? Area::find($user->area_id) : null;

        if (!$area || (int) $area->state !== 1) {
            return ['area' => null, 'matriz' => []];
        }

        return [
            'area'   => $area,
            'matriz' => $area->permisos($user->access_level ?? Area::NIVEL_GESTOR),
        ];
    }

    /**
     * Lo que le toca a cada área en el panel de inicio, en su orden.
     *
     * Es lo único de todo esto que sabe de áreas, y a propósito: es una
     * preferencia de presentación, no una regla de seguridad. Un área que no
     * esté acá cae al orden general, que empieza por la operación.
     */
    private const ORDEN_POR_AREA = [
        'gerencia'     => ['dinero', 'comercial', 'operacion', 'marketing', 'calidad', 'sst', 'contabilidad'],
        'contabilidad' => ['contabilidad', 'dinero', 'operacion'],
        'marketing'    => ['marketing', 'dinero', 'comercial'],
        'comercial'    => ['comercial', 'dinero', 'operacion', 'calidad'],
        'sst'          => ['sst', 'operacion'],
        'calidad'      => ['calidad', 'operacion', 'comercial'],
    ];

    private const ORDEN_POR_DEFECTO = [
        'dinero', 'operacion', 'contabilidad', 'comercial', 'marketing', 'sst', 'calidad',
    ];

    /**
     * Bloques del inicio para quien pregunta.
     *
     * Cada bloque se calcula SOLO si su permiso lo autoriza: los que no
     * corresponden ni se consultan, así que un auxiliar de SST no paga el
     * costo de agregar el reporte financiero que después no vería.
     */
    public function bloquesDelInicio(Request $request): array
    {
        ['area' => $area, 'matriz' => $matriz] = $this->permisosDe($request);

        $ve = fn (string ...$modulos) => (bool) array_filter(
            $modulos,
            fn ($m) => ($matriz[$m]['view'] ?? false) === true,
        );

        $bloques = [];

        if ($ve('liquidaciones', 'pagos')) {
            $bloques['contabilidad'] = $this->bloqueContabilidad($ve('liquidaciones'), $ve('pagos'));
        }

        if ($ve('sst.documentos', 'sst.incidentes')) {
            $bloques['sst'] = $this->bloqueSst();
        }

        if ($ve('pqrs', 'resenas')) {
            $bloques['calidad'] = $this->bloqueCalidad($ve('pqrs'), $ve('resenas'));
        }

        if ($ve('marketing', 'marketing.banners', 'marketing.cupones')) {
            $bloques['marketing'] = $this->bloqueMarketing();
        }

        if ($ve('negocios', 'productos')) {
            $bloques['comercial'] = $this->bloqueComercial($ve('negocios'), $ve('productos'));
        }

        return [
            'focus'  => $area?->code,
            'order'  => self::ORDEN_POR_AREA[$area?->code] ?? self::ORDEN_POR_DEFECTO,
            'blocks' => $bloques,
        ];
    }

    /* -------------------------- CONTABILIDAD --------------------------- */

    private function bloqueContabilidad(bool $veLiquidaciones, bool $vePagos): array
    {
        $datos = [];

        if ($veLiquidaciones) {
            $porEstado = DB::table('settlements')
                ->selectRaw('state, COUNT(*) as n, COALESCE(SUM(net_payable), 0) as monto')
                ->groupBy('state')
                ->get()
                ->keyBy('state');

            $datos += [
                // Un corte aprobado y sin pagar es plata que se le debe a
                // alguien: es la cifra que Contabilidad viene a mirar.
                'settlements_draft'    => (int) ($porEstado[0]->n ?? 0),
                'settlements_approved' => (int) ($porEstado[1]->n ?? 0),
                'payable'              => round((float) ($porEstado[1]->monto ?? 0), 2),
                'paid_amount'          => round((float) ($porEstado[2]->monto ?? 0), 2),
            ];
        }

        if ($vePagos) {
            $datos += [
                'payments_rejected' => DB::table('payments')
                    ->whereIn('status', ['rejected', 'failed'])
                    ->where('payment_date', '>=', Carbon::now()->subDays(30))
                    ->count(),
                // Contra `payments` y no contra la bandera denormalizada del
                // pedido, por lo mismo que el resto del panel.
                'unpaid_orders' => DB::table('orderssales as o')
                    ->whereIn('o.state', self::ACTIVOS)
                    ->whereNotExists(fn ($q) => $this->pagoAprobado($q))
                    ->count(),
            ];
        }

        return $datos;
    }

    /* ------------------------------- SST ------------------------------- */

    private function bloqueSst(): array
    {
        $hoy   = Carbon::today()->toDateString();
        $aviso = Carbon::today()->addDays(DomiciliaryDocument::AVISO_DIAS)->toDateString();

        $activos = DB::table('domiciliary')->where('state', 1)->count();

        // Cuántos obligatorios vigentes tiene cada repartidor. Le falta la
        // documentación a quien no llega a los cinco.
        $completos = DB::table('domiciliary_documents')
            ->where('state', 1)
            ->whereIn('type', DomiciliaryDocument::OBLIGATORIOS)
            ->whereDate('expires_at', '>=', $hoy)
            ->groupBy('domiciliary_id')
            ->havingRaw('COUNT(DISTINCT type) = ?', [count(DomiciliaryDocument::OBLIGATORIOS)])
            ->pluck('domiciliary_id')
            ->count();

        return [
            'expired' => DB::table('domiciliary_documents')
                ->where('state', 1)->whereDate('expires_at', '<', $hoy)->count(),
            'expiring' => DB::table('domiciliary_documents')
                ->where('state', 1)
                ->whereDate('expires_at', '>=', $hoy)
                ->whereDate('expires_at', '<=', $aviso)
                ->count(),
            'couriers_active'     => $activos,
            'couriers_incomplete' => max(0, $activos - $completos),
            'without_contract'    => DB::table('domiciliary')
                ->where('state', 1)->whereNull('contract_signed_at')->count(),
            'incidents_open' => DB::table('safety_incidents')
                ->whereIn('state', [0, 1])->count(),
            'incidents_serious' => DB::table('safety_incidents')
                ->whereIn('state', [0, 1])->where('severity', 'grave')->count(),
            'days_off' => (int) DB::table('safety_incidents')
                ->where('occurred_at', '>=', Carbon::now()->subDays(90))
                ->sum('days_off'),
        ];
    }

    /* ----------------------------- CALIDAD ----------------------------- */

    private function bloqueCalidad(bool $vePqrs, bool $veResenas): array
    {
        $datos = [];

        if ($vePqrs) {
            $abiertas = DB::table('pqrs')->whereIn('state', [0, 1]);

            $datos += [
                'pqrs_open' => (clone $abiertas)->count(),
                // Fuera de plazo: es la única cifra de esta pantalla que
                // significa que alguien está esperando de más ahora mismo.
                'pqrs_overdue' => (clone $abiertas)
                    ->whereNotNull('due_at')
                    ->where('due_at', '<', Carbon::now())
                    ->count(),
                'pqrs_resolved_30d' => DB::table('pqrs')
                    ->whereIn('state', [2, 3])
                    ->where('resolved_at', '>=', Carbon::now()->subDays(30))
                    ->count(),
            ];
        }

        if ($veResenas) {
            $desde = Carbon::now()->subDays(30);

            $negocios = DB::table('business_reviews')
                ->where('created_at', '>=', $desde)->where('qualification', '<=', 2)->count();
            $repartidores = DB::table('domiciliary_reviews')
                ->where('created_at', '>=', $desde)->where('qualification', '<=', 2)->count();

            $datos['negative_reviews_30d'] = $negocios + $repartidores;
        }

        return $datos;
    }

    /* ---------------------------- MARKETING ---------------------------- */

    private function bloqueMarketing(): array
    {
        $hoy   = Carbon::today()->toDateString();
        $desde = Carbon::today()->subDays(29)->toDateString();

        $eventos = DB::table('banner_events')
            ->where('day', '>=', $desde)
            ->selectRaw("SUM(CASE WHEN type = 'impression' THEN 1 ELSE 0 END) as impresiones")
            ->selectRaw("SUM(CASE WHEN type = 'click' THEN 1 ELSE 0 END) as clics")
            ->first();

        $impresiones = (int) ($eventos->impresiones ?? 0);
        $clics       = (int) ($eventos->clics ?? 0);

        return [
            'campaigns_live' => DB::table('ad_campaigns')
                ->where('state', 1)
                ->whereDate('starts_at', '<=', $hoy)
                ->whereDate('ends_at', '>=', $hoy)
                ->count(),
            'banners_live' => DB::table('banners as b')
                ->join('ad_campaigns as c', 'c.id', '=', 'b.campaign_id')
                ->where('b.state', 1)->where('c.state', 1)
                ->whereDate('c.starts_at', '<=', $hoy)
                ->whereDate('c.ends_at', '>=', $hoy)
                ->count(),
            // Una pieza sin imagen se guarda bien y no se muestra: el fallo
            // más fácil de cometer y el más difícil de notar.
            'banners_without_image' => DB::table('banners as b')
                ->where('b.state', 1)
                ->whereNotExists(fn ($q) => $q->from('media_files as m')
                    ->whereColumn('m.entity_id', 'b.id')
                    ->where('m.entity_type', 'banners'))
                ->count(),
            'impressions_30d' => $impresiones,
            'clicks_30d'      => $clics,
            'ctr_30d'         => $impresiones > 0 ? round($clics / $impresiones * 100, 2) : null,
            'coupons_expiring' => DB::table('coupons')
                ->where('state', 1)
                ->whereDate('ends_at', '>=', $hoy)
                ->whereDate('ends_at', '<=', Carbon::today()->addDays(7)->toDateString())
                ->count(),
            'featured_expiring' => DB::table('featured_businesses')
                ->where('state', 1)
                ->whereDate('ends_at', '>=', $hoy)
                ->whereDate('ends_at', '<=', Carbon::today()->addDays(7)->toDateString())
                ->count(),
        ];
    }

    /* ---------------------------- COMERCIAL ---------------------------- */

    private function bloqueComercial(bool $veNegocios, bool $veProductos): array
    {
        $datos = [];

        if ($veNegocios) {
            $datos += [
                'businesses_total'   => DB::table('business')->count(),
                'businesses_hidden'  => DB::table('business')->where('state', '!=', 1)->count(),
                // Sin coordenadas no entra en el mapa de entregas; sin tipo no
                // aparece en ningún carrusel de la app. Dos formas de estar
                // dado de alta y ser invisible.
                'without_location' => DB::table('business')
                    ->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude')
                        ->orWhere('latitude', 0)->orWhere('longitude', 0))
                    ->count(),
                'without_type' => DB::table('business')->whereNull('type')->count(),
                'owners_without_business' => DB::table('owner as o')
                    ->whereNotExists(fn ($q) => $q->from('owner_busines as ob')
                        ->whereColumn('ob.owner_id', 'o.owner_id'))
                    ->count(),
                /*
                 * Y la dirección contraria, que es la que rompe algo.
                 *
                 * Se contaba el propietario sin negocio —molesto— y no el
                 * negocio sin propietario, que es el que NADIE puede
                 * administrar: ni desde el panel de aliados ni desde la app,
                 * porque las dos resuelven la tienda por `owner_busines`.
                 * Crearlo desde Catálogo → Negocios no crea ese vínculo; se
                 * ata desde Propietarios, y hasta que alguien lo haga la
                 * tienda está dada de alta y muda.
                 */
                'businesses_without_owner' => DB::table('business as b')
                    ->whereNotExists(fn ($q) => $q->from('owner_busines as ob')
                        ->whereColumn('ob.busines_id', 'b.busines_id'))
                    ->count(),
            ];
        }

        if ($veProductos) {
            $datos += [
                'products_total'      => DB::table('products')->count(),
                'products_no_image'   => DB::table('products')
                    ->where(fn ($q) => $q->whereNull('image')->orWhere('image', ''))
                    ->count(),
                'products_out_of_stock' => DB::table('products_business')->where('amount', 0)->count(),
                'categories_unassigned' => DB::table('category as c')
                    ->whereNotExists(fn ($q) => $q->from('category_category_business as ccb')
                        ->whereColumn('ccb.category_id', 'c.category_id'))
                    ->count(),
            ];
        }

        return $datos;
    }

    /* ==================================================================
       REPORTES

       Cuatro miradas del mismo periodo. Tres cosas las gobiernan y valen para
       las cuatro:

       1. La VENTANA puede ser relativa ("últimos 30 días") o dos fechas
          concretas. Con solo la relativa no se podía cuadrar un mes cerrado
          contra su liquidación, que es para lo que Contabilidad abre esto.

       2. Toda cifra viene con la del PERIODO ANTERIOR de igual tamaño. Un
          ingreso de $3,3 M no dice si el mes fue bueno; al lado de los $2,9 M
          del mes pasado, sí. Es la diferencia entre un dato y una decisión.

       3. Los FILTROS acotan por negocio, municipio o tipo de negocio. Antes
          todo era agregado nacional y no había forma de responder "¿cómo va
          La Esquina?" ni "¿cómo va Soledad?", que es lo que pregunta
          Comercial.
       ================================================================== */


    /**
     * Rango en días, acotado para que nadie pida un año de golpe por error.
     *
     * Lo usa el panel de inicio, que sigue trabajando con ventanas relativas:
     * los reportes tienen su propia `ventanaDelReporte()` porque además
     * admiten dos fechas concretas.
     */
    public function rango(Request $request): int
    {
        $dias = (int) $request->query('range', 30);

        return max(1, min($dias, 365));
    }
}
