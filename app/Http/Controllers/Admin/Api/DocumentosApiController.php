<?php

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Models\Operacion\DomiciliaryDocument;
use App\Models\Operacion\LandingRequest;
use App\Models\Operacion\Pqrs;
use App\Models\Operacion\PqrsNote;
use App\Models\Operacion\SafetyIncident;
use App\Models\Operacion\Settlement;
use App\Services\LiquidacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DocumentosApiController extends Controller
{

    /**
     * Documentos con su situación calculada, y el resumen del tablero.
     *
     * Se devuelve `summary` aparte porque los contadores tienen que ser del
     * TOTAL y no de lo que se esté filtrando: el número de vencidos no puede
     * cambiar porque alguien buscó un nombre.
     */
    public function documentos(Request $request)
    {
        $q = DomiciliaryDocument::query()
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'domiciliary_documents.domiciliary_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->orderByRaw('CASE WHEN domiciliary_documents.expires_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('domiciliary_documents.expires_at');

        if ($request->filled('domiciliary_id')) {
            $q->where('domiciliary_documents.domiciliary_id', (int) $request->query('domiciliary_id'));
        }

        if ($request->filled('type')) {
            $q->where('domiciliary_documents.type', $request->query('type'));
        }

        $filas = $q->get([
            'domiciliary_documents.*',
            'u.name as domiciliary_name',
            'u.phone as domiciliary_phone',
        ]);

        return response()->json([
            'data' => $filas->map(fn (DomiciliaryDocument $d) => array_merge($d->toArray(), [
                'domiciliary_name'  => $d->domiciliary_name,
                'domiciliary_phone' => $d->domiciliary_phone,
                'situation'         => $d->situacion(),
                'days_left'         => $d->diasRestantes(),
            ])),
            'summary' => $this->resumenDocumentos(),
        ]);
    }

    private function resumenDocumentos(): array
    {
        $vigentes = DomiciliaryDocument::where('state', 1)->get();

        $porSituacion = $vigentes->groupBy(fn (DomiciliaryDocument $d) => $d->situacion())
            ->map->count();

        /*
         * Domiciliarios activos a los que les falta algún documento obligatorio.
         * Es la pregunta que SST tiene que poder responder en un vistazo: quién
         * está rodando sin papeles.
         */
        $activos = DB::table('domiciliary')->where('state', 1)->pluck('domiciliary_id');

        $sinCompletar = 0;
        foreach ($activos as $id) {
            $tiene = DomiciliaryDocument::where('domiciliary_id', $id)
                ->where('state', 1)
                ->pluck('type')
                ->all();

            if (array_diff(DomiciliaryDocument::OBLIGATORIOS, $tiene)) {
                $sinCompletar++;
            }
        }

        return [
            'vencidos'          => (int) ($porSituacion['vencido'] ?? 0),
            'por_vencer'        => (int) ($porSituacion['por_vencer'] ?? 0),
            'vigentes'          => (int) ($porSituacion['vigente'] ?? 0),
            'sin_vencimiento'   => (int) ($porSituacion['sin_vencimiento'] ?? 0),
            'domiciliarios_incompletos' => $sinCompletar,
            'domiciliarios_activos'     => $activos->count(),
            'aviso_dias'        => DomiciliaryDocument::AVISO_DIAS,
            'obligatorios'      => DomiciliaryDocument::OBLIGATORIOS,
        ];
    }

    public function storeDocumento(Request $request)
    {
        $d = DomiciliaryDocument::create($this->validarDocumento($request));

        return response()->json(['message' => 'Documento registrado.', 'id' => $d->id], 201);
    }

    public function updateDocumento(Request $request, $id)
    {
        $d = DomiciliaryDocument::findOr($id, fn () => abort(404, 'El documento no existe.'));

        $d->update($this->validarDocumento($request, true));

        return response()->json(['message' => 'Documento actualizado.']);
    }

    public function deleteDocumento($id)
    {
        $d = DomiciliaryDocument::findOr($id, fn () => abort(404, 'El documento no existe.'));

        $d->delete();

        return response()->json(['message' => 'Documento eliminado.']);
    }

    private function validarDocumento(Request $request, bool $parcial = false): array
    {
        $regla = $parcial ? 'sometimes' : 'required';

        return $request->validate([
            'domiciliary_id' => "{$regla}|integer|exists:domiciliary,domiciliary_id",
            'type'           => "{$regla}|in:licencia,soat,tecnomecanica,arl,eps,examen_medico,cedula,otro",
            'number'         => 'sometimes|nullable|string|max:60',
            'issued_at'      => 'sometimes|nullable|date',
            // Puede ir nula: la cédula no caduca. Un documento sin fecha
            // simplemente no entra en las alertas.
            'expires_at'     => 'sometimes|nullable|date',
            'media_id'       => 'sometimes|nullable|integer|exists:media_files,id',
            'notes'          => 'sometimes|nullable|string|max:255',
            'state'          => 'sometimes|boolean',
        ]);
    }

    /* ==================================================================
       SST · INCIDENTES
       ================================================================== */
}
