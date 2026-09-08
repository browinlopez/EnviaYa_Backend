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
}
