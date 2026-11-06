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

    /**
     * Lo que un domiciliario cobró en pedidos de ESTA tienda y todavía no ha
     * salido de su bolsa.
     *
     * Es el tope de lo que la tienda puede recibir. La bolsa es una sola para
     * todas las tiendas en que reparte: sin este tope, una tienda podría
     * «recibir» la plata que él cobró para la de al lado.
     *
     * SE RECORRE EN ORDEN. Cada cobro suma a la tienda del pedido; cada
     * entrega en un mostrador resta de esa tienda; y cada consignación a la
     * PLATAFORMA se reparte entre lo que en ESE MOMENTO estaba pendiente con
     * cada tienda —la consignación no dice de quién era cada peso—. Sumar y
     * restar totales no sirve: si consignó todo y después cobró para la
     * vecina, el reparto de totales le asignaba a esta tienda parte del cobro
     * nuevo. Y nunca más de lo que lleva encima.
     */
    public function pendienteConLaTienda(int $negocioId, int $domiciliaryId): float
    {
        $eventos = collect();

        CashMovement::where('cash_movements.domiciliary_id', $domiciliaryId)
            ->where('cash_movements.type', CashMovement::RECAUDO)
            ->leftJoin('orderssales as o', 'o.orderSales_id', '=', 'cash_movements.order_id')
            ->get(['cash_movements.id', 'cash_movements.amount', 'cash_movements.created_at', 'o.busines_id'])
            ->each(fn ($m) => $eventos->push([
                'cuando' => (string) $m->created_at, 'orden' => 0, 'id' => $m->id,
                'tipo' => 'cobro', 'tienda' => (int) $m->busines_id, 'monto' => (float) $m->amount,
            ]));

        CashDeposit::where('domiciliary_id', $domiciliaryId)
            ->where('state', CashDeposit::CONFIRMADA)
            ->get(['id', 'amount', 'canal', 'busines_id', 'confirmed_at', 'created_at'])
            ->each(fn ($d) => $eventos->push([
                'cuando' => (string) ($d->confirmed_at ?? $d->created_at), 'orden' => 1, 'id' => $d->id,
                'tipo'   => $d->canal === CashDeposit::MOSTRADOR ? 'mostrador' : 'plataforma',
                'tienda' => (int) $d->busines_id, 'monto' => (float) $d->amount,
            ]));

        $porTienda = [];

        foreach ($eventos->sortBy(fn ($e) => [$e['cuando'], $e['orden'], $e['id']]) as $e) {
            if ($e['tipo'] === 'cobro') {
                $porTienda[$e['tienda']] = ($porTienda[$e['tienda']] ?? 0) + $e['monto'];
            } elseif ($e['tipo'] === 'mostrador') {
                $porTienda[$e['tienda']] = max(0, ($porTienda[$e['tienda']] ?? 0) - $e['monto']);
            } else {
                $total = array_sum($porTienda);
                if ($total <= 0) {
                    continue;
                }
                $parte = min(1, $e['monto'] / $total);
                foreach ($porTienda as $t => $v) {
                    $porTienda[$t] = $v * (1 - $parte);
                }
            }
        }

        $pendiente = $porTienda[$negocioId] ?? 0.0;

        return max(0.0, round(min($pendiente, $this->saldo($domiciliaryId)), 2));
    }

    /**
     * EL DOMICILIARIO LE ENTREGA EL EFECTIVO A LA TIENDA, en el mostrador.
     *
     * Es una consignación más, pero hecha a la tienda: se declara y se
     * confirma en el mismo acto, porque quien la registra es quien recibió la
     * plata y la tiene en la mano. Va por `declararDeposito` y
     * `confirmarDeposito` para que el apunte que baja el saldo sea el mismo
     * de siempre.
     *
     * No puede pasar de lo que cobró en pedidos de esta tienda ni de lo que
     * tiene encima.
     */
    public function recibirEnMostrador(
        int $negocioId,
        int $domiciliaryId,
        float $monto,
        int $usuarioId,
        ?string $nota = null,
    ): CashDeposit {
        if ($monto <= 0) {
            throw new RuntimeException('El monto tiene que ser mayor que cero.');
        }

        return DB::transaction(function () use ($negocioId, $domiciliaryId, $monto, $usuarioId, $nota) {
            // Bloqueo por domiciliario: dos entregas a la vez no pueden pasar
            // juntas el tope, cada una viendo el saldo sin la otra.
            DB::table('domiciliary')->where('domiciliary_id', $domiciliaryId)->lockForUpdate()->first();

            $tope = min(
                $this->pendienteConLaTienda($negocioId, $domiciliaryId),
                max(0.0, $this->saldo($domiciliaryId)),
            );

            if ($tope <= 0) {
                throw new RuntimeException('Este domiciliario no tiene efectivo pendiente de tus pedidos.');
            }

            if ($monto > $tope + 0.004) {
                throw new RuntimeException(
                    'De tus pedidos solo tiene pendientes $' . number_format($tope, 0, ',', '.') . '.'
                );
            }

            $deposito = $this->declararDeposito($domiciliaryId, [
                'amount'    => round($monto, 2),
                'reference' => 'Entregado en el mostrador',
                'notes'     => $nota,
            ]);
            $deposito->update(['canal' => CashDeposit::MOSTRADOR, 'busines_id' => $negocioId]);

            return $this->confirmarDeposito($deposito, $usuarioId, $nota);
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
