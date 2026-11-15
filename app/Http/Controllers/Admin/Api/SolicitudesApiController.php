<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActualizarSolicitudRequest;
use App\Http\Requests\Admin\FiltrarSolicitudesRequest;
use App\Models\Operacion\LandingRequest;
use App\Models\Operacion\Pqrs;
use App\Support\FiltroDeFechas;
use Illuminate\Support\Facades\Storage;

class SolicitudesApiController extends Controller
{
    public function solicitudes(FiltrarSolicitudesRequest $request)
    {
        $q = LandingRequest::query()
            ->leftJoin('user as a', 'a.user_id', '=', 'landing_requests.assigned_to')
            ->orderByDesc('landing_requests.id');

        if ($request->filled('state')) {
            $q->where('landing_requests.state', (int) $request->query('state'));
        }

        if ($request->filled('type')) {
            $q->where('landing_requests.type', $request->query('type'));
        }

        $filas = $q->get([
            'landing_requests.*',
            'a.name as assignee_name',
        ]);

        return response()->json([
            // Cada fila ya es un modelo, así que el vencimiento lo contesta
            // ella misma: en PQRS hace falta rearmarlo porque aquella consulta
            // devuelve objetos planos.
            'data' => $filas->each(fn ($s) => $s->overdue = $s->vencida()),
            'summary' => [
                'nuevas' => LandingRequest::where('state', LandingRequest::NUEVA)->count(),
                'en_gestion' => LandingRequest::where('state', LandingRequest::EN_GESTION)->count(),
                /*
                 * Vencidas son, hoy por hoy, solo eliminaciones de cuenta: son
                 * las únicas con plazo comprometido. Que aparezcan aparte no es
                 * un adorno: pasarse de los quince días hábiles prometidos en
                 * /eliminar-cuenta es un incumplimiento público, no un retraso
                 * interno.
                 */
                'vencidas' => LandingRequest::whereIn('state', [LandingRequest::NUEVA, LandingRequest::EN_GESTION])
                    ->whereNotNull('due_at')
                    ->where('due_at', '<', now())
                    ->count(),
                'mes' => FiltroDeFechas::elMes(
                    LandingRequest::query(),
                    'created_at', (int) now()->year, (int) now()->month,
                )->count(),
                // Cuántas hay de cada tipo, para el tablero del panel.
                'por_tipo' => LandingRequest::selectRaw('type, count(*) as total')
                    ->groupBy('type')
                    ->pluck('total', 'type'),
            ],
        ]);
    }

    public function showSolicitud($id)
    {
        $s = LandingRequest::with('assignee:user_id,name')
            ->findOr($id, fn () => abort(404, 'La solicitud no existe.'));

        return response()->json($s);
    }

    /**
     * La foto o el comprobante de una PQRS.
     *
     * Vive en el disco privado y solo sale por acá, con sesión y con permiso
     * del módulo: nunca tiene una URL pública que se pueda reenviar.
     */
    public function adjuntoSolicitud($id)
    {
        $s = LandingRequest::findOr($id, fn () => abort(404, 'La solicitud no existe.'));

        $disco = Storage::disk(LandingRequest::ADJUNTO_DISCO);

        if (! $s->attachment_path || ! $disco->exists($s->attachment_path)) {
            abort(404, 'Esta solicitud no trae adjunto.');
        }

        return $disco->download(
            $s->attachment_path,
            $s->attachment_name ?: basename($s->attachment_path),
        );
    }

    public function updateSolicitud(ActualizarSolicitudRequest $request, $id)
    {
        $s = LandingRequest::findOr($id, fn () => abort(404, 'La solicitud no existe.'));

        $datos = $request->validated();

        $nuevoEstado = (int) ($datos['state'] ?? $s->state);
        $resolucion = $datos['resolution'] ?? $s->resolution;

        /*
         * Descartar sin decir por qué deja a la siguiente persona sin saber si
         * ya se habló con ese tendero o si nadie lo miró. En una eliminación
         * de cuenta es además la constancia de qué se hizo con la petición.
         */
        if ($nuevoEstado === LandingRequest::DESCARTADA && trim((string) $resolucion) === '') {
            return response()->json([
                'message' => 'Para descartar una solicitud hay que escribir el motivo.',
            ], 422);
        }

        if ($nuevoEstado >= LandingRequest::ATENDIDA && $s->state < LandingRequest::ATENDIDA) {
            $datos['handled_at'] = now();
        }

        $s->forceFill($datos)->save();

        return response()->json(['message' => 'Solicitud actualizada.']);
    }

    /* ==================================================================
       CONTABILIDAD · LIQUIDACIONES
       ================================================================== */
}
