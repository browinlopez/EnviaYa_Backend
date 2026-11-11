<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BannerRequest;
use App\Models\Marketing\Banner;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BannersApiController extends Controller
{
    public function __construct(private readonly MediaService $medios) {}

    /* ==================================================================
       RESUMEN
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

    public function storeBanner(BannerRequest $request)
    {
        $b = Banner::create($request->validated());

        return response()->json(['message' => 'Banner creado.', 'id' => $b->id], 201);
    }

    public function updateBanner(Request $request, $id)
    {
        $b = Banner::findOr($id, fn () => abort(404, 'El banner no existe.'));

        /*
         * EN LINEA Y NO EN UN FormRequest, a proposito: la busqueda de arriba
         * contesta 404 cuando el registro no existe, y eso tiene que ir ANTES
         * que un error de validacion. Las reglas no se repiten: se piden a
         * `BannerRequest` en modo parcial.
         */
        $b->update($request->validate(BannerRequest::reglas(parcial: true)));

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
    /**
     * Rendimiento de una pieza.
     *
     * Devuelve tres cosas que antes se confundían en una:
     *
     *  · `period`  — lo que pasó en la ventana pedida, calculado desde los
     *                eventos.
     *  · `previous`— la ventana anterior de igual tamaño, para poder decir si
     *                sube o baja. Un CTR de 4,2 % no significa nada solo; al
     *                lado del 3,1 % de la quincena pasada, sí.
     *  · `lifetime`— los contadores acumulados de la pieza.
     *
     * Antes el panel pintaba los acumulados encima de una serie de 30 días y
     * las dos cifras no cuadraban: parecía un error de cálculo cuando era una
     * mezcla de dos preguntas distintas.
     */
    public function bannerMetrics(Request $request, $id)
    {
        $b = Banner::findOr($id, fn () => abort(404, 'El banner no existe.'));

        $dias = max(1, min(365, (int) $request->query('range', 30)));
        $hasta = now()->startOfDay();
        $desde = $hasta->copy()->subDays($dias - 1);

        // La ventana anterior termina justo antes de que empiece esta.
        $desdeAntes = $desde->copy()->subDays($dias);
        $hastaAntes = $desde->copy()->subDay();

        $porDia = $this->eventosPorDia($b->id, $desde, $hasta);

        // Se rellenan los días sin eventos. Sin esto la gráfica une el día 3
        // con el día 9 en una línea recta y aparenta actividad continua donde
        // hubo seis días de nada.
        $serie = [];
        $impresiones = 0;
        $clics = 0;

        for ($d = $desde->copy(); $d->lte($hasta); $d->addDay()) {
            $clave = $d->toDateString();
            $fila = $porDia[$clave] ?? null;

            $i = (int) ($fila->impressions ?? 0);
            $c = (int) ($fila->clicks ?? 0);

            $impresiones += $i;
            $clics += $c;

            $serie[] = [
                'day' => $clave,
                'label' => $d->format('d M'),
                'impressions' => $i,
                'clicks' => $c,
                'ctr' => $i > 0 ? round($c / $i * 100, 2) : 0,
            ];
        }

        $antes = $this->eventosPorDia($b->id, $desdeAntes, $hastaAntes);
        $impresionesAntes = array_sum(array_map(fn ($f) => (int) $f->impressions, $antes));
        $clicsAntes = array_sum(array_map(fn ($f) => (int) $f->clicks, $antes));

        // El mejor día por CLICS y no por impresiones: lo que interesa de una
        // pieza es cuándo funcionó, no cuándo se mostró mucho sin resultado.
        $mejor = collect($serie)->sortByDesc('clicks')->first();

        return response()->json([
            'banner_id' => $b->id,
            'title' => $b->title,
            'period' => [
                'days' => $dias,
                'from' => $desde->toDateString(),
                'to' => $hasta->toDateString(),
            ],
            'totals' => $this->cifras($impresiones, $clics),
            'previous' => $this->cifras($impresionesAntes, $clicsAntes),
            'lifetime' => $this->cifras(
                (int) $b->impressions_count,
                (int) $b->clicks_count,
            ),
            'best_day' => ($mejor && $mejor['clicks'] > 0) ? $mejor : null,
            'series' => $serie,
        ]);
    }

    /** @return array<string, object> indexado por día */
    private function eventosPorDia(int $bannerId, $desde, $hasta): array
    {
        return DB::table('banner_events')
            ->where('banner_id', $bannerId)
            ->whereBetween('day', [$desde->toDateString(), $hasta->toDateString()])
            ->groupBy('day')
            ->orderBy('day')
            ->get([
                'day',
                DB::raw("SUM(CASE WHEN type = 'impression' THEN 1 ELSE 0 END) as impressions"),
                DB::raw("SUM(CASE WHEN type = 'click' THEN 1 ELSE 0 END) as clicks"),
            ])
            ->keyBy('day')
            ->all();
    }

    private function cifras(int $impresiones, int $clics): array
    {
        return [
            'impressions' => $impresiones,
            'clicks' => $clics,
            // Sin impresiones el CTR no es cero, es indefinido: dividir por
            // cero y devolver 0,0 % afirma que la pieza no funcionó cuando lo
            // que pasa es que no se mostró.
            'ctr' => $impresiones > 0 ? round($clics / $impresiones * 100, 2) : null,
        ];
    }

    /**
     * Imagen principal de varios banners en una sola consulta.
     *
     * Preguntarle a MediaService por cada banner del listado sería una consulta
     * por fila; con veinte piezas en pantalla eso es lo que se nota.
     */
    private function imagenesDe(array $ids): array
    {
        if (! $ids) {
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
            'image_url' => $imagenes[$b->id] ?? null,
            'campaign_name' => $b->campaign?->name,
            'advertiser_name' => $b->campaign?->advertiser?->name,
            'ctr' => $b->impressions_count > 0
                ? round($b->clicks_count / $b->impressions_count * 100, 2)
                : 0,
        ]);
    }

    /* ==================================================================
       CUPONES
       ================================================================== */
}
