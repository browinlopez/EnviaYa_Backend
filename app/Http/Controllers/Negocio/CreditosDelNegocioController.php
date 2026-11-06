<?php

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Models\Business\BusinessUserAffiliation;
use App\Models\Operacion\StoreCredit;
use App\Models\Operacion\StoreCreditMovement;
use App\Models\User;
use App\Services\CreditoDeTienda;
use App\Services\MediaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * EL CRÉDITO QUE LA TIENDA LE DA A SUS CLIENTES, DESDE LA TIENDA.
 *
 * Todo bajo el middleware `negocio`: la tienda sale de la sesión y nunca del
 * cuerpo, así que no se puede fiar a nombre de otra ni ver los cupos de otra.
 */
class CreditosDelNegocioController extends Controller
{
    public function __construct(
        private readonly CreditoDeTienda $creditos,
        private readonly MediaService $medios,
    )
    {
    }

    /**
     * El panorama y los clientes.
     *
     * Entran los afiliados —a quienes se les puede dar cupo— y también quien
     * ya no lo está pero todavía debe: dejar de verlo sería perder de vista
     * una deuda que sigue viva.
     */
    public function index(Request $request)
    {
        $negocioId = (int) $request->attributes->get('busines_id');

        $afiliados = BusinessUserAffiliation::where('busines_id', $negocioId)->pluck('user_id');
        $conCupo   = StoreCredit::where('busines_id', $negocioId)->pluck('user_id');

        $usuarios = User::whereIn('user_id', $afiliados->merge($conCupo)->unique()->values())
            ->orderBy('name')
            ->get(['user_id', 'name', 'email', 'phone']);

        $clientes = $usuarios->map(function (User $u) use ($negocioId, $afiliados) {
            $cupo = $this->creditos->cupo($negocioId, (int) $u->user_id);

            return [
                'user_id'    => (int) $u->user_id,
                'name'       => $u->name,
                'email'      => $u->email,
                'phone'      => $u->phone,
                'afiliado'   => $afiliados->contains($u->user_id),
                'limite'     => $cupo['limite'],
                'usado'      => $cupo['usado'],
                'disponible' => $cupo['disponible'],
            ];
        })->values();

        return response()->json([
            'resumen'  => $this->creditos->resumenDeLaTienda($negocioId),
            'clientes' => $clientes,
        ]);
    }

    /** Fijar (o cambiar) el cupo de un cliente. Cero lo deja sin crédito. */
    public function asignar(Request $request, int $userId)
    {
        $negocioId = (int) $request->attributes->get('busines_id');

        $datos = $request->validate([
            'credit_limit' => 'required|numeric|min:0|max:999999999',
        ]);

        try {
            $this->creditos->asignar($negocioId, $userId, (float) $datos['credit_limit'], $request->user()?->user_id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Cupo guardado.',
            'cupo'    => $this->creditos->cupo($negocioId, $userId),
            'resumen' => $this->creditos->resumenDeLaTienda($negocioId),
        ]);
    }

    /** El cliente le pagó a la tienda: se libera ese valor de su cupo. */
    public function abonar(Request $request, int $userId)
    {
        $negocioId = (int) $request->attributes->get('busines_id');

        $datos = $request->validate([
            'amount'      => 'required|numeric|min:1|max:999999999',
            'notes'       => 'nullable|string|max:255',
            // La foto de la transferencia o del recibo. Opcional.
            'comprobante' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:8192',
        ], [
            'comprobante.mimes' => 'El comprobante tiene que ser una foto (JPG, PNG o WEBP) o un PDF.',
            'comprobante.max'   => 'El comprobante pesa más de 8 MB.',
        ]);

        /*
         * El archivo se sube ANTES de registrar el abono: si falla la subida,
         * no queda un abono que dice tener comprobante y no lo tiene. Si falla
         * el abono después, queda un archivo suelto, que es el mal menor.
         */
        /*
         * Primero, que el cliente tenga crédito EN ESTA TIENDA. Sin esto el
         * archivo se subía a la carpeta de cualquier usuario del sistema y
         * quedaba suelto cuando el abono fallaba después.
         */
        if (!StoreCredit::where('busines_id', $negocioId)->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'Ese cliente no tiene crédito en tu tienda.'], 422);
        }

        $mediaId = null;
        if ($request->hasFile('comprobante')) {
            if (!$this->medios->configurado()) {
                return response()->json([
                    'message' => 'No se pudo guardar el comprobante: el almacenamiento de archivos no está configurado.',
                ], 503);
            }

            try {
                $archivo = $this->medios->subir(
                    'usuarios',
                    $userId,
                    User::where('user_id', $userId)->value('name'),
                    $request->file('comprobante'),
                    'documentos',
                    $request->user()?->user_id,
                );
                $mediaId = (int) $archivo['id'];
            } catch (RuntimeException $e) {
                // MediaService explica sus fallos en español; cualquier otra
                // excepción la atiende el manejador general sin detalles.
                return response()->json(['message' => 'No se pudo guardar el comprobante. ' . $e->getMessage()], 422);
            }
        }

        try {
            $movimiento = $this->creditos->abonar(
                $negocioId,
                $userId,
                (float) $datos['amount'],
                $request->user()?->user_id,
                $datos['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($mediaId) {
            $movimiento->update(['comprobante_media_id' => $mediaId]);
        }

        return response()->json([
            'message' => 'Abono registrado.',
            'cupo'    => $this->creditos->cupo($negocioId, $userId),
            'resumen' => $this->creditos->resumenDeLaTienda($negocioId),
        ], 201);
    }

    /** El historial de un cliente: qué compró a crédito y qué ha abonado. */
    public function movimientos(Request $request, int $userId)
    {
        $negocioId = (int) $request->attributes->get('busines_id');

        $credito = StoreCredit::where('busines_id', $negocioId)->where('user_id', $userId)->first();

        if (!$credito) {
            return response()->json([
                'cupo'        => $this->creditos->cupo($negocioId, $userId),
                'movimientos' => [],
            ]);
        }

        $movimientos = StoreCreditMovement::where('store_credit_id', $credito->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'type', 'amount', 'order_id', 'notes', 'comprobante_media_id', 'created_at']);

        // Los comprobantes de una vez, no uno por movimiento.
        $archivos = DB::table('media_files')
            ->whereIn('id', $movimientos->pluck('comprobante_media_id')->filter()->all())
            ->get(['id', 'object_key', 'original_name', 'mime_type'])
            ->keyBy('id');

        return response()->json([
            'cupo'        => $this->creditos->cupo($negocioId, $userId),
            'movimientos' => $movimientos->map(fn ($m) => [
                'id'         => $m->id,
                'tipo'       => $m->type,
                'valor'      => (float) $m->amount,
                'order_id'   => $m->order_id,
                'nota'       => $m->notes,
                'fecha'      => $m->created_at?->toIso8601String(),
                /* Sin enlace del bucket: el archivo se pide a
                   `movimientos/{id}/comprobante`, con la sesión del tendero. */
                'comprobante' => ($f = $archivos->get($m->comprobante_media_id)) ? [
                    'nombre' => $f->original_name ?: basename($f->object_key),
                    'es_pdf' => $f->mime_type === 'application/pdf',
                ] : null,
            ]),
        ]);
    }

    /** El comprobante de un abono, para verlo en el panel. Solo de esta tienda. */
    public function comprobante(Request $request, int $userId, int $movimientoId)
    {
        $negocioId = (int) $request->attributes->get('busines_id');

        $credito = StoreCredit::where('busines_id', $negocioId)->where('user_id', $userId)->firstOrFail();
        $mediaId = StoreCreditMovement::where('store_credit_id', $credito->id)
            ->where('id', $movimientoId)
            ->value('comprobante_media_id');

        abort_unless($mediaId, 404, 'Este abono no tiene comprobante.');

        return $this->medios->entregar((int) $mediaId);
    }
}
