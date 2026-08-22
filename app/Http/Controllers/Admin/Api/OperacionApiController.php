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

/**
 * SST, CALIDAD Y CONTABILIDAD
 *
 * Los tres módulos que le faltaban al panel para que esas áreas pudieran
 * trabajar. Van juntos en un controlador porque comparten forma —listado con
 * tablero, ficha, cambio de estado— y separarlos en tres archivos de doscientas
 * líneas no haría más fácil encontrar nada.
 *
 * Cada bloque está detrás de su propio `modulo:` en las rutas, así que
 * compartir archivo no significa compartir permisos: SST no alcanza las
 * liquidaciones aunque el código viva al lado.
 */
class OperacionApiController extends Controller
{
    /* ==================================================================
       SST · DOCUMENTACIÓN
       ================================================================== */

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

    public function solicitudes(Request $request)
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
                'nuevas'     => LandingRequest::where('state', LandingRequest::NUEVA)->count(),
                'en_gestion' => LandingRequest::where('state', LandingRequest::EN_GESTION)->count(),
                /*
                 * Vencidas son, hoy por hoy, solo eliminaciones de cuenta: son
                 * las únicas con plazo comprometido. Que aparezcan aparte no es
                 * un adorno: pasarse de los quince días hábiles prometidos en
                 * /eliminar-cuenta es un incumplimiento público, no un retraso
                 * interno.
                 */
                'vencidas'   => LandingRequest::whereIn('state', [LandingRequest::NUEVA, LandingRequest::EN_GESTION])
                    ->whereNotNull('due_at')
                    ->where('due_at', '<', now())
                    ->count(),
                'mes'        => LandingRequest::whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->count(),
                // Cuántas hay de cada tipo, para el tablero del panel.
                'por_tipo'   => LandingRequest::selectRaw('type, count(*) as total')
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

    public function updateSolicitud(Request $request, $id)
    {
        $s = LandingRequest::findOr($id, fn () => abort(404, 'La solicitud no existe.'));

        $datos = $request->validate([
            'state'       => 'sometimes|integer|in:0,1,2,3',
            'assigned_to' => 'sometimes|nullable|integer|exists:user,user_id',
            'resolution'  => 'sometimes|nullable|string',
        ]);

        $nuevoEstado = (int) ($datos['state'] ?? $s->state);
        $resolucion  = $datos['resolution'] ?? $s->resolution;

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

    public function liquidaciones(Request $request)
    {
        $q = Settlement::query()
            ->leftJoin('business as b', 'b.busines_id', '=', 'settlements.business_id')
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'settlements.domiciliary_id')
            ->leftJoin('user as du', 'du.user_id', '=', 'd.user_id')
            ->orderByDesc('settlements.id');

        if ($request->filled('type')) {
            $q->where('settlements.type', $request->query('type'));
        }

        if ($request->filled('state')) {
            $q->where('settlements.state', (int) $request->query('state'));
        }

        // El join a `business` ya estaba para el nombre: filtrar por negocio
        // no cuesta nada más.
        if ($request->filled('business_id')) {
            $q->where('settlements.business_id', (int) $request->query('business_id'));
        }

        return response()->json([
            'data' => $q->get([
                'settlements.*',
                'b.name as business_name',
                'du.name as domiciliary_name',
            ]),
            'summary' => [
                'por_pagar' => (float) Settlement::whereIn('state', [Settlement::BORRADOR, Settlement::APROBADA])
                    ->sum('net_payable'),
                'pagado_mes' => (float) Settlement::where('state', Settlement::PAGADA)
                    ->whereMonth('paid_at', now()->month)
                    ->whereYear('paid_at', now()->year)
                    ->sum('net_payable'),
                'borradores' => Settlement::where('state', Settlement::BORRADOR)->count(),
            ],
        ]);
    }

    public function showLiquidacion($id)
    {
        $s = Settlement::with('items')->findOr($id, fn () => abort(404, 'La liquidación no existe.'));

        return response()->json($s);
    }

    public function generarLiquidacion(Request $request, LiquidacionService $servicio)
    {
        $datos = $request->validate([
            'type'         => 'required|in:business,domiciliary',
            'target_id'    => 'required|integer',
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
        ]);

        try {
            $liq = $servicio->generar(
                $datos['type'],
                (int) $datos['target_id'],
                $datos['period_start'],
                $datos['period_end'],
                $request->user()?->user_id,
            );
        } catch (RuntimeException $e) {
            // "No hay pedidos sin liquidar" es información útil, no un fallo:
            // llega tal cual para que quien genera sepa qué pasó.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Liquidación generada con {$liq->orders_count} pedido(s).",
            'id'      => $liq->id,
        ], 201);
    }

    public function updateLiquidacion(Request $request, $id)
    {
        $s = Settlement::findOr($id, fn () => abort(404, 'La liquidación no existe.'));

        $datos = $request->validate([
            'state'             => 'sometimes|integer|in:0,1,2,3',
            'payment_reference' => 'sometimes|nullable|string|max:100',
            'notes'             => 'sometimes|nullable|string|max:500',
        ]);

        $nuevo = (int) ($datos['state'] ?? $s->state);

        // Una vez aprobada, los montos son constancia. Solo se admite avanzar
        // en el ciclo o anular; volver a borrador reabriría lo ya facturado.
        if (!$s->editable() && $nuevo < $s->state && $nuevo !== Settlement::ANULADA) {
            return response()->json([
                'message' => 'Una liquidación aprobada no vuelve a borrador. Anúlala y genera una nueva.',
            ], 422);
        }

        if ($nuevo === Settlement::PAGADA && empty($datos['payment_reference'] ?? $s->payment_reference)) {
            return response()->json([
                'message' => 'Para marcarla como pagada hace falta la referencia de la transferencia.',
            ], 422);
        }

        if ($nuevo === Settlement::APROBADA && $s->state === Settlement::BORRADOR) {
            $datos['approved_at'] = now();
        }

        if ($nuevo === Settlement::PAGADA && $s->state !== Settlement::PAGADA) {
            $datos['paid_at'] = now();
        }

        $s->forceFill($datos)->save();

        return response()->json(['message' => 'Liquidación actualizada.']);
    }

    public function deleteLiquidacion($id)
    {
        $s = Settlement::findOr($id, fn () => abort(404, 'La liquidación no existe.'));

        if (!$s->editable()) {
            return response()->json([
                'message' => 'Solo se puede eliminar una liquidación en borrador. Si ya se aprobó, anúlala.',
            ], 422);
        }

        $s->delete();

        return response()->json(['message' => 'Liquidación eliminada.']);
    }
}
