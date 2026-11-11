<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CampanaRequest;
use App\Models\Marketing\AdCampaign;
use App\Models\Marketing\Banner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CampanasApiController extends Controller
{
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
                    'banners_count' => (int) ($m->banners_count ?? 0),
                    'impressions' => (int) ($m->impressions ?? 0),
                    'clicks' => (int) ($m->clicks ?? 0),
                ]);
            })
        );
    }

    public function storeCampaign(CampanaRequest $request)
    {
        $c = AdCampaign::create($request->validated());

        return response()->json(['message' => 'Campaña creada.', 'id' => $c->id], 201);
    }

    public function updateCampaign(Request $request, $id)
    {
        $c = AdCampaign::findOr($id, fn () => abort(404, 'La campaña no existe.'));

        /*
         * EN LINEA Y NO EN UN FormRequest, a proposito: la busqueda de arriba
         * contesta 404 cuando el registro no existe, y eso tiene que ir ANTES
         * que un error de validacion. Las reglas no se repiten: se piden a
         * `CampanaRequest` en modo parcial.
         */
        $c->update($request->validate(CampanaRequest::reglas(parcial: true)));

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

    /* ==================================================================
       BANNERS
       ================================================================== */
}
