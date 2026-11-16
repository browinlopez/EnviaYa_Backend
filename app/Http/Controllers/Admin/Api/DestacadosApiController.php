<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DestacadoRequest;
use App\Models\Marketing\FeaturedBusiness;
use App\Support\FiltroDeFechas;
use Illuminate\Http\Request;

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

    public function storeFeatured(DestacadoRequest $request)
    {
        $datos = $request->validated();

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
        /*
         * EN LINEA Y NO EN UN FormRequest, a proposito: la busqueda de arriba
         * contesta 404 cuando el registro no existe, y eso tiene que ir ANTES
         * que un error de validacion. Las reglas no se repiten: se piden a
         * `DestacadoRequest` en modo parcial.
         */
        $datos = $request->validate(DestacadoRequest::reglas(parcial: true));

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
            // Como rangos: ver `FiltroDeFechas`. `hasta` incluye el dia
            // entero, que es lo que hacia `whereDate(..., '<=', ...)`.
            ->tap(fn ($q) => FiltroDeFechas::hasta($q, 'starts_at', $hasta))
            ->tap(fn ($q) => FiltroDeFechas::desde($q, 'ends_at', $desde))
            ->exists();
    }

    /* ==================================================================
       NOTIFICACIONES SEGMENTADAS
       ================================================================== */
}
