<?php

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Models\Marketing\AdCampaign;
use App\Models\Marketing\Advertiser;
use App\Models\Marketing\Banner;
use App\Models\Marketing\Coupon;
use App\Models\Marketing\FeaturedBusiness;
use App\Models\Marketing\PushCampaign;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SUPERFICIE DE MARKETING PARA EL PANEL
 *
 * Va en su propio controlador y no dentro de AdminApiController porque ese ya
 * pasa de las mil quinientas líneas y sirve a otro dominio: mezclar la pauta
 * con el catálogo obligaría a leer todo el archivo para encontrar cualquier
 * cosa. Se registra bajo el mismo prefijo `admin` y el mismo middleware de
 * rol 4, así que desde afuera es la misma API.
 *
 * A diferencia de AdminApiController, acá se usa Eloquent y no el constructor
 * de consultas: los banners y las notificaciones guardan segmentación en
 * columnas JSON y vigencias con reglas propias, y los casts y scopes de los
 * modelos evitan repetir esa lógica en cada método. Donde solo hay que agregar
 * y contar —el resumen, las métricas— se baja a `DB::table`, que para eso es
 * más directo.
 */
class MarketingApiController extends Controller
{
    public function __construct(private readonly MediaService $medios)
    {
    }

    /* ==================================================================
       RESUMEN
       ================================================================== */

    /**
     * KPIs del módulo. `range` en días, como el resto del panel.
     */
    public function overview(Request $request)
    {
        $dias  = max(1, min(365, (int) $request->query('range', 30)));
        $desde = now()->subDays($dias)->toDateString();
        $hoy   = now()->toDateString();

        $eventos = DB::table('banner_events')
            ->where('day', '>=', $desde)
            ->selectRaw("SUM(CASE WHEN type = 'impression' THEN 1 ELSE 0 END) as impresiones")
            ->selectRaw("SUM(CASE WHEN type = 'click' THEN 1 ELSE 0 END) as clics")
            ->first();

        $impresiones = (int) ($eventos->impresiones ?? 0);
        $clics       = (int) ($eventos->clics ?? 0);

        /*
         * Ingreso comprometido, no facturado: suma lo pactado en las campañas
         * y los destaques vigentes. Se nombra así a propósito para que nadie
         * lo confunda con caja: que una campaña esté activa no significa que
         * el anunciante ya haya pagado.
         */
        $comprometido = (float) AdCampaign::where('state', AdCampaign::ACTIVA)->sum('budget')
            + (float) FeaturedBusiness::vigente()->sum('paid_amount');

        $canjes = DB::table('coupon_redemptions')
            ->whereDate('created_at', '>=', $desde)
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(discount), 0) as descontado')
            ->first();

        return response()->json([
            'range' => $dias,
            'totals' => [
                'advertisers'       => Advertiser::where('state', 1)->count(),
                'campaigns_active'  => AdCampaign::where('state', AdCampaign::ACTIVA)->count(),
                'banners_live'      => Banner::entregable()->count(),
                'impressions'       => $impresiones,
                'clicks'            => $clics,
                'ctr'               => $impresiones > 0 ? round($clics / $impresiones * 100, 2) : 0,
                'committed_revenue' => round($comprometido, 2),
                'coupons_active'    => Coupon::vigente()->count(),
                'redemptions'       => (int) ($canjes->total ?? 0),
                'discount_given'    => round((float) ($canjes->descontado ?? 0), 2),
                'featured_live'     => FeaturedBusiness::vigente()->count(),
            ],
            // Serie diaria para la gráfica. Se rellenan los días sin eventos:
            // una serie con huecos dibuja una línea que salta y miente sobre
            // la tendencia.
            'series' => $this->serieDiaria($desde, $hoy),
            'top_banners' => $this->mejoresBanners(5),
        ]);
    }

    private function serieDiaria(string $desde, string $hasta): array
    {
        $filas = DB::table('banner_events')
            ->where('day', '>=', $desde)
            ->groupBy('day')
            ->orderBy('day')
            ->get([
                'day',
                DB::raw("SUM(CASE WHEN type = 'impression' THEN 1 ELSE 0 END) as impressions"),
                DB::raw("SUM(CASE WHEN type = 'click' THEN 1 ELSE 0 END) as clicks"),
            ])
            ->keyBy(fn ($f) => (string) $f->day);

        $serie  = [];
        $cursor = \Carbon\CarbonImmutable::parse($desde);
        $fin    = \Carbon\CarbonImmutable::parse($hasta);

        while ($cursor <= $fin) {
            $clave = $cursor->toDateString();
            $fila  = $filas[$clave] ?? null;

            $serie[] = [
                'day'         => $clave,
                'impressions' => (int) ($fila->impressions ?? 0),
                'clicks'      => (int) ($fila->clicks ?? 0),
            ];

            $cursor = $cursor->addDay();
        }

        return $serie;
    }

    private function mejoresBanners(int $limite): array
    {
        return Banner::with('campaign.advertiser')
            ->orderByDesc('impressions_count')
            ->limit($limite)
            ->get()
            ->map(fn (Banner $b) => [
                'id'          => $b->id,
                'title'       => $b->title,
                'advertiser'  => $b->campaign?->advertiser?->name,
                'impressions' => $b->impressions_count,
                'clicks'      => $b->clicks_count,
                'ctr'         => $b->impressions_count > 0
                    ? round($b->clicks_count / $b->impressions_count * 100, 2)
                    : 0,
            ])
            ->all();
    }

    /* ==================================================================
       ANUNCIANTES
       ================================================================== */

    public function advertisers(Request $request)
    {
        $q = Advertiser::query()
            ->leftJoin('business as b', 'b.busines_id', '=', 'advertisers.business_id')
            ->orderBy('advertisers.name');

        if ($buscar = trim((string) $request->query('search', ''))) {
            $q->where('advertisers.name', 'like', "%{$buscar}%");
        }

        $filas = $q->get([
            'advertisers.*',
            'b.name as business_name',
        ]);

        // Cuántas campañas tiene cada uno, en una sola consulta.
        $campanas = DB::table('ad_campaigns')
            ->groupBy('advertiser_id')
            ->pluck(DB::raw('COUNT(*)'), 'advertiser_id');

        return response()->json(
            $filas->map(function ($a) use ($campanas) {
                $a->campaigns_count = (int) ($campanas[$a->id] ?? 0);
                return $a;
            })
        );
    }

    public function storeAdvertiser(Request $request)
    {
        $datos = $this->validarAnunciante($request);

        $a = Advertiser::create($datos);

        return response()->json(['message' => 'Anunciante creado.', 'id' => $a->id], 201);
    }

    public function updateAdvertiser(Request $request, $id)
    {
        $a = Advertiser::findOr($id, fn () => abort(404, 'El anunciante no existe.'));

        $a->update($this->validarAnunciante($request, true));

        return response()->json(['message' => 'Anunciante actualizado.']);
    }

    public function deleteAdvertiser($id)
    {
        $a = Advertiser::findOr($id, fn () => abort(404, 'El anunciante no existe.'));

        // Borrar el anunciante arrastraría sus campañas y con ellas los banners
        // y su historial de métricas. Si ya pautó, lo correcto es desactivarlo.
        $campanas = AdCampaign::where('advertiser_id', $a->id)->count();
        if ($campanas) {
            return response()->json([
                'message' => "No se puede eliminar: tiene {$campanas} campaña(s). Desactívalo en su lugar.",
            ], 422);
        }

        $a->delete();

        return response()->json(['message' => 'Anunciante eliminado.']);
    }

    private function validarAnunciante(Request $request, bool $parcial = false): array
    {
        $regla = $parcial ? 'sometimes' : 'required';

        return $request->validate([
            'name'          => "{$regla}|string|max:150",
            'business_id'   => 'sometimes|nullable|integer|exists:business,busines_id',
            'contact_name'  => 'sometimes|nullable|string|max:150',
            'contact_email' => 'sometimes|nullable|email|max:150',
            'contact_phone' => 'sometimes|nullable|string|max:30',
            'tax_id'        => 'sometimes|nullable|string|max:40',
            'notes'         => 'sometimes|nullable|string',
            'state'         => 'sometimes|boolean',
        ]);
    }

    /* ==================================================================
       CAMPAÑAS
       ================================================================== */

    public function campaigns(Request $request)
    {
        $q = AdCampaign::with('advertiser:id,name')->orderByDesc('starts_at');

        if ($request->filled('advertiser_id')) {
            $q->where('advertiser_id', (int) $request->query('advertiser_id'));
        }

        if ($request->filled('state')) {
            $q->where('state', (int) $request->query('state'));
        }

        $campanas = $q->get();

        // Métricas agregadas de los banners de cada campaña, de una vez.
        $metricas = DB::table('banners')
            ->groupBy('campaign_id')
            ->get([
                'campaign_id',
                DB::raw('COUNT(*) as banners_count'),
                DB::raw('COALESCE(SUM(impressions_count), 0) as impressions'),
                DB::raw('COALESCE(SUM(clicks_count), 0) as clicks'),
            ])
            ->keyBy('campaign_id');

        return response()->json(
            $campanas->map(function (AdCampaign $c) use ($metricas) {
                $m = $metricas[$c->id] ?? null;

                return array_merge($c->toArray(), [
                    'advertiser_name' => $c->advertiser?->name,
                    'banners_count'   => (int) ($m->banners_count ?? 0),
                    'impressions'     => (int) ($m->impressions ?? 0),
                    'clicks'          => (int) ($m->clicks ?? 0),
                ]);
            })
        );
    }

    public function storeCampaign(Request $request)
    {
        $c = AdCampaign::create($this->validarCampana($request));

        return response()->json(['message' => 'Campaña creada.', 'id' => $c->id], 201);
    }

    public function updateCampaign(Request $request, $id)
    {
        $c = AdCampaign::findOr($id, fn () => abort(404, 'La campaña no existe.'));

        $c->update($this->validarCampana($request, true));

        return response()->json(['message' => 'Campaña actualizada.']);
    }

    public function deleteCampaign($id)
    {
        $c = AdCampaign::findOr($id, fn () => abort(404, 'La campaña no existe.'));

        $banners = Banner::where('campaign_id', $c->id)->count();
        if ($banners) {
            return response()->json([
                'message' => "No se puede eliminar: tiene {$banners} banner(s). Finalízala en su lugar.",
            ], 422);
        }

        $c->delete();

        return response()->json(['message' => 'Campaña eliminada.']);
    }

    private function validarCampana(Request $request, bool $parcial = false): array
    {
        $regla = $parcial ? 'sometimes' : 'required';

        $datos = $request->validate([
            'advertiser_id' => "{$regla}|integer|exists:advertisers,id",
            'name'          => "{$regla}|string|max:150",
            'description'   => 'sometimes|nullable|string',
            'objective'     => 'sometimes|in:awareness,traffic,conversion',
            'starts_at'     => "{$regla}|date",
            // La fecha final nunca antes de la inicial: una campaña invertida
            // no falla al guardarse, simplemente no entrega nada, y eso se
            // descubre tarde y sin mensaje.
            'ends_at'       => "{$regla}|date|after_or_equal:starts_at",
            'budget'        => 'sometimes|numeric|min:0',
            'state'         => 'sometimes|integer|in:0,1,2,3',
        ]);

        return $datos;
    }

    /* ==================================================================
       BANNERS
       ================================================================== */

    public function banners(Request $request)
    {
        $q = Banner::with('campaign.advertiser')->orderByDesc('priority')->orderByDesc('id');

        foreach (['placement', 'platform'] as $filtro) {
            if ($request->filled($filtro)) {
                $q->where($filtro, $request->query($filtro));
            }
        }

        if ($request->filled('campaign_id')) {
            $q->where('campaign_id', (int) $request->query('campaign_id'));
        }

        $banners = $q->get();
        $imagenes = $this->imagenesDe($banners->pluck('id')->all());

        return response()->json(
            $banners->map(fn (Banner $b) => $this->presentarBanner($b, $imagenes))
        );
    }

    public function showBanner($id)
    {
        $b = Banner::with('campaign.advertiser')
            ->findOr($id, fn () => abort(404, 'El banner no existe.'));

        return response()->json($this->presentarBanner($b, $this->imagenesDe([$b->id])));
    }

    public function storeBanner(Request $request)
    {
        $b = Banner::create($this->validarBanner($request));

        return response()->json(['message' => 'Banner creado.', 'id' => $b->id], 201);
    }

    public function updateBanner(Request $request, $id)
    {
        $b = Banner::findOr($id, fn () => abort(404, 'El banner no existe.'));

        $b->update($this->validarBanner($request, true));

        return response()->json(['message' => 'Banner actualizado.']);
    }

    public function deleteBanner($id)
    {
        $b = Banner::findOr($id, fn () => abort(404, 'El banner no existe.'));

        // Los eventos se van con él por la foránea en cascada. Es intencional:
        // el historial de un banner borrado no le sirve a nadie y mantenerlo
        // dejaría filas apuntando a una pieza que ya no existe.
        $b->delete();

        return response()->json(['message' => 'Banner eliminado.']);
    }

    /**
     * Serie e histórico de una pieza, para la ficha de métricas.
     */
    public function bannerMetrics(Request $request, $id)
    {
        $b = Banner::findOr($id, fn () => abort(404, 'El banner no existe.'));

        $dias  = max(1, min(365, (int) $request->query('range', 30)));
        $desde = now()->subDays($dias)->toDateString();

        $serie = DB::table('banner_events')
            ->where('banner_id', $b->id)
            ->where('day', '>=', $desde)
            ->groupBy('day')
            ->orderBy('day')
            ->get([
                'day',
                DB::raw("SUM(CASE WHEN type = 'impression' THEN 1 ELSE 0 END) as impressions"),
                DB::raw("SUM(CASE WHEN type = 'click' THEN 1 ELSE 0 END) as clicks"),
            ]);

        return response()->json([
            'banner_id'   => $b->id,
            'title'       => $b->title,
            'impressions' => $b->impressions_count,
            'clicks'      => $b->clicks_count,
            'ctr'         => $b->impressions_count > 0
                ? round($b->clicks_count / $b->impressions_count * 100, 2)
                : 0,
            'series'      => $serie,
        ]);
    }

    /**
     * Imagen principal de varios banners en una sola consulta.
     *
     * Preguntarle a MediaService por cada banner del listado sería una consulta
     * por fila; con veinte piezas en pantalla eso es lo que se nota.
     */
    private function imagenesDe(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        return DB::table('media_files')
            ->where('entity_type', Banner::ENTIDAD_MEDIOS)
            ->whereIn('entity_id', $ids)
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->get(['entity_id', 'object_key'])
            ->groupBy('entity_id')
            ->map(fn ($grupo) => $this->medios->url($grupo->first()->object_key))
            ->all();
    }

    private function presentarBanner(Banner $b, array $imagenes): array
    {
        return array_merge($b->toArray(), [
            'image_url'       => $imagenes[$b->id] ?? null,
            'campaign_name'   => $b->campaign?->name,
            'advertiser_name' => $b->campaign?->advertiser?->name,
            'ctr'             => $b->impressions_count > 0
                ? round($b->clicks_count / $b->impressions_count * 100, 2)
                : 0,
        ]);
    }

    private function validarBanner(Request $request, bool $parcial = false): array
    {
        $regla = $parcial ? 'sometimes' : 'required';

        return $request->validate([
            'campaign_id' => "{$regla}|integer|exists:ad_campaigns,id",
            'title'       => "{$regla}|string|max:150",
            'subtitle'    => 'sometimes|nullable|string|max:255',
            'placement'   => 'sometimes|in:home_hero,home_strip,listing_inline,splash,web_home',
            'platform'    => 'sometimes|in:app,web,both',
            'link_type'   => 'sometimes|in:none,url,business,product,category',
            'link_value'  => 'sometimes|nullable|string|max:500',
            'priority'    => 'sometimes|integer|min:0|max:999',
            'starts_at'   => 'sometimes|nullable|date',
            'ends_at'     => 'sometimes|nullable|date|after_or_equal:starts_at',

            // Segmentación. Se admiten arreglos de enteros; vacío = sin filtro.
            'target_municipalities'        => 'sometimes|nullable|array',
            'target_municipalities.*'      => 'integer',
            'target_complexes'             => 'sometimes|nullable|array',
            'target_complexes.*'           => 'integer',
            'target_business_categories'   => 'sometimes|nullable|array',
            'target_business_categories.*' => 'integer',
            'target_roles'                 => 'sometimes|nullable|array',
            'target_roles.*'               => 'integer',

            'state' => 'sometimes|boolean',
        ]);
    }

    /* ==================================================================
       CUPONES
       ================================================================== */

    public function coupons(Request $request)
    {
        $q = Coupon::query()
            ->leftJoin('business as b', 'b.busines_id', '=', 'coupons.business_id')
            ->leftJoin('category as c', 'c.category_id', '=', 'coupons.category_id')
            ->orderByDesc('coupons.id');

        if ($buscar = trim((string) $request->query('search', ''))) {
            $q->where('coupons.code', 'like', '%' . strtoupper($buscar) . '%');
        }

        return response()->json(
            $q->get([
                'coupons.*',
                'b.name as business_name',
                'c.name as category_name',
            ])
        );
    }

    public function storeCoupon(Request $request)
    {
        $c = Coupon::create($this->validarCupon($request));

        return response()->json(['message' => 'Cupón creado.', 'id' => $c->id, 'code' => $c->code], 201);
    }

    public function updateCoupon(Request $request, $id)
    {
        $c = Coupon::findOr($id, fn () => abort(404, 'El cupón no existe.'));

        $c->update($this->validarCupon($request, true, $c->id));

        return response()->json(['message' => 'Cupón actualizado.']);
    }

    public function deleteCoupon($id)
    {
        $c = Coupon::findOr($id, fn () => abort(404, 'El cupón no existe.'));

        // Un cupón ya canjeado es parte del historial de descuentos aplicados:
        // borrarlo dejaría pedidos con un descuento que no se puede explicar.
        if ($c->uses_count > 0) {
            return response()->json([
                'message' => "No se puede eliminar: ya se canjeó {$c->uses_count} vez(ces). Desactívalo en su lugar.",
            ], 422);
        }

        $c->delete();

        return response()->json(['message' => 'Cupón eliminado.']);
    }

    /** Canjes de un cupón, para ver quién lo usó. */
    public function couponRedemptions($id)
    {
        Coupon::findOr($id, fn () => abort(404, 'El cupón no existe.'));

        return response()->json(
            DB::table('coupon_redemptions as r')
                ->leftJoin('user as u', 'u.user_id', '=', 'r.user_id')
                ->where('r.coupon_id', $id)
                ->orderByDesc('r.id')
                ->limit(500)
                ->get([
                    'r.id', 'r.order_id', 'r.discount', 'r.created_at',
                    'u.user_id', 'u.name', 'u.email',
                ])
        );
    }

    private function validarCupon(Request $request, bool $parcial = false, ?int $ignorar = null): array
    {
        $regla = $parcial ? 'sometimes' : 'required';

        $unico = 'unique:coupons,code' . ($ignorar ? ",{$ignorar}" : '');

        return $request->validate([
            'code'              => "{$regla}|string|max:40|{$unico}",
            'description'       => 'sometimes|nullable|string|max:255',
            'type'              => 'sometimes|in:percent,fixed',
            'value'             => "{$regla}|numeric|min:0",
            'max_discount'      => 'sometimes|nullable|numeric|min:0',
            'min_order'         => 'sometimes|numeric|min:0',
            'max_uses'          => 'sometimes|nullable|integer|min:1',
            'max_uses_per_user' => 'sometimes|nullable|integer|min:1',
            'business_id'       => 'sometimes|nullable|integer|exists:business,busines_id',
            'category_id'       => 'sometimes|nullable|integer|exists:category,category_id',
            'advertiser_id'     => 'sometimes|nullable|integer|exists:advertisers,id',
            'starts_at'         => "{$regla}|date",
            'ends_at'           => "{$regla}|date|after_or_equal:starts_at",
            'state'             => 'sometimes|boolean',
        ]);
    }

    /* ==================================================================
       NEGOCIOS DESTACADOS
       ================================================================== */

    public function featured(Request $request)
    {
        $q = FeaturedBusiness::query()
            ->leftJoin('business as b', 'b.busines_id', '=', 'featured_businesses.business_id')
            ->orderByDesc('featured_businesses.priority')
            ->orderByDesc('featured_businesses.id');

        if ($request->filled('placement')) {
            $q->where('featured_businesses.placement', $request->query('placement'));
        }

        return response()->json(
            $q->get(['featured_businesses.*', 'b.name as business_name', 'b.logo as business_logo'])
        );
    }

    public function storeFeatured(Request $request)
    {
        $datos = $this->validarDestacado($request);

        /*
         * Un negocio no puede estar destacado dos veces en el mismo sitio con
         * periodos que se pisan: se pagaría dos veces por el mismo puesto y el
         * listado lo mostraría duplicado.
         */
        if ($this->destaqueSolapado($datos['business_id'], $datos['placement'], $datos['starts_at'], $datos['ends_at'])) {
            return response()->json([
                'message' => 'Ese negocio ya está destacado en la misma posición durante esas fechas.',
            ], 422);
        }

        $f = FeaturedBusiness::create($datos);

        return response()->json(['message' => 'Destaque creado.', 'id' => $f->id], 201);
    }

    public function updateFeatured(Request $request, $id)
    {
        $f = FeaturedBusiness::findOr($id, fn () => abort(404, 'El destaque no existe.'));

        $datos = $this->validarDestacado($request, true);

        $solapa = $this->destaqueSolapado(
            $datos['business_id'] ?? $f->business_id,
            $datos['placement'] ?? $f->placement,
            $datos['starts_at'] ?? $f->starts_at->toDateString(),
            $datos['ends_at'] ?? $f->ends_at->toDateString(),
            $f->id,
        );

        if ($solapa) {
            return response()->json([
                'message' => 'Ese negocio ya está destacado en la misma posición durante esas fechas.',
            ], 422);
        }

        $f->update($datos);

        return response()->json(['message' => 'Destaque actualizado.']);
    }

    public function deleteFeatured($id)
    {
        $f = FeaturedBusiness::findOr($id, fn () => abort(404, 'El destaque no existe.'));

        $f->delete();

        return response()->json(['message' => 'Destaque eliminado.']);
    }

    private function destaqueSolapado(
        int $negocio,
        string $posicion,
        string $desde,
        string $hasta,
        ?int $ignorar = null,
    ): bool {
        return FeaturedBusiness::where('business_id', $negocio)
            ->where('placement', $posicion)
            ->when($ignorar, fn ($q) => $q->where('id', '!=', $ignorar))
            // Dos periodos se pisan si cada uno empieza antes de que el otro
            // termine. Es más corto y más seguro que enumerar los casos.
            ->whereDate('starts_at', '<=', $hasta)
            ->whereDate('ends_at', '>=', $desde)
            ->exists();
    }

    private function validarDestacado(Request $request, bool $parcial = false): array
    {
        $regla = $parcial ? 'sometimes' : 'required';

        return $request->validate([
            'business_id' => "{$regla}|integer|exists:business,busines_id",
            'placement'   => 'sometimes|in:home_top,category_top,search_top',
            'priority'    => 'sometimes|integer|min:0|max:999',
            'starts_at'   => "{$regla}|date",
            'ends_at'     => "{$regla}|date|after_or_equal:starts_at",
            'paid_amount' => 'sometimes|numeric|min:0',
            'state'       => 'sometimes|boolean',
        ]);
    }

    /* ==================================================================
       NOTIFICACIONES SEGMENTADAS
       ================================================================== */

    public function pushCampaigns()
    {
        return response()->json(
            PushCampaign::orderByDesc('id')->limit(200)->get()
        );
    }

    /**
     * Cuánta gente alcanzaría un segmento, antes de enviar nada.
     *
     * Existe porque un envío masivo no se puede deshacer: ver "4.812
     * destinatarios" antes de pulsar es la única oportunidad de notar que el
     * filtro estaba mal puesto.
     */
    public function pushPreview(Request $request)
    {
        $segmento = $request->validate([
            'segment'                  => 'sometimes|nullable|array',
            'segment.roles'            => 'sometimes|nullable|array',
            'segment.municipalities'   => 'sometimes|nullable|array',
            'segment.complexes'        => 'sometimes|nullable|array',
            'segment.only_with_orders' => 'sometimes|boolean',
        ])['segment'] ?? [];

        return response()->json([
            'recipients' => $this->consultaSegmento($segmento)->count(),
        ]);
    }

    public function storePushCampaign(Request $request)
    {
        $datos = $this->validarPush($request);
        $datos['created_by'] = $request->user()?->user_id;

        $p = PushCampaign::create($datos);

        return response()->json(['message' => 'Notificación creada.', 'id' => $p->id], 201);
    }

    public function updatePushCampaign(Request $request, $id)
    {
        $p = PushCampaign::findOr($id, fn () => abort(404, 'La notificación no existe.'));

        if (!$p->editable()) {
            return response()->json([
                'message' => 'Esta notificación ya se envió y no se puede modificar.',
            ], 422);
        }

        $p->update($this->validarPush($request, true));

        return response()->json(['message' => 'Notificación actualizada.']);
    }

    /**
     * Marca la campaña como enviada y congela su alcance.
     *
     * IMPORTANTE: acá NO se entrega a los dispositivos. La plataforma todavía
     * no tiene registro de tokens de push (FCM/APNs), así que este método
     * resuelve el segmento, guarda a cuánta gente alcanzó y deja la campaña
     * cerrada. El transporte real se engancha en el punto marcado abajo, sin
     * tocar nada más de este módulo.
     */
    public function sendPushCampaign($id)
    {
        $p = PushCampaign::findOr($id, fn () => abort(404, 'La notificación no existe.'));

        if ($p->sent_at !== null) {
            return response()->json(['message' => 'Esta notificación ya se envió.'], 422);
        }

        if ($p->state === PushCampaign::CANCELADA) {
            return response()->json(['message' => 'Esta notificación está cancelada.'], 422);
        }

        $destinatarios = $this->consultaSegmento($p->segment ?? [])->count();

        // ── Punto de enganche del transporte ──────────────────────────────
        // Cuando exista la tabla de tokens de dispositivo, el envío va acá,
        // en una cola: hacerlo dentro de la petición dejaría al panel esperando
        // mientras se despachan miles de mensajes.
        // ──────────────────────────────────────────────────────────────────

        /*
         * `forceFill` y no `update`: `sent_at` y `recipients_count` quedan
         * fuera de $fillable a propósito, porque son constancia de lo que hizo
         * el servidor y no datos que alguien pueda mandar. Con `update` se
         * descartaban en silencio y la campaña quedaba marcada como enviada
         * pero sin fecha, con lo que se podía volver a enviar.
         */
        $p->forceFill([
            'state'            => PushCampaign::ENVIADA,
            'sent_at'          => now(),
            'recipients_count' => $destinatarios,
        ])->save();

        return response()->json([
            'message'    => "Notificación cerrada con {$destinatarios} destinatario(s).",
            'recipients' => $destinatarios,
            // Se dice explícitamente para que el panel no afirme algo falso.
            'delivered'  => false,
            'note'       => 'El envío a dispositivos requiere el registro de tokens de push, que aún no existe.',
        ]);
    }

    public function cancelPushCampaign($id)
    {
        $p = PushCampaign::findOr($id, fn () => abort(404, 'La notificación no existe.'));

        if ($p->sent_at !== null) {
            return response()->json(['message' => 'No se puede cancelar: ya se envió.'], 422);
        }

        $p->update(['state' => PushCampaign::CANCELADA]);

        return response()->json(['message' => 'Notificación cancelada.']);
    }

    /**
     * Traduce los criterios del segmento a una consulta sobre `user`.
     *
     * Se devuelve la consulta y no el resultado para que quien llame decida si
     * necesita contar o recorrer: la vista previa solo cuenta, y traer miles de
     * filas para descartarlas sería gratuito solo en apariencia.
     */
    private function consultaSegmento(array $s)
    {
        $q = DB::table('user as u')->where('u.state', 1);

        if (!empty($s['roles'])) {
            $q->whereIn('u.rol', array_map('intval', $s['roles']));
        }

        /*
         * El municipio del usuario NO está en `user`: vive en `user_address`,
         * que además admite varias direcciones por persona. Basta con que
         * alguna caiga en el municipio buscado, así que va como EXISTS y no
         * como join: un join devolvería la misma persona una vez por dirección
         * y el conteo de destinatarios saldría inflado.
         */
        if (!empty($s['municipalities'])) {
            $q->whereExists(function ($sub) use ($s) {
                $sub->from('user_address as ua')
                    ->whereColumn('ua.user_id', 'u.user_id')
                    ->whereIn('ua.municipality_id', array_map('intval', $s['municipalities']))
                    ->selectRaw('1');
            });
        }

        // El vínculo con el conjunto cuelga de `buyer`, no de `user`.
        if (!empty($s['complexes'])) {
            $q->whereIn('u.user_id', function ($sub) use ($s) {
                $sub->from('buyer_complex as bc')
                    ->join('buyer as bu', 'bu.buyer_id', '=', 'bc.buyer_id')
                    ->whereIn('bc.complex_id', array_map('intval', $s['complexes']))
                    ->whereNotNull('bu.user_id')
                    ->select('bu.user_id');
            });
        }

        // `orderssales` referencia al comprador (`buyer_id`), no al usuario.
        if (!empty($s['only_with_orders'])) {
            $q->whereExists(function ($sub) {
                $sub->from('orderssales as o')
                    ->join('buyer as bo', 'bo.buyer_id', '=', 'o.buyer_id')
                    ->whereColumn('bo.user_id', 'u.user_id')
                    ->selectRaw('1');
            });
        }

        /*
         * No hay filtro por fecha de registro: la tabla `user` no tiene
         * `created_at` (se creó sin timestamps). Segmentar por antigüedad
         * exigiría añadir la columna primero, y hacerlo con `email_verified_at`
         * como sustituto contaría "cuándo verificó" y no "cuándo se registró",
         * que son cosas distintas para quien arma la campaña.
         */

        return $q;
    }

    private function validarPush(Request $request, bool $parcial = false): array
    {
        $regla = $parcial ? 'sometimes' : 'required';

        return $request->validate([
            'title'        => "{$regla}|string|max:120",
            'body'         => "{$regla}|string|max:500",
            'link_type'    => 'sometimes|in:none,url,business,product,category',
            'link_value'   => 'sometimes|nullable|string|max:500',
            'segment'      => 'sometimes|nullable|array',
            'scheduled_at' => 'sometimes|nullable|date',
            'state'        => 'sometimes|integer|in:0,1,3',
        ]);
    }
}
