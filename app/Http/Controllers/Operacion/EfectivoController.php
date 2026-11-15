<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operacion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operacion\ResolverDepositoRequest;
use App\Http\Resources\Operacion\DepositoResource;
use App\Models\Domiciliary;
use App\Models\Operacion\CashDeposit;
use App\Models\Operacion\CashMovement;
use App\Queries\EfectivoDeLaOperacion;
use App\Services\CustodiaDeEfectivo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    public function __construct(private CustodiaDeEfectivo $custodia) {}

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

        if (! $domiciliario) {
            return response()->json(['message' => 'Esta cuenta no es de un domiciliario.'], 403);
        }

        $movimientos = CashMovement::where('domiciliary_id', $domiciliario->domiciliary_id)
            ->latest('created_at')
            ->limit(50)
            ->get(['id', 'type', 'amount', 'order_id', 'deposit_id', 'created_at']);

        /*
         * El tope MÁS BAJO de los negocios a los que reparte.
         *
         * Su saldo es uno solo, pero cada negocio pone el suyo: el que manda en
         * la práctica es el más estricto, porque es el primero que deja de
         * despacharle. Enseñarle el más alto le haría creer que tiene margen
         * cuando ya hay una tienda que no le va a dar más pedidos en efectivo.
         */
        $tope = DB::table('business_domiciliary as bd')
            ->join('business as b', 'b.busines_id', '=', 'bd.busines_id')
            ->where('bd.domiciliary_id', $domiciliario->domiciliary_id)
            ->whereNotNull('b.max_courier_cash')
            ->min('b.max_courier_cash');

        $saldo = $this->custodia->saldo($domiciliario->domiciliary_id);

        return response()->json([
            'balance' => $saldo,
            'cash_limit' => $tope !== null ? (float) $tope : null,
            'cash_room' => $tope !== null ? max(0, round((float) $tope - $saldo, 2)) : null,
            'movements' => $movimientos,
            'deposits' => CashDeposit::where('domiciliary_id', $domiciliario->domiciliary_id)
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

        if (! $domiciliario) {
            return response()->json(['message' => 'Esta cuenta no es de un domiciliario.'], 403);
        }

        $datos = $request->validate([
            'amount' => 'required|numeric|min:1',
            'reference' => 'nullable|string|max:100',
            'deposited_at' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ]);

        $deposito = $this->custodia->declararDeposito($domiciliario->domiciliary_id, $datos);

        return response()->json([
            'message' => 'Consignación registrada. Queda pendiente de confirmación.',
            'deposit' => (new DepositoResource($deposito))->resolve(),
            'balance' => $this->custodia->saldo($domiciliario->domiciliary_id),
        ], 201);
    }

    /* ----------------------------- LADO PANEL ---------------------------- */

    /** Los depósitos declarados, para revisarlos contra el extracto. */
    /** Los depositos declarados. La consulta vive en `Queries/EfectivoDeLaOperacion`. */
    public function index(Request $request)
    {
        return response()->json([
            'data' => (new EfectivoDeLaOperacion)->depositos(
                estado: $request->query('state'),
                domiciliario: ((int) $request->query('domiciliary_id')) ?: null,
            ),
        ]);
    }

    /** Quien debe cuanto, ahora mismo. Es la pregunta que antes no se podia hacer. */
    public function saldos()
    {
        $saldos = (new EfectivoDeLaOperacion)->saldos();

        return response()->json([
            'data' => $saldos,
            'total' => round((float) $saldos->sum('balance'), 2),
        ]);
    }

    /** Confirma o rechaza un depósito. Confirmar es lo que baja el saldo. */
    public function resolver(ResolverDepositoRequest $request, int $id)
    {
        $datos = $request->validated();

        $deposito = CashDeposit::find($id);

        if (! $deposito) {
            return response()->json(['message' => 'Depósito no encontrado.'], 404);
        }

        $usuarioId = (int) $request->user()->user_id;

        try {
            $deposito = $datos['state'] === CashDeposit::CONFIRMADA
                ? $this->custodia->confirmarDeposito($deposito, $usuarioId, $datos['notes'] ?? null)
                : $this->custodia->rechazarDeposito($deposito, $usuarioId, $datos['notes'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $datos['state'] === CashDeposit::CONFIRMADA
                ? 'Depósito confirmado. El saldo bajó.'
                : 'Depósito rechazado. El saldo no cambió.',
            'deposit' => (new DepositoResource($deposito))->resolve(),
            'balance' => $this->custodia->saldo($deposito->domiciliary_id),
        ]);
    }

    /* ------------------------------------------------------------------ */

    private function domiciliarioDe(Request $request): ?Domiciliary
    {
        return Domiciliary::where('user_id', $request->user()->user_id)->first();
    }
}
