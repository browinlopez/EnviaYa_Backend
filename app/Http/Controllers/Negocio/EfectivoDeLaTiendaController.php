<?php

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Models\Domiciliary;
use App\Models\Operacion\CashDeposit;
use App\Services\CustodiaDeEfectivo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    public function __construct(private CustodiaDeEfectivo $custodia)
    {
    }

    public function index(Request $request)
    {
        $negocioId = (int) $request->attributes->get('busines_id');

        $flota = Domiciliary::with('user')
            ->whereHas('businesses', fn ($q) => $q->where('business.busines_id', $negocioId)->where('business_domiciliary.state', 1))
            ->get();
        $ids = $flota->pluck('domiciliary_id')->map(fn ($id) => (int) $id)->all();

        $ultimas = CashDeposit::where('busines_id', $negocioId)
            ->where('canal', CashDeposit::MOSTRADOR)
            ->where('state', CashDeposit::CONFIRMADA)
            ->selectRaw('domiciliary_id, MAX(confirmed_at) as ultima')
            ->groupBy('domiciliary_id')
            ->pluck('ultima', 'domiciliary_id');

        $domiciliarios = $flota->map(fn ($d) => [
            'domiciliary_id' => (int) $d->domiciliary_id,
            'name'           => $d->user?->name,
            'phone'          => $d->user?->phone,
            // Lo que lleva encima de todas las tiendas, y lo que es de esta.
            'saldo'          => round($this->custodia->saldo((int) $d->domiciliary_id), 2),
            'pendiente'      => $this->custodia->pendienteConLaTienda($negocioId, (int) $d->domiciliary_id),
            'ultima_entrega' => $ultimas[$d->domiciliary_id] ?? null,
        ])->sortByDesc('pendiente')->values();

        $entregas = CashDeposit::where('cash_deposits.busines_id', $negocioId)
            ->where('cash_deposits.canal', CashDeposit::MOSTRADOR)
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'cash_deposits.domiciliary_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->leftJoin('user as r', 'r.user_id', '=', 'cash_deposits.confirmed_by')
            ->latest('cash_deposits.confirmed_at')
            ->limit(100)
            ->get([
                'cash_deposits.id', 'cash_deposits.domiciliary_id', 'cash_deposits.amount',
                'cash_deposits.notes', 'cash_deposits.confirmed_at', 'cash_deposits.settlement_id',
                'u.name as domiciliario', 'r.name as recibio',
            ])
            ->map(fn ($e) => [
                'id'             => (int) $e->id,
                'domiciliary_id' => (int) $e->domiciliary_id,
                'domiciliario'   => $e->domiciliario,
                'monto'          => (float) $e->amount,
                'nota'           => $e->notes,
                'fecha'          => optional($e->confirmed_at)->toIso8601String(),
                'recibio'        => $e->recibio,
                // Si ya se descontó de un corte de la plataforma.
                'en_corte'       => $e->settlement_id !== null,
            ]);

        $consignaciones = $ids === [] ? collect() : CashDeposit::whereIn('cash_deposits.domiciliary_id', $ids)
            ->where('cash_deposits.canal', CashDeposit::CONSIGNACION)
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'cash_deposits.domiciliary_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->latest('cash_deposits.created_at')
            ->limit(50)
            ->get(['cash_deposits.*', 'u.name as domiciliario'])
            ->map(fn ($c) => [
                'id'           => (int) $c->id,
                'domiciliario' => $c->domiciliario,
                'monto'        => (float) $c->amount,
                'referencia'   => $c->reference,
                'estado'       => $c->state,
                'fecha'        => optional($c->deposited_at ?? $c->created_at)->toIso8601String(),
            ]);

        $hoy = $entregas->filter(fn ($e) => $e['fecha'] && now()->isSameDay($e['fecha']));

        return response()->json([
            'domiciliarios'  => $domiciliarios,
            'entregas'       => $entregas->values(),
            'consignaciones' => $consignaciones->values(),
            'resumen'        => [
                'pendiente'    => round((float) $domiciliarios->sum('pendiente'), 2),
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
            'notes'  => 'nullable|string|max:255',
        ], [
            'amount.required' => 'Escribe cuánto te entregó.',
            'amount.min'      => 'El monto tiene que ser mayor que cero.',
        ]);

        try {
            $entrega = $this->custodia->recibirEnMostrador(
                $negocioId,
                (int) $d->domiciliary_id,
                (float) $datos['amount'],
                (int) $request->user()->user_id,
                $datos['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => ['amount' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'message'   => 'Listo: quedó registrado lo que te entregó.',
            'entrega'   => ['id' => $entrega->id, 'monto' => (float) $entrega->amount],
            'pendiente' => $this->custodia->pendienteConLaTienda($negocioId, (int) $d->domiciliary_id),
            'saldo'     => $this->custodia->saldo((int) $d->domiciliary_id),
        ], 201);
    }
}
