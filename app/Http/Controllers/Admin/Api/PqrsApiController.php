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

class PqrsApiController extends Controller
{

    public function pqrs(Request $request)
    {
        $q = Pqrs::query()
            ->leftJoin('user as u', 'u.user_id', '=', 'pqrs.user_id')
            ->leftJoin('user as a', 'a.user_id', '=', 'pqrs.assigned_to')
            ->leftJoin('business as b', 'b.busines_id', '=', 'pqrs.business_id')
            ->orderByDesc('pqrs.id');

        if ($request->filled('state')) {
            $q->where('pqrs.state', (int) $request->query('state'));
        }

        if ($request->filled('type')) {
            $q->where('pqrs.type', $request->query('type'));
        }

        $filas = $q->get([
            'pqrs.*',
            'u.name as user_name',
            'a.name as assignee_name',
            'b.name as business_name',
        ]);

        return response()->json([
            'data' => $filas->map(function ($p) {
                $modelo = (new Pqrs())->forceFill((array) $p);
                $p->overdue = $modelo->vencido();
                return $p;
            }),
            'summary' => [
                'abiertos'   => Pqrs::where('state', Pqrs::ABIERTO)->count(),
                'en_gestion' => Pqrs::where('state', Pqrs::EN_GESTION)->count(),
                'vencidos'   => Pqrs::whereIn('state', [Pqrs::ABIERTO, Pqrs::EN_GESTION])
                    ->whereNotNull('due_at')
                    ->where('due_at', '<', now())
                    ->count(),
                'resueltos_mes' => Pqrs::where('state', '>=', Pqrs::RESUELTO)
                    ->whereMonth('resolved_at', now()->month)
                    ->whereYear('resolved_at', now()->year)
                    ->count(),
                'plazos' => Pqrs::PLAZOS,
            ],
        ]);
    }

    public function showPqrs($id)
    {
        $p = Pqrs::with('notes.author:user_id,name')
            ->findOr($id, fn () => abort(404, 'El PQRS no existe.'));

        return response()->json($p);
    }

    public function storePqrs(Request $request)
    {
        $datos = $request->validate([
            'type'           => 'required|in:peticion,queja,reclamo,sugerencia,felicitacion',
            'channel'        => 'sometimes|in:app,whatsapp,llamada,correo,panel',
            'priority'       => 'sometimes|in:baja,media,alta',
            'user_id'        => 'sometimes|nullable|integer|exists:user,user_id',
            'order_id'       => 'sometimes|nullable|integer|exists:orderssales,orderSales_id',
            'business_id'    => 'sometimes|nullable|integer|exists:business,busines_id',
            'domiciliary_id' => 'sometimes|nullable|integer|exists:domiciliary,domiciliary_id',
            'contact_name'   => 'sometimes|nullable|string|max:150',
            'contact_email'  => 'sometimes|nullable|email|max:150',
            'contact_phone'  => 'sometimes|nullable|string|max:30',
            'subject'        => 'required|string|max:200',
            'description'    => 'required|string',
            'assigned_to'    => 'sometimes|nullable|integer|exists:user,user_id',
        ]);

        $prioridad = $datos['priority'] ?? 'media';

        $datos['code']   = Pqrs::siguienteRadicado();
        // El plazo se fija al radicar y no se recalcula: subirle la urgencia a
        // un caso atrasado no puede hacerlo aparecer como si estuviera a tiempo.
        $datos['due_at'] = now()->addDays(Pqrs::PLAZOS[$prioridad]);

        $p = Pqrs::create($datos);

        return response()->json([
            'message' => "Radicado {$p->code} creado.",
            'id'      => $p->id,
            'code'    => $p->code,
        ], 201);
    }

    public function updatePqrs(Request $request, $id)
    {
        $p = Pqrs::findOr($id, fn () => abort(404, 'El PQRS no existe.'));

        $datos = $request->validate([
            'priority'    => 'sometimes|in:baja,media,alta',
            'state'       => 'sometimes|integer|in:0,1,2,3',
            'assigned_to' => 'sometimes|nullable|integer|exists:user,user_id',
            'resolution'  => 'sometimes|nullable|string',
        ]);

        $nuevoEstado = (int) ($datos['state'] ?? $p->state);
        $resolucion  = $datos['resolution'] ?? $p->resolution;

        // Resolver sin decir cómo deja al cliente sin respuesta y al caso sin
        // historia: es lo que se necesita cuando vuelve a reclamar.
        if ($nuevoEstado >= Pqrs::RESUELTO && trim((string) $resolucion) === '') {
            return response()->json([
                'message' => 'Para resolver o cerrar un PQRS hay que escribir la respuesta.',
            ], 422);
        }

        if ($nuevoEstado >= Pqrs::RESUELTO && $p->state < Pqrs::RESUELTO) {
            $datos['resolved_at'] = now();
        }

        $p->forceFill($datos)->save();

        return response()->json(['message' => 'PQRS actualizado.']);
    }

    public function addPqrsNote(Request $request, $id)
    {
        Pqrs::findOr($id, fn () => abort(404, 'El PQRS no existe.'));

        $datos = $request->validate([
            'note'        => 'required|string',
            'is_internal' => 'sometimes|boolean',
        ]);

        PqrsNote::create([
            'pqrs_id'     => $id,
            'user_id'     => $request->user()?->user_id,
            'note'        => $datos['note'],
            'is_internal' => $datos['is_internal'] ?? false,
        ]);

        return response()->json(['message' => 'Seguimiento agregado.'], 201);
    }

    /* ==================================================================
       SOLICITUDES DE LA WEB PÚBLICA
       Lo que mandan los formularios de la landing: comercios y domiciliarios
       que quieren entrar, conjuntos que piden que los den de alta, barrios que
       piden cobertura y personas que piden que les borren la cuenta.

       No se pueden crear desde el panel a propósito: nacen en el formulario
       público, y una fila escrita a mano acá no tendría autorización de
       tratamiento de datos detrás.
       ================================================================== */
}
