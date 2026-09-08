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

class DestacadosApiController extends Controller
{

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
}
