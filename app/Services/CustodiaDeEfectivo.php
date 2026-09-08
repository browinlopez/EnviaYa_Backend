<?php

namespace App\Services;

use App\Models\Operacion\CashDeposit;
use App\Models\Operacion\CashMovement;
use App\Models\Order\OrdersSales;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El libro de efectivo de los domiciliarios.
 *
 * Un solo sitio donde se apunta y se consulta, para que nadie sume el saldo
 * por su cuenta con un `where` distinto y le dé otro número.
 */
class CustodiaDeEfectivo
{
    /**
     * Apunta lo que un domiciliario recaudó al entregar un pedido en efectivo.
     *
     * Recauda el TOTAL del pedido —producto incluido, que es del negocio—, no
     * su comisión. Por eso casi siempre va a quedar debiendo: cobra 32.000 y
     * gana 500.
     *
     * Devuelve null si el pedido no aplica (no fue en efectivo, o no tiene
     * domiciliario) o si ya estaba apuntado: `updateStatus` se reintenta ante
     * un fallo de red, y sin esa guarda el segundo intento duplicaría la deuda.
     */
    public function registrarRecaudo(OrdersSales $order, ?int $usuarioId = null): ?CashMovement
    {
        if ((int) $order->methods_id !== 1 || !$order->domiciliary_id) {
            return null;
        }

        $yaEsta = CashMovement::where('order_id', $order->orderSales_id)
            ->where('type', CashMovement::RECAUDO)
            ->exists();

        if ($yaEsta) {
            return null;
        }

        $movimiento = CashMovement::create([
            'domiciliary_id' => $order->domiciliary_id,
            'type'           => CashMovement::RECAUDO,
            'amount'         => (float) $order->total,
            'order_id'       => $order->orderSales_id,
            'created_by'     => $usuarioId,
        ]);

        // También en el pedido, para que la liquidación no tenga que cruzar
        // tablas y para que el desglose de un pedido se explique solo.
        $order->cash_due = (float) $order->total;

        return $movimiento;
    }

    /** Lo que un domiciliario tiene encima ahora mismo. */
    public function saldo(int $domiciliaryId): float
    {
        return round((float) CashMovement::where('domiciliary_id', $domiciliaryId)->sum('amount'), 2);
    }

    /**
     * El domiciliario declara que consignó. NO toca el saldo todavía.
     *
     * Queda pendiente hasta que alguien lo confirme contra el extracto. Si
     * bajara el saldo al declararlo, saldar la deuda sería cuestión de
     * escribir un número de referencia cualquiera.
     */
    public function declararDeposito(int $domiciliaryId, array $datos): CashDeposit
    {
        return CashDeposit::create([
            'domiciliary_id' => $domiciliaryId,
            'amount'         => $datos['amount'],
            'reference'      => $datos['reference'] ?? null,
            'deposited_at'   => $datos['deposited_at'] ?? now(),
            'receipt_path'   => $datos['receipt_path'] ?? null,
            'state'          => CashDeposit::PENDIENTE,
            'notes'          => $datos['notes'] ?? null,
        ]);
    }

    /**
     * Alguien del equipo confirma el depósito: ahí sí baja el saldo.
     *
     * En transacción y con el movimiento atado al depósito, para que no pueda
     * quedar un depósito confirmado sin su apunte —o al revés, un apunte sin
     * respaldo—.
     */
    /**
     * @param string|null $nota Lo que anotó quien confirmó, tal cual.
     *                          Se guardaba solo al rechazar: al confirmar el
     *                          controlador la validaba y la tiraba, así que
     *                          «verificado contra el extracto del 5» se perdía
     *                          y quedaba una confirmación sin sustento.
     */
    public function confirmarDeposito(CashDeposit $deposito, int $usuarioId, ?string $nota = null): CashDeposit
    {
        if ($deposito->state === CashDeposit::CONFIRMADA) {
            throw new RuntimeException('Este depósito ya estaba confirmado.');
        }

        if ($deposito->state === CashDeposit::RECHAZADA) {
            throw new RuntimeException('Este depósito fue rechazado; no se puede confirmar.');
        }

        return DB::transaction(function () use ($deposito, $usuarioId, $nota) {
            CashMovement::create([
                'domiciliary_id' => $deposito->domiciliary_id,
                'type'           => CashMovement::CONSIGNACION,
                // Negativo: baja lo que debe.
                'amount'         => -1 * (float) $deposito->amount,
                'deposit_id'     => $deposito->id,
                'created_by'     => $usuarioId,
            ]);

            $deposito->state        = CashDeposit::CONFIRMADA;
            $deposito->confirmed_by = $usuarioId;
            $deposito->confirmed_at = now();
            $deposito->notes        = $nota ?: $deposito->notes;
            $deposito->save();

            return $deposito;
        });
    }

    public function rechazarDeposito(CashDeposit $deposito, int $usuarioId, ?string $motivo = null): CashDeposit
    {
        if ($deposito->state === CashDeposit::CONFIRMADA) {
            throw new RuntimeException(
                'Este depósito ya está confirmado. Para revertirlo, registra un ajuste.'
            );
        }

        $deposito->state        = CashDeposit::RECHAZADA;
        $deposito->confirmed_by = $usuarioId;
        $deposito->confirmed_at = now();
        $deposito->notes        = $motivo ?: $deposito->notes;
        $deposito->save();

        return $deposito;
    }
}
