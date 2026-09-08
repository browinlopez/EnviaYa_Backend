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

class IncidentesApiController extends Controller
{

    public function incidentes(Request $request)
    {
        $q = SafetyIncident::query()
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'safety_incidents.domiciliary_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->orderByDesc('safety_incidents.occurred_at');

        if ($request->filled('state')) {
            $q->where('safety_incidents.state', (int) $request->query('state'));
        }

        $filas = $q->get(['safety_incidents.*', 'u.name as domiciliary_name']);

        $todos = SafetyIncident::query();

        return response()->json([
            'data'    => $filas,
            'summary' => [
                'abiertos'     => (clone $todos)->where('state', SafetyIncident::ABIERTO)->count(),
                'investigando' => (clone $todos)->where('state', SafetyIncident::EN_INVESTIGACION)->count(),
                'graves_anio'  => (clone $todos)->where('severity', 'grave')
                    ->whereYear('occurred_at', now()->year)->count(),
                // Días de incapacidad acumulados: es el número que piden la ARL
                // y los indicadores de severidad.
                'dias_incapacidad_anio' => (int) (clone $todos)
                    ->whereYear('occurred_at', now()->year)->sum('days_off'),
            ],
        ]);
    }

    public function storeIncidente(Request $request)
    {
        $datos = $this->validarIncidente($request);
        $datos['reported_by'] = $request->user()?->user_id;

        $i = SafetyIncident::create($datos);

        return response()->json(['message' => 'Incidente registrado.', 'id' => $i->id], 201);
    }

    public function updateIncidente(Request $request, $id)
    {
        $i = SafetyIncident::findOr($id, fn () => abort(404, 'El incidente no existe.'));

        $datos = $this->validarIncidente($request, true);

        // Cerrar sin decir qué se hizo deja un registro que solo sirve para
        // contar cuántos hubo, y el módulo existe para que no se repitan.
        $cerrando = (int) ($datos['state'] ?? $i->state) === SafetyIncident::CERRADO;
        $acciones = $datos['actions'] ?? $i->actions;

        if ($cerrando && trim((string) $acciones) === '') {
            return response()->json([
                'message' => 'Para cerrar un incidente hay que registrar qué acciones se tomaron.',
            ], 422);
        }

        if ($cerrando && $i->state !== SafetyIncident::CERRADO) {
            $datos['closed_at'] = now();
        }

        $i->forceFill($datos)->save();

        return response()->json(['message' => 'Incidente actualizado.']);
    }

    private function validarIncidente(Request $request, bool $parcial = false): array
    {
        $regla = $parcial ? 'sometimes' : 'required';

        return $request->validate([
            'domiciliary_id' => 'sometimes|nullable|integer|exists:domiciliary,domiciliary_id',
            'order_id'       => 'sometimes|nullable|integer|exists:orderssales,orderSales_id',
            'occurred_at'    => "{$regla}|date",
            'type'           => "{$regla}|in:accidente_transito,caida,robo,agresion,falla_vehiculo,condicion_insegura,otro",
            'severity'       => 'sometimes|in:leve,moderado,grave',
            'had_injuries'   => 'sometimes|boolean',
            'days_off'       => 'sometimes|integer|min:0|max:2000',
            'location'       => 'sometimes|nullable|string|max:255',
            'description'    => "{$regla}|string",
            'actions'        => 'sometimes|nullable|string',
            'state'          => 'sometimes|integer|in:0,1,2',
        ]);
    }

    /* ==================================================================
       CALIDAD · PQRS
       ================================================================== */
}
