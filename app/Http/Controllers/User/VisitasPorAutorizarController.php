<?php

declare(strict_types=1);

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\ResponderVisitaRequest;
use App\Models\Conjunto\SolicitudDeEntrada;
use App\Services\AutorizacionDelResidente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * EL LADO DEL RESIDENTE: «¿DEJAS ENTRAR A ESTA PERSONA?».
 *
 * Solo contesta quien vive en ese apartamento según sus direcciones de la
 * app. Responde una vez: la primera respuesta es la que vale, y lo que
 * conteste otro residente después ya no cambia nada.
 */
class VisitasPorAutorizarController extends Controller
{
    public function __construct(private readonly AutorizacionDelResidente $residentes) {}

    /** Las que esperan respuesta, para la pantalla que abre el aviso. */
    public function pendientes(Request $request)
    {
        $userId = (int) $request->user()->user_id;

        $suyas = DB::table('user_address')
            ->where('user_id', $userId)
            ->where('state', 1)
            ->whereNotNull('complex_id')
            ->get(['complex_id', 'tower', 'apartment']);

        if ($suyas->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $solicitudes = SolicitudDeEntrada::query()
            ->where('state', SolicitudDeEntrada::PENDIENTE)
            ->where('expires_at', '>', now())
            ->where(function ($q) use ($suyas) {
                foreach ($suyas as $d) {
                    $q->orWhere(fn ($w) => $w
                        ->where('complex_id', $d->complex_id)
                        ->whereRaw('LOWER(TRIM(tower)) = ?', [mb_strtolower(trim((string) $d->tower))])
                        ->whereRaw('LOWER(TRIM(apartment)) = ?', [mb_strtolower(trim((string) $d->apartment))]));
                }
            })
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $nombres = DB::table('residential_complexes')
            ->whereIn('complex_id', $solicitudes->pluck('complex_id'))
            ->pluck('name', 'complex_id');

        return response()->json([
            'data' => $solicitudes->map(fn (SolicitudDeEntrada $s) => [
                'id' => $s->id,
                'visitor_name' => $s->visitor_name,
                'kind' => $s->kind,
                'tower' => $s->tower,
                'apartment' => $s->apartment,
                'conjunto' => $nombres[$s->complex_id] ?? null,
                'expires_at' => $s->expires_at->toIso8601String(),
            ])->values(),
        ]);
    }

    public function responder(ResponderVisitaRequest $request, int $id)
    {
        $solicitud = SolicitudDeEntrada::find($id);
        $userId = (int) $request->user()->user_id;

        // 404 también a quien no vive ahí: no se confirma que exista.
        if (! $solicitud || ! $this->residentes->esResidente($userId, $solicitud)) {
            return response()->json(['message' => 'Esa visita no es para tu apartamento.'], 404);
        }

        $estado = $solicitud->estadoVigente();

        if ($estado !== SolicitudDeEntrada::PENDIENTE) {
            return response()->json([
                'message' => match ($estado) {
                    SolicitudDeEntrada::VENCIDA => 'Ya pasó el tiempo para contestar. Si sigue en la portería, el celador te llamará.',
                    default => 'Alguien de tu apartamento ya contestó.',
                },
                'state' => $estado,
            ], 409);
        }

        /*
         * Una sola respuesta: la actualización solo pasa si sigue pendiente.
         * Dos residentes contestando en el mismo segundo no se pisan.
         */
        $nuevo = $request->validated('decision') === 'si'
            ? SolicitudDeEntrada::AUTORIZADA
            : SolicitudDeEntrada::RECHAZADA;

        $cambiadas = SolicitudDeEntrada::where('id', $solicitud->id)
            ->where('state', SolicitudDeEntrada::PENDIENTE)
            ->update(['state' => $nuevo, 'answered_by' => $userId, 'answered_at' => now()]);

        if (! $cambiadas) {
            return response()->json(['message' => 'Alguien de tu apartamento ya contestó.'], 409);
        }

        return response()->json([
            'message' => $nuevo === SolicitudDeEntrada::AUTORIZADA
                ? 'Le avisamos al celador que puede entrar.'
                : 'Le avisamos al celador que no entra.',
            'state' => $nuevo,
        ]);
    }
}
