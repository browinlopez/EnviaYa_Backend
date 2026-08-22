<?php

namespace App\Http\Controllers\Operacion;

use App\Http\Controllers\Controller;
use App\Models\Domiciliary;
use App\Models\Operacion\CashDeposit;
use App\Models\Operacion\CashMovement;
use App\Services\CustodiaDeEfectivo;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * El efectivo, por los dos lados: el domiciliario que lo tiene y el equipo que
 * lo confirma.
 *
 * Van juntos en un controlador a propósito, porque son dos mitades de la misma
 * conversación y separarlas invita a que una evolucione sin la otra. Lo que sí
 * está separado es la autorización: las rutas del domiciliario resuelven su
 * identidad desde la sesión y NUNCA desde un parámetro.
 */
class EfectivoController extends Controller
{
    public function __construct(private CustodiaDeEfectivo $custodia)
    {
    }

    /* ------------------------- LADO DOMICILIARIO ------------------------- */

    /**
     * Cuánto debe y de dónde sale.
     *
     * El identificador del domiciliario sale de la sesión. Aceptarlo por
     * parámetro dejaría a cualquiera consultar —o peor, saldar— el saldo de
     * otro.
     */
    public function miSaldo(Request $request)
    {
        $domiciliario = $this->domiciliarioDe($request);

        if (!$domiciliario) {
            return response()->json(['message' => 'Esta cuenta no es de un domiciliario.'], 403);
        }

        $movimientos = CashMovement::where('domiciliary_id', $domiciliario->domiciliary_id)
            ->latest('created_at')
            ->limit(50)
            ->get(['id', 'type', 'amount', 'order_id', 'deposit_id', 'created_at']);

        return response()->json([
            'balance'     => $this->custodia->saldo($domiciliario->domiciliary_id),
            'movements'   => $movimientos,
            'deposits'    => CashDeposit::where('domiciliary_id', $domiciliario->domiciliary_id)
                ->latest('created_at')->limit(20)->get(),
        ]);
    }

    /**
     * Declara una consignación. NO baja el saldo.
     *
     * Queda pendiente hasta que alguien la confirme contra el extracto. Si
     * bajara al declararla, saldar la deuda sería cuestión de escribir una
     * referencia inventada.
     */
    public function declararDeposito(Request $request)
    {
        $domiciliario = $this->domiciliarioDe($request);

        if (!$domiciliario) {
            return response()->json(['message' => 'Esta cuenta no es de un domiciliario.'], 403);
        }

        $datos = $request->validate([
            'amount'       => 'required|numeric|min:1',
            'reference'    => 'nullable|string|max:100',
            'deposited_at' => 'nullable|date',
            'notes'        => 'nullable|string|max:500',
        ]);

        $deposito = $this->custodia->declararDeposito($domiciliario->domiciliary_id, $datos);

        return response()->json([
            'message' => 'Consignación registrada. Queda pendiente de confirmación.',
            'deposit' => $deposito,
            'balance' => $this->custodia->saldo($domiciliario->domiciliary_id),
        ], 201);
    }

    /* ----------------------------- LADO PANEL ---------------------------- */

    /** Los depósitos declarados, para revisarlos contra el extracto. */
    public function index(Request $request)
    {
        $q = CashDeposit::query()
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'cash_deposits.domiciliary_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->select([
                'cash_deposits.*',
                'u.name as domiciliary_name',
            ]);

        if ($estado = $request->query('state')) {
            $q->where('cash_deposits.state', $estado);
        }

        if ($domi = $request->query('domiciliary_id')) {
            $q->where('cash_deposits.domiciliary_id', $domi);
        }

        return response()->json([
            'data' => $q->latest('cash_deposits.created_at')->limit(200)->get(),
        ]);
    }

    /** Quién debe cuánto, ahora mismo. Es la pregunta que antes no se podía hacer. */
    public function saldos()
    {
        $saldos = CashMovement::query()
            ->join('domiciliary as d', 'd.domiciliary_id', '=', 'cash_movements.domiciliary_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->groupBy('cash_movements.domiciliary_id', 'u.name')
            ->havingRaw('SUM(cash_movements.amount) <> 0')
            ->orderByRaw('SUM(cash_movements.amount) DESC')
            ->get([
                'cash_movements.domiciliary_id',
                'u.name',
                \Illuminate\Support\Facades\DB::raw('SUM(cash_movements.amount) as balance'),
            ]);

        return response()->json([
            'data'  => $saldos,
            'total' => round((float) $saldos->sum('balance'), 2),
        ]);
    }

    /** Confirma o rechaza un depósito. Confirmar es lo que baja el saldo. */
    public function resolver(Request $request, int $id)
    {
        $datos = $request->validate([
            'state' => 'required|in:confirmada,rechazada',
            'notes' => 'nullable|string|max:500',
        ]);

        $deposito = CashDeposit::find($id);

        if (!$deposito) {
            return response()->json(['message' => 'Depósito no encontrado.'], 404);
        }

        $usuarioId = (int) $request->user()->user_id;

        try {
            $deposito = $datos['state'] === CashDeposit::CONFIRMADA
                ? $this->custodia->confirmarDeposito($deposito, $usuarioId)
                : $this->custodia->rechazarDeposito($deposito, $usuarioId, $datos['notes'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $datos['state'] === CashDeposit::CONFIRMADA
                ? 'Depósito confirmado. El saldo bajó.'
                : 'Depósito rechazado. El saldo no cambió.',
            'deposit' => $deposito,
            'balance' => $this->custodia->saldo($deposito->domiciliary_id),
        ]);
    }

    /* ------------------------------------------------------------------ */

    private function domiciliarioDe(Request $request): ?Domiciliary
    {
        return Domiciliary::where('user_id', $request->user()->user_id)->first();
    }
}
