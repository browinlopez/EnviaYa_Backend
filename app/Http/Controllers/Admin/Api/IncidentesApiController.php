<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IncidenteRequest;
use App\Models\Operacion\Pqrs;
use App\Models\Operacion\SafetyIncident;
use App\Support\FiltroDeFechas;
use Illuminate\Http\Request;

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
            'data' => $filas,
            'summary' => [
                'abiertos' => (clone $todos)->where('state', SafetyIncident::ABIERTO)->count(),
                'investigando' => (clone $todos)->where('state', SafetyIncident::EN_INVESTIGACION)->count(),
                'graves_anio' => FiltroDeFechas::elAnio(
                    (clone $todos)->where('severity', 'grave'),
                    'occurred_at', (int) now()->year,
                )->count(),
                // Días de incapacidad acumulados: es el número que piden la ARL
                // y los indicadores de severidad.
                'dias_incapacidad_anio' => (int) FiltroDeFechas::elAnio(
                    (clone $todos), 'occurred_at', (int) now()->year,
                )->sum('days_off'),
            ],
        ]);
    }

    public function storeIncidente(IncidenteRequest $request)
    {
        $datos = $request->validated();
        $datos['reported_by'] = $request->user()?->user_id;

        $i = SafetyIncident::create($datos);

        return response()->json(['message' => 'Incidente registrado.', 'id' => $i->id], 201);
    }

    public function updateIncidente(Request $request, $id)
    {
        $i = SafetyIncident::findOr($id, fn () => abort(404, 'El incidente no existe.'));
        /*
         * EN LINEA Y NO EN UN FormRequest, a proposito: la busqueda de arriba
         * contesta 404 cuando el registro no existe, y eso tiene que ir ANTES
         * que un error de validacion. Las reglas no se repiten: se piden a
         * `IncidenteRequest` en modo parcial.
         */
        $datos = $request->validate(IncidenteRequest::reglas(parcial: true));

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

    /* ==================================================================
       CALIDAD · PQRS
       ================================================================== */
}
