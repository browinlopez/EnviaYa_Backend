<?php

declare(strict_types=1);

namespace App\Http\Controllers\Conjunto;

use App\Http\Controllers\Controller;
use App\Http\Requests\Conjunto\PedirAutorizacionRequest;
use App\Models\Conjunto\SolicitudDeEntrada;
use App\Services\AutorizacionDelResidente;
use App\Services\Avisos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Sin residentes con la app se dice enseguida: esperar algo que no llega es peor.
class AutorizacionesController extends Controller
{
    public function __construct(private readonly AutorizacionDelResidente $residentes) {}

    public function pedir(PedirAutorizacionRequest $request)
    {
        $datos = $request->validated();
        $complexId = (int) $request->attributes->get('complex_id');

        $quienes = $this->residentes->residentesDe($complexId, $datos['tower'], $datos['apartment']);

        if ($quienes->isEmpty()) {
            return response()->json([
                'message' => 'En ese apartamento nadie tiene la app de VeciPa\'Ya. Llama al citófono.',
                'reason' => 'sin_residentes',
            ], 422);
        }

        $solicitud = SolicitudDeEntrada::create([
            'complex_id' => $complexId,
            'tower' => trim($datos['tower']),
            'apartment' => trim($datos['apartment']),
            'visitor_name' => trim($datos['visitor_name']),
            'kind' => $datos['kind'] ?? 'visitante',
            'state' => SolicitudDeEntrada::PENDIENTE,
            'residents_notified' => $quienes->count(),
            'requested_by' => $request->user()->user_id,
            'expires_at' => now()->addMinutes(SolicitudDeEntrada::VIGENCIA_MINUTOS),
        ]);

        $conjunto = DB::table('residential_complexes')->where('complex_id', $complexId)->value('name') ?? 'tu conjunto';
        $quien = $solicitud->kind === 'servicio' ? 'Un servicio' : $solicitud->visitor_name;

        foreach ($quienes as $userId) {
            Avisos::para(
                $userId,
                'visita_por_autorizar',
                "{$quien} está en la portería de {$conjunto} para la torre {$solicitud->tower}, apto {$solicitud->apartment}. ¿Lo dejas entrar?",
                ['solicitud_id' => $solicitud->id],
                entrega: [
                    // La misma etiqueta deja reemplazarlo por «ya contestaron».
                    'etiqueta' => "visita-{$solicitud->id}",
                    // Pasados los cinco minutos ya no tiene sentido que suene.
                    'vence' => $solicitud->expires_at->getTimestamp(),
                ],
            );
        }

        return response()->json($this->forma($solicitud), 201);
    }

    // Respaldo del panel por si el tiempo real no está.
    public function ver(Request $request, int $id)
    {
        $solicitud = SolicitudDeEntrada::where('id', $id)
            ->where('complex_id', (int) $request->attributes->get('complex_id'))
            ->first();

        if (! $solicitud) {
            return response()->json(['message' => 'Esa solicitud no es de tu conjunto.'], 404);
        }

        return response()->json($this->forma($solicitud));
    }

    /** @return array<string, mixed> */
    private function forma(SolicitudDeEntrada $s): array
    {
        $estado = $s->estadoVigente();

        return [
            'id' => $s->id,
            'state' => $estado,
            'residents_notified' => $s->residents_notified,
            'expires_at' => $s->expires_at->toIso8601String(),
            'answered_at' => $s->answered_at?->toIso8601String(),
            // El nombre de quien contestó: va a «Quién autorizó» del registro.
            'answered_by_name' => $s->answered_by
                ? DB::table('user')->where('user_id', $s->answered_by)->value('name')
                : null,
        ];
    }
}
