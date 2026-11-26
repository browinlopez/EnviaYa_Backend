<?php

declare(strict_types=1);

namespace App\Http\Controllers\Conjunto;

use App\Http\Controllers\Controller;
use App\Models\Conjunto\ComplexShift;
use App\Models\Conjunto\ComplexStaff;
use App\Support\FiltroDeFechas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * LOS TURNOS DE LA PORTERÍA.
 *
 * El celador marca cuándo empieza y cuándo termina; el administrador ve quién
 * está en la puerta ahora y los turnos de los últimos días. Uno abierto por
 * celador: empezar otro con uno abierto es un error de dedo, no dos turnos.
 *
 * El conjunto sale de la sesión (`EnsureComplexStaff`), como en todo el panel.
 */
class TurnosController extends Controller
{
    private function soloCelador(Request $request): ?JsonResponse
    {
        if ($request->attributes->get('complex_role') !== ComplexStaff::CELADOR) {
            return response()->json(['message' => 'Los turnos son de los celadores.'], 403);
        }

        return null;
    }

    /** El turno abierto de quien pide (o null). */
    public function mio(Request $request)
    {
        if ($no = $this->soloCelador($request)) {
            return $no;
        }

        return response()->json(['data' => $this->forma(ComplexShift::abiertoDe((int) $request->user()->user_id))]);
    }

    public function iniciar(Request $request)
    {
        if ($no = $this->soloCelador($request)) {
            return $no;
        }

        $userId = (int) $request->user()->user_id;
        $complexId = (int) $request->attributes->get('complex_id');

        /*
         * Con bloqueo sobre la ficha: dos toques seguidos al botón no pueden
         * abrir dos turnos. MySQL no tiene índice único parcial, así que la
         * regla vive aquí.
         */
        [$turno, $nuevo] = DB::transaction(function () use ($userId, $complexId) {
            ComplexStaff::where('user_id', $userId)->lockForUpdate()->first();

            if ($abierto = ComplexShift::abiertoDe($userId)) {
                return [$abierto, false];
            }

            return [ComplexShift::create([
                'complex_id' => $complexId,
                'user_id' => $userId,
                'started_at' => now()->toDateTimeString(),
            ]), true];
        });

        if (! $nuevo) {
            return response()->json([
                'message' => 'Ya tienes un turno abierto.',
                'data' => $this->forma($turno),
            ], 409);
        }

        return response()->json(['message' => 'Turno iniciado.', 'data' => $this->forma($turno->fresh())], 201);
    }

    public function terminar(Request $request)
    {
        if ($no = $this->soloCelador($request)) {
            return $no;
        }

        $turno = ComplexShift::cerrarDe((int) $request->user()->user_id);

        if (! $turno) {
            return response()->json(['message' => 'No tienes un turno abierto.'], 422);
        }

        return response()->json(['message' => 'Turno terminado.', 'data' => $this->forma($turno->fresh())]);
    }

    /**
     * Los turnos del conjunto, del administrador: los abiertos primero y luego
     * los más recientes. `abiertos=1` deja solo quién está en la puerta ahora.
     */
    public function index(Request $request)
    {
        $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $complexId = (int) $request->attributes->get('complex_id');
        $porPagina = min(100, max(1, (int) $request->query('per_page', 30)));

        // Por el día en que EMPEZÓ el turno: el de la noche del lunes es del lunes.
        $consulta = DB::table('complex_shifts as s')
            ->where('s.complex_id', $complexId)
            ->join('user as u', 'u.user_id', '=', 's.user_id')
            ->when($request->boolean('abiertos'), fn ($q) => $q->whereNull('s.ended_at'))
            ->when($request->query('user_id'), fn ($q, $id) => $q->where('s.user_id', (int) $id))
            ->when($request->query('desde'), fn ($q, $d) => FiltroDeFechas::desde($q, 's.started_at', $d))
            ->when($request->query('hasta'), fn ($q, $d) => FiltroDeFechas::hasta($q, 's.started_at', $d));

        /*
         * El resumen del rango, sobre TODOS los turnos y no sobre la página:
         * cuántos hubo, cuántas horas sumaron y quién está en la puerta ahora.
         */
        $todos = (clone $consulta)->get(['s.started_at', 's.ended_at']);
        $minutos = $todos->sum(function ($t) {
            $fin = $t->ended_at ? strtotime((string) $t->ended_at) : now()->getTimestamp();

            return max(0, (int) floor(($fin - strtotime((string) $t->started_at)) / 60));
        });

        $pagina = $consulta
            ->orderByRaw('s.ended_at IS NOT NULL')
            ->orderByDesc('s.started_at')
            ->paginate($porPagina, ['s.id', 's.user_id', 'u.name', 's.started_at', 's.ended_at']);

        return response()->json([
            'data' => collect($pagina->items())->map(fn ($t) => $this->forma($t)),
            'resumen' => [
                'turnos' => $todos->count(),
                'minutos' => $minutos,
                'abiertos' => $todos->whereNull('ended_at')->count(),
            ],
            'meta' => [
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'total' => $pagina->total(),
                'last_page' => $pagina->lastPage(),
            ],
        ]);
    }

    /** Un turno, con su duración en minutos (hasta ahora si sigue abierto). */
    private function forma(?object $t): ?array
    {
        if (! $t) {
            return null;
        }

        $inicio = strtotime((string) $t->started_at);
        $fin = $t->ended_at ? strtotime((string) $t->ended_at) : now()->getTimestamp();

        return array_filter([
            'id' => (int) $t->id,
            'user_id' => (int) $t->user_id,
            'name' => $t->name ?? null,
            'started_at' => (string) $t->started_at,
            'ended_at' => $t->ended_at ? (string) $t->ended_at : null,
            'abierto' => $t->ended_at === null,
            'minutos' => max(0, (int) floor(($fin - $inicio) / 60)),
        ], fn ($v, $k) => $k !== 'name' || $v !== null, ARRAY_FILTER_USE_BOTH);
    }
}
