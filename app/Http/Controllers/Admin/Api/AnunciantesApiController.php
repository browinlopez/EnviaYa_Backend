<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AnuncianteRequest;
use App\Models\Marketing\AdCampaign;
use App\Models\Marketing\Advertiser;
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

    public function storeAdvertiser(AnuncianteRequest $request)
    {
        $datos = $request->validated();

        $a = Advertiser::create($datos);

        return response()->json(['message' => 'Anunciante creado.', 'id' => $a->id], 201);
    }

    public function updateAdvertiser(Request $request, $id)
    {
        $a = Advertiser::findOr($id, fn () => abort(404, 'El anunciante no existe.'));

        /*
         * EN LINEA Y NO EN UN FormRequest, a proposito: el `findOr` de arriba
         * contesta 404 cuando el anunciante no existe, y eso tiene que ir
         * ANTES que un error de validacion. Las reglas no se repiten: se
         * piden a `AnuncianteRequest` en modo parcial.
         */
        $a->update($request->validate(AnuncianteRequest::reglas(parcial: true)));

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

    /* ==================================================================
       CAMPAÑAS
       ================================================================== */
}
