<?php

namespace App\Http\Controllers\Admin\Api;

use App\Services\Push\Notificador;
use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\Marketing\AdCampaign;
use App\Models\Marketing\Advertiser;
use App\Models\Marketing\Banner;
use App\Models\Marketing\Coupon;
use App\Models\Marketing\FeaturedBusiness;
use App\Models\Marketing\PushCampaign;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketingApiController extends Controller
{

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


    public function pushCampaigns(Notificador $notificador)
    {
        $transporte = $notificador->transporte();

        return response()->json([
            'data' => PushCampaign::orderByDesc('id')->limit(200)->get(),
            /*
             * Estado del transporte, para que la pantalla pueda avisar ANTES de
             * que alguien arme una campaña entera. Sin credenciales de Firebase
             * el módulo funciona —se redactan, se segmentan, se cierran— y no
             * entrega nada: decirlo al final, cuando ya se envió, es tarde.
             */
            'transport' => [
                'name'  => $transporte->nombre(),
                'ready' => $transporte->configurado(),
            ],
            // Cuántos teléfonos hay registrados en total. Es el techo de
            // cualquier campaña y casi nadie lo tiene presente.
            'devices' => DeviceToken::vivos()->count(),
        ]);
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

        $usuarios = $this->consultaSegmento($segmento)->pluck('u.user_id');

        return response()->json([
            'recipients' => $usuarios->count(),
            /*
             * Y a cuántos TELÉFONOS llegaría de verdad. Es el número que importa
             * y el que nadie espera: de 3.000 destinatarios pueden salir 800
             * envíos si el resto no tiene la app instalada con sesión abierta.
             * Verlo antes de pulsar es la diferencia entre calcular el alcance y
             * suponerlo.
             */
            'devices' => DeviceToken::vivos()->whereIn('user_id', $usuarios)->count(),
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
     * Envía la campaña y congela su alcance.
     *
     * Resuelve el segmento —que son PERSONAS—, lo traduce a los DISPOSITIVOS
     * registrados de esas personas y encola el envío por lotes. Los dos números
     * son distintos y los dos importan: "llegó a 3.000 personas" es falso si
     * solo 800 tienen la app instalada con sesión abierta.
     *
     * El envío no ocurre en esta petición. Firebase manda un mensaje por
     * llamada, así que mil teléfonos son mil peticiones HTTP: hacerlas acá sería
     * un tiempo de espera agotado con la campaña marcada como enviada a medias.
     * La respuesta dice cuántos dispositivos se encolaron; lo entregado se ve
     * después, en la propia pantalla.
     */
    public function sendPushCampaign($id, Notificador $notificador)
    {
        $p = PushCampaign::findOr($id, fn () => abort(404, 'La notificación no existe.'));

        if ($p->sent_at !== null) {
            return response()->json(['message' => 'Esta notificación ya se envió.'], 422);
        }

        if ($p->state === PushCampaign::CANCELADA) {
            return response()->json(['message' => 'Esta notificación está cancelada.'], 422);
        }

        $usuarios = $this->consultaSegmento($p->segment ?? [])
            ->pluck('u.user_id')
            ->all();

        $destinatarios = count($usuarios);

        /*
         * `forceFill` y no `update`: `sent_at` y los contadores quedan fuera de
         * $fillable a propósito, porque son constancia de lo que hizo el
         * servidor y no datos que alguien pueda mandar. Con `update` se
         * descartaban en silencio y la campaña quedaba marcada como enviada
         * pero sin fecha, con lo que se podía volver a enviar.
         *
         * Se marca ANTES de encolar: si se encolara primero, un fallo al guardar
         * dejaría notificaciones saliendo hacia una campaña que sigue figurando
         * como no enviada, y volver a pulsar la mandaría dos veces.
         */
        $p->forceFill([
            'state'            => PushCampaign::ENVIADA,
            'sent_at'          => now(),
            'recipients_count' => $destinatarios,
            'delivered_count'  => 0,
            'failed_count'     => 0,
        ])->save();

        $dispositivos = $notificador->encolar($p, $usuarios);

        $p->forceFill(['devices_count' => $dispositivos])->save();

        $transporte = $notificador->transporte();

        return response()->json([
            'message' => $dispositivos === 0
                ? "Ninguno de los {$destinatarios} destinatarios tiene un dispositivo registrado."
                : "En camino a {$dispositivos} dispositivo(s) de {$destinatarios} destinatario(s).",
            'recipients' => $destinatarios,
            'devices'    => $dispositivos,
            // Se dice explícitamente para que el panel no afirme algo falso.
            'delivered'  => $transporte->configurado(),
            'transport'  => $transporte->nombre(),
            'note'       => $transporte->configurado()
                ? null
                : 'No hay transporte de push configurado en el servidor: no se entregará nada. Falta FCM_CREDENTIALS.',
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
