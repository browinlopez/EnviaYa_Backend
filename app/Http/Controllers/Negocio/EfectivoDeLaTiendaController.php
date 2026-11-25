<?php

declare(strict_types=1);

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Models\Domiciliary;
use App\Queries\EfectivoDeLaTienda;
use App\Services\CustodiaDeEfectivo;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * EL EFECTIVO DE LOS DOMICILIARIOS, VISTO DESDE LA TIENDA.
 *
 * Dos cosas, y la diferencia importa:
 *
 *  · LO QUE LE ENTREGAN EN EL MOSTRADOR. El domiciliario vuelve y le da al
 *    tendero lo que cobró en sus pedidos. La tienda lo registra y queda
 *    confirmado al instante —quien lo apunta es quien tiene la plata en la
 *    mano—. Solo hasta lo que cobró en pedidos de ESTA tienda; ver
 *    `CustodiaDeEfectivo::recibirEnMostrador`.
 *
 *  · LAS CONSIGNACIONES A VECIPA'YA. Las ve, pero no las confirma: se
 *    confirman contra el extracto de la plataforma, que la tienda no tiene.
 *
 * Solo los domiciliarios vinculados a la tienda.
 */
class EfectivoDeLaTiendaController extends Controller
{
    public function __construct(private CustodiaDeEfectivo $custodia) {}

    public function index(Request $request)
    {
        $negocioId = (int) $request->attributes->get('busines_id');

        /* LAS CUATRO CONSULTAS VIVEN EN `Queries/EfectivoDeLaTienda`, que es
           lo que §12 pide. Aqui queda el armado de la respuesta, que es
           presentacion y no lectura. */
        $lee = new EfectivoDeLaTienda;

        $flota = $lee->flota($negocioId);
        $ids = $flota->pluck('domiciliary_id')->map(fn ($id) => (int) $id)->all();

        $ultimas = $lee->ultimaEntregaPorDomiciliario($negocioId);

        /*
         * LOS DOS NÚMEROS DE TODA LA FLOTA, ANTES DEL RECORRIDO.
         *
         * Aquí se llamaba a `saldo()` y a `pendienteConLaTienda()` DENTRO del
         * `map`, y el segundo hace dos consultas más y vuelve a llamar al
         * primero: cuatro por domiciliario. Medido contra la base de
         * desarrollo, esta ruta hacía **50 consultas** para una tienda con
         * diez, y 42 eran tres líneas repitiéndose. Una tienda con cuarenta
         * domiciliarios haría ciento ochenta.
         *
         * Las versiones en plural traen lo mismo en tres consultas para todos,
         * y el reparto cronológico es el mismo código: no hay dos cuentas que
         * puedan dar números distintos.
         */
        $saldos = $this->custodia->saldosDe($ids);
        $pendientes = $this->custodia->pendientesConLaTienda($negocioId, $ids);

        $domiciliarios = $flota->map(fn ($d) => [
            'domiciliary_id' => (int) $d->domiciliary_id,
            'name' => $d->user?->name,
            'phone' => $d->user?->phone,
            // Lo que lleva encima de todas las tiendas, y lo que es de esta.
            'saldo' => round($saldos[(int) $d->domiciliary_id] ?? 0.0, 2),
            'pendiente' => $pendientes[(int) $d->domiciliary_id] ?? 0.0,
            'ultima_entrega' => $ultimas[$d->domiciliary_id] ?? null,
        ])->sortByDesc('pendiente')->values();

        $entregas = $lee->entregasEnMostrador($negocioId)
            ->map(fn ($e) => [
                'id' => (int) $e->id,
                'domiciliary_id' => (int) $e->domiciliary_id,
                'domiciliario' => $e->domiciliario,
                'monto' => (float) $e->amount,
                'nota' => $e->notes,
                'fecha' => optional($e->confirmed_at)->toIso8601String(),
                'recibio' => $e->recibio,
                // Si ya se descontó de un corte de la plataforma.
                'en_corte' => $e->settlement_id !== null,
            ]);

        $consignaciones = $lee->consignaciones($ids)
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'domiciliario' => $c->domiciliario,
                'monto' => (float) $c->amount,
                'referencia' => $c->reference,
                'estado' => $c->state,
                'fecha' => optional($c->deposited_at ?? $c->created_at)->toIso8601String(),
            ]);

        $hoy = $entregas->filter(fn ($e) => $e['fecha'] && now()->isSameDay($e['fecha']));

        return response()->json([
            'domiciliarios' => $domiciliarios,
            'entregas' => $entregas->values(),
            'consignaciones' => $consignaciones->values(),
            'resumen' => [
                'pendiente' => round((float) $domiciliarios->sum('pendiente'), 2),
                'recibido_hoy' => round((float) $hoy->sum('monto'), 2),
                'entregas_hoy' => $hoy->count(),
            ],
        ]);
    }

    /** Registrar que un domiciliario entregó efectivo en el mostrador. */
    public function recibir(Request $request, int $id)
    {
        $negocioId = (int) $request->attributes->get('busines_id');

        $d = Domiciliary::whereHas('businesses', fn ($q) => $q->where('business.busines_id', $negocioId)->where('business_domiciliary.state', 1))
            ->findOrFail($id);

        $datos = $request->validate([
            'amount' => 'required|numeric|min:1|max:999999999',
            'notes' => 'nullable|string|max:255',
            /*
             * LA CLAVE DEL INTENTO, para que el mismo billete no se cuente
             * dos veces. La pone la app al abrir el formulario y la repite
             * en cada reintento de ESE intento; ver `recibirEnMostrador`.
             *
             * `nullable` a propósito: una app vieja sigue funcionando, con
             * el riesgo que ya tenía. No se le cierra la puerta a nadie por
             * no haberse actualizado.
             */
            'idempotency_key' => 'nullable|uuid',
        ], [
            'amount.required' => 'Escribe cuánto te entregó.',
            'amount.min' => 'El monto tiene que ser mayor que cero.',
        ]);

        try {
            $entrega = $this->custodia->recibirEnMostrador(
                $negocioId,
                (int) $d->domiciliary_id,
                (float) $datos['amount'],
                (int) $request->user()->user_id,
                $datos['notes'] ?? null,
                $datos['idempotency_key'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['amount' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'message' => 'Listo: quedó registrado lo que te entregó.',
            'entrega' => ['id' => $entrega->id, 'monto' => (float) $entrega->amount],
            'pendiente' => $this->custodia->pendienteConLaTienda($negocioId, (int) $d->domiciliary_id),
            'saldo' => $this->custodia->saldo((int) $d->domiciliary_id),
        ], 201);
    }
}
