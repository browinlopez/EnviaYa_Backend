<?php

namespace App\Http\Controllers\Conjunto;

use App\Events\CodigoDeAccesoUsado;
use App\Http\Controllers\Controller;
use App\Models\Domiciliary;
use App\Services\AccesoAlConjunto;
use App\Services\PulsoDelConjunto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * La portería del conjunto.
 *
 * El celador escanea el código de la app del domiciliario —o escribe su
 * cédula— y esto responde una sola pregunta: ¿tiene pedidos para ESTE
 * conjunto?
 *
 * El conjunto NO llega por parámetro. Lo pone `EnsureComplexStaff` desde la
 * sesión. Si viniera en la petición, un celador podría consultar la portería
 * de otro edificio cambiando un número, y de paso vería a qué apartamento va
 * cada pedido de un conjunto que no es el suyo.
 */
class PorteriaController extends Controller
{
    public function __construct(
        private AccesoAlConjunto $acceso,
        private PulsoDelConjunto $pulso,
    ) {
    }

    public function verificar(Request $request)
    {
        $datos = $request->validate([
            'code'     => 'required_without:document|nullable|string|max:20',
            'document' => 'required_without:code|nullable|string|max:50',
        ]);

        $complexId = (int) $request->attributes->get('complex_id');

        $porCodigo    = !empty($datos['code']);
        $domiciliario = $porCodigo
            ? $this->acceso->resolverCodigo($datos['code'])
            : $this->acceso->resolverCedula($datos['document']);

        if (!$domiciliario) {
            return response()->json([
                'message' => $porCodigo
                    // Se distingue a propósito: un código caducado es lo más
                    // frecuente, y decir "no existe" haría que el celador
                    // buscara el problema donde no está.
                    ? 'El código no es válido o ya venció. Pídele que genere uno nuevo.'
                    : 'No encontramos un domiciliario con esa cédula.',
                'allowed' => false,
            ], 404);
        }

        $pedidos = $this->acceso->pedidosEnElConjunto($domiciliario, $complexId);

        $conjunto = DB::table('residential_complexes')
            ->where('complex_id', $complexId)
            ->first(['name']);

        if ($pedidos->isEmpty()) {
            return response()->json([
                'message' => 'Este domiciliario no tiene pedidos en el conjunto',
                'allowed' => false,
                'domiciliary' => $this->ficha($domiciliario, $complexId),
            ]);
        }

        // Solo se registra la entrada cuando procede: una entrada anotada de
        // alguien a quien no se dejó pasar ensuciaría el historial.
        $entrada = DB::table('complex_entries')->insertGetId([
            'complex_id'     => $complexId,
            'domiciliary_id' => $domiciliario->domiciliary_id,
            'method'         => $porCodigo ? 'codigo' : 'cedula',
            'orders_count'   => $pedidos->count(),
            'orders'         => $pedidos->toJson(),
            'registered_by'  => $request->user()->user_id,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        /*
         * Se le avisa a SU app, y sólo cuando entró por código.
         *
         * Con la cédula no hay nada que gastar —sirve siempre— así que un aviso
         * de «tu código se usó» sobre una entrada por cédula sería falso.
         *
         * Va después de registrar la entrada: si el aviso falla —Reverb caído,
         * red del servidor— la entrada ya está anotada. Al revés, un fallo de
         * notificación dejaría a alguien pasando sin registro.
         */
        if ($porCodigo) {
            try {
                CodigoDeAccesoUsado::dispatch(
                    (int) $domiciliario->user_id,
                    (string) ($conjunto?->name ?? 'el conjunto'),
                    $pedidos->count(),
                );
            } catch (\Throwable $e) {
                // Que no se pueda avisar no puede tumbar la portería: la
                // persona está en la puerta y el celador espera una respuesta.
                report($e);
            }
        }

        return response()->json([
            'message'     => 'Puede entrar.',
            'allowed'     => true,
            'entry_id'    => $entrada,
            'domiciliary' => $this->ficha($domiciliario, $complexId),
            'orders'      => $pedidos,
        ]);
    }

    /** Las entradas registradas en este conjunto. */
    public function entradas(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        $consulta = DB::table('complex_entries as e')
            ->where('e.complex_id', $complexId)
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'e.domiciliary_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            // Quién estaba en la portería cuando pasó. Es la mitad del valor
            // de un registro de entradas: sin eso dice qué ocurrió y no bajo
            // la responsabilidad de quién.
            ->leftJoin('user as c', 'c.user_id', '=', 'e.registered_by');

        if ($buscar = trim((string) $request->query('search'))) {
            $consulta->where(function ($q) use ($buscar) {
                $q->where('u.name', 'like', "%{$buscar}%")
                    ->orWhere('d.document', 'like', "%{$buscar}%");
            });
        }

        if ($metodo = $request->query('method')) {
            if (in_array($metodo, ['codigo', 'cedula', 'manual'], true)) {
                $consulta->where('e.method', $metodo);
            }
        }

        // Domiciliarios de la plataforma o gente de fuera. Son dos libros
        // distintos que comparten tabla, y casi siempre se consulta uno.
        if ($clase = $request->query('kind')) {
            if ($clase === 'externos') {
                $consulta->where('e.kind', '!=', 'domiciliario');
            } else {
                $consulta->where('e.kind', $clase);
            }
        }

        // Quien sigue adentro. Es la consulta que convierte el libro en
        // control de acceso.
        if ($request->boolean('dentro')) {
            $consulta->whereNull('e.exited_at');
        }

        if ($desde = $request->query('desde')) {
            $consulta->whereDate('e.created_at', '>=', $desde);
        }

        if ($hasta = $request->query('hasta')) {
            $consulta->whereDate('e.created_at', '<=', $hasta);
        }

        $filas = $consulta
            ->orderByDesc('e.created_at')
            ->limit(300)
            ->get([
                'e.id', 'e.kind', 'e.method', 'e.orders_count', 'e.created_at',
                'e.exited_at',
                // Los del visitante. Van junto a los del domiciliario porque
                // el listado es uno solo: la portería no lleva dos libros.
                'e.visitor_name', 'e.visitor_document', 'e.visitor_phone',
                'e.visitor_company', 'e.vehicle_plate',
                'e.tower', 'e.apartment', 'e.authorized_by', 'e.notes',
                // El detalle guardado al entrar: a qué torre y apartamento iba
                // cada pedido. Se congeló en ese momento a propósito — si se
                // recalculara, un pedido entregado después ya no aparecería y
                // el registro de una entrada pasada cambiaría solo.
                'e.orders',
                'u.name as domiciliary_name', 'd.document',
                'c.name as registered_by_name',
            ]);

        return response()->json(['data' => $filas]);
    }

    /**
     * Quién es quien está en la puerta.
     *
     * Lleva más de lo que hace falta para decidir si pasa —eso ya lo decidió
     * la consulta de pedidos— porque la decisión no siempre es automática. Un
     * celador que ve «primera vez que entra» mira con más cuidado que uno que
     * ve «lleva 40 entradas», y sin el dato las dos situaciones se ven
     * exactamente igual.
     *
     * La calificación y el historial son del DOMICILIARIO, no del comprador:
     * acá no se expone nada de quien pidió.
     */
    private function ficha(Domiciliary $d, int $complexId): array
    {
        return [
            'domiciliary_id' => $d->domiciliary_id,
            'name'           => $d->user->name ?? null,
            'document'       => $d->document,
            'phone'          => $d->user->phone ?? null,
            'qualification'  => $d->qualification !== null ? (float) $d->qualification : null,
            // Si se marcó disponible en su app. No impide entrar —ya tiene los
            // pedidos encima— pero explica por qué a veces no aparece en la
            // lista del tendero.
            'available'      => (bool) $d->available,
            'activo'         => (bool) $d->state,
            'historial'      => $this->pulso->historialEnElConjunto($complexId, (int) $d->domiciliary_id),
        ];
    }
}
