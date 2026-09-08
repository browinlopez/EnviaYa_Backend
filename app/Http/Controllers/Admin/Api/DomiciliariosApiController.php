<?php

namespace App\Http\Controllers\Admin\Api;

use App\Support\Consultas\AyudasDeAdmin;
use App\Services\Ajustes;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Conjunto\ComplexStaff;
use App\Models\Operacion\DomiciliaryDocument;
use App\Models\Reviews\BusinessReview;
use App\Models\Reviews\DomiciliaryReview;
use App\Models\User;
use App\Services\BusinessMediaService;
use App\Services\ContratoService;
use App\Services\MediaService;
use App\Services\VinculosDelRol;
use App\Support\ListadoPaginado;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DomiciliariosApiController extends Controller
{
    use AyudasDeAdmin;

    public function domiciliaries(Request $request)
    {
        return response()->json($this->resumenDomiciliarios(
            (int) $request->query('business_id') ?: null,
        ));
    }

    /**
     * Alta de un domiciliario.
     *
     * Un repartidor son DOS filas: la cuenta en `user` (rol 3) y su ficha en
     * `domiciliary`. Se admite crear la cuenta desde cero o vincular una que
     * ya exista, porque a veces la persona ya está registrada como comprador.
     */
    public function storeDomiciliary(Request $request)
    {
        $datos = $request->validate([
            'user_id'  => 'nullable|integer|exists:user,user_id',
            'name'     => 'required_without:user_id|string|max:255',
            'email'    => 'required_without:user_id|email|max:255|unique:user,email',
            'password' => 'required_without:user_id|string|min:6',
            'phone'    => 'nullable|string|max:20',
            'document' => 'nullable|string|max:50',
            'available' => 'sometimes|boolean',
        ]);

        $id = DB::transaction(function () use ($datos) {
            $userId = $datos['user_id'] ?? null;

            if ($userId) {
                if (DB::table('domiciliary')->where('user_id', $userId)->exists()) {
                    abort(422, 'Ese usuario ya está registrado como domiciliario.');
                }
                // Pasa a rol domiciliario: si sigue como comprador, la app lo
                // manda a la pantalla equivocada al iniciar sesión.
                DB::table('user')->where('user_id', $userId)->update(['rol' => 3]);
            } else {
                $userId = DB::table('user')->insertGetId([
                    'name'              => $datos['name'],
                    'email'             => $datos['email'],
                    'password'          => Hash::make($datos['password']),
                    'phone'             => $datos['phone'] ?? null,
                    'rol'               => 3,
                    'state'             => 1,
                    'qualification'     => 0,
                    'email_verified_at' => now(),
                    // La fecha la pone la aplicación y no la base: `CURRENT_TIMESTAMP`
                    // es el reloj del servidor, que en el VPS no es el de Bogotá.
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
            }

            return DB::table('domiciliary')->insertGetId([
                'user_id'       => $userId,
                'document'      => $datos['document'] ?? null,
                'available'     => $datos['available'] ?? 0,
                'qualification' => 0,
                'state'         => 1,
            ]);
        });

        return response()->json(['message' => 'Domiciliario creado.', 'domiciliary_id' => $id], 201);
    }

    /**
     * Ficha completa de un domiciliario.
     *
     * Devuelve todo lo que hace falta para juzgarlo de un vistazo: qué lleva
     * encima ahora, qué entregó últimamente, qué dicen de él y para qué
     * negocios trabaja. El panel lo abre en un solo modal, así que traerlo en
     * una sola respuesta evita cuatro peticiones en cascada.
     */
    public function showDomiciliary($id)
    {
        $d = $this->resumenDomiciliarios()->firstWhere('domiciliary_id', (int) $id);
        abort_if(!$d, 404, 'El domiciliario no existe.');

        $pedidos = fn() => $this->ordenesBase()->where('o.domiciliary_id', $id);

        $d->active_orders = $pedidos()
            ->whereIn('o.state', self::ACTIVOS)
            ->orderBy('o.sale_date')
            ->get();

        $d->recent_deliveries = $pedidos()
            ->where('o.state', self::ENTREGADO)
            ->orderByDesc('o.delivery_date')
            ->limit(10)
            ->get();

        $d->reviews = DB::table('domiciliary_reviews as r')
            ->leftJoin('buyer as b', 'b.buyer_id', '=', 'r.buyer_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'b.user_id')
            ->where('r.domiciliary_id', $id)
            ->orderByDesc('r.reviews_id')
            ->limit(10)
            ->get([
                'r.reviews_id', 'r.qualification', 'r.comment', 'r.created_at',
                'u.name as author_name',
            ]);

        $d->businesses = DB::table('business_domiciliary as bd')
            ->leftJoin('business as b', 'b.busines_id', '=', 'bd.busines_id')
            ->where('bd.domiciliary_id', $id)
            ->get(['b.busines_id', 'b.name', 'b.logo', 'b.address']);

        // Promedio de minutos en ruta. Solo cuentan los pedidos con las dos
        // marcas: estimar los que faltan inventaría un dato.
        $d->avg_minutes = DB::table('orderssales')
            ->where('domiciliary_id', $id)
            ->where('state', self::ENTREGADO)
            ->whereNotNull('dispatched_at')
            ->whereNotNull('delivery_date')
            ->avg(DB::raw('TIMESTAMPDIFF(MINUTE, dispatched_at, delivery_date)'));

        $d->contract = $this->contratoDe($d);

        return response()->json($d);
    }

    /* ==================================================================
       ACUERDO DE VINCULACIÓN
       ================================================================== */


    /** Datos del contrato archivado, o null si todavía no ha firmado. */
    private function contratoDe(object $d): ?array
    {
        if (!$d->contract_media_id) {
            return null;
        }

        $archivo = DB::table('media_files')->where('id', $d->contract_media_id)->first();

        // El archivo pudo borrarse desde la sección de documentos: entonces la
        // ficha debe decir que no hay contrato, no enlazar a un objeto muerto.
        if (!$archivo) {
            return null;
        }

        return [
            'media_id'  => (int) $archivo->id,
            'name'      => $archivo->original_name,
            'size'      => (int) $archivo->size_bytes,
            'url'       => app(MediaService::class)->url($archivo->object_key),
            'signed_at' => $d->contract_signed_at,
            'city'      => $d->contract_city,
        ];
    }

    /**
     * Genera el acuerdo de vinculación con la firma trazada en el panel.
     *
     * Se rehace desde cero cada vez: firmar de nuevo produce un documento
     * nuevo con su fecha, y el anterior se conserva en la carpeta de la
     * persona porque un acuerdo firmado no se sobrescribe.
     */
    public function signContract(Request $request, $id, ContratoService $contratos)
    {
        $datos = $request->validate([
            'signature' => 'required|string',
            'city'      => 'required|string|max:120',
            // Se puede corregir la cédula en el mismo paso: el acuerdo la
            // lleva impresa y firmarlo sin ella no tiene sentido.
            'document'  => 'sometimes|nullable|string|max:50',
        ]);

        $d = DB::table('domiciliary as d')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->where('d.domiciliary_id', $id)
            ->first(['d.domiciliary_id', 'd.user_id', 'd.document', 'u.name']);

        abort_if(!$d, 404, 'El domiciliario no existe.');

        // El documento corregido se usa para el acuerdo, pero NO se guarda
        // todavía: si la firma resulta inválida, la ficha no puede quedarse
        // con una cédula que nadie llegó a confirmar.
        $documentoNuevo = $datos['document'] ?? null;
        $d->document = $documentoNuevo ?: $d->document;

        if (!$d->document) {
            return response()->json([
                'message' => 'Registra el documento de identidad antes de firmar: el acuerdo lo lleva impreso.',
            ], 422);
        }

        try {
            $resultado = $contratos->generar(
                $d,
                $datos['signature'],
                $datos['city'],
                $request->user()->user_id ?? null,
                $request->user()->name ?? null,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($documentoNuevo) {
            DB::table('domiciliary')->where('domiciliary_id', $id)
                ->update(['document' => $documentoNuevo]);
        }

        return response()->json([
            'message'  => 'Acuerdo de vinculación firmado y archivado.',
            'contract' => $resultado,
        ], 201);
    }

    public function updateDomiciliary(Request $request, $id)
    {
        $domiciliario = DB::table('domiciliary')->where('domiciliary_id', $id)->first();
        abort_if(!$domiciliario, 404, 'El domiciliario no existe.');

        $datos = $request->validate([
            'available' => 'sometimes|boolean',
            'document'  => 'sometimes|nullable|string|max:50',
            'state'     => 'sometimes|boolean',
            // Bloqueo de la CUENTA: impide entrar a la app. No es lo mismo
            // que `available`, que solo dice si hoy acepta pedidos.
            'blocked'   => 'sometimes|boolean',
        ]);

        if (array_key_exists('blocked', $datos)) {
            $bloquear = (bool) $datos['blocked'];
            unset($datos['blocked']);

            $huerfanos = DB::table('orderssales')
                ->where('domiciliary_id', $id)
                ->whereIn('state', self::ACTIVOS)
                ->count();

            DB::transaction(function () use ($bloquear, $domiciliario, $id) {
                DB::table('user')
                    ->where('user_id', $domiciliario->user_id)
                    ->update(['state' => $bloquear ? 0 : 1]);

                if ($bloquear) {
                    // Sin revocar los tokens, quien ya tenía la sesión abierta
                    // sigue operando: el bloqueo solo aplicaría al próximo
                    // inicio de sesión, que puede no llegar nunca.
                    DB::table('personal_access_tokens')
                        ->where('tokenable_type', \App\Models\User::class)
                        ->where('tokenable_id', $domiciliario->user_id)
                        ->delete();

                    // Y se saca de la rueda de asignación, para que ningún
                    // tendero le despache mientras está bloqueado.
                    DB::table('domiciliary')->where('domiciliary_id', $id)->update(['available' => 0]);
                }
            });

            $respuesta = $this->showDomiciliary($id);

            // Bloquear a alguien que lleva pedidos encima los deja sin
            // repartidor: se avisa para que se reasignen, en vez de dejar que
            // se descubra cuando el cliente reclame.
            if ($bloquear && $huerfanos > 0) {
                $cuerpo = $respuesta->getData(true);
                $cuerpo['warning'] = "Quedaron {$huerfanos} pedido(s) en curso sin repartidor. Hay que reasignarlos.";
                $cuerpo['orphaned_orders'] = $huerfanos;
                return response()->json($cuerpo);
            }

            return $respuesta;
        }

        // Ponerlo como no disponible mientras carga pedidos dejaría entregas
        // huérfanas: el domiciliario ya no aparece para asignar, pero sigue
        // teniendo pedidos encima.
        if (array_key_exists('available', $datos) && !$datos['available']) {
            $activos = DB::table('orderssales')
                ->where('domiciliary_id', $id)
                ->whereIn('state', self::ACTIVOS)
                ->count();

            if ($activos > 0) {
                return response()->json([
                    'message' => "No se puede marcar como no disponible: tiene {$activos} entrega(s) en curso.",
                ], 422);
            }
        }

        if ($datos) {
            DB::table('domiciliary')->where('domiciliary_id', $id)->update($datos);
        }

        return $this->showDomiciliary($id);
    }

    /* ==================================================================
       PAGOS
       ================================================================== */
}
