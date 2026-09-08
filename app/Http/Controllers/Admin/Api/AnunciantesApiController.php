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

class AnunciantesApiController extends Controller
{

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
}
