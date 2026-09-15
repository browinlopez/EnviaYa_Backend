<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Business\BusinessUserAffiliation;
use App\Models\Operacion\Settlement;
use App\Models\Operacion\StoreCredit;
use App\Models\Operacion\StoreCreditMovement;
use App\Models\Order\OrdersSales;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * EL CRÉDITO QUE UNA TIENDA LE DA A SUS CLIENTES.
 *
 * Es un CUPO ROTATIVO: el tendero le asigna a un comprador afiliado hasta
 * cuánto le fía; cada pedido pagado con crédito lo va gastando, y cuando el
 * comprador le paga a la tienda —por fuera de la app— el tendero registra el
 * abono y ese valor vuelve a quedar disponible.
 *
 * Todo lo que decide si se puede fiar vive acá, en un solo sitio, por la misma
 * razón que el libro de efectivo: dos consultas con un `where` distinto darían
 * dos saldos distintos para la misma persona.
 *
 * LAS TRES REGLAS
 *  1. Solo a compradores AFILIADOS a la tienda.
 *  2. La suma de todos los cupos de la tienda no pasa su tope
 *     (`business.credit_debt_cap`, lo pone el equipo). Sin tope, no hay crédito.
 *  3. Si la tienda arrastra con la plataforma una deuda igual o mayor a su tope
 *     —días en que lo vendido a crédito superó lo que se le debía pagar—, no
 *     vende más a crédito hasta ponerse al día. El riesgo de que el comprador
 *     no le pague es de la tienda; el de que la tienda no le pague a la
 *     plataforma lo acota este tope.
 */
class CreditoDeTienda
{
    /** `payment_methods.methods_id` del crédito de la tienda. */
    public const METODO = 7;

    public function estaAfiliado(int $negocioId, int $userId): bool
    {
        return BusinessUserAffiliation::where('busines_id', $negocioId)
            ->where('user_id', $userId)
            ->exists();
    }

    /** Lo usado de un cupo: la suma de su libro, nunca una columna aparte. */
    public function usado(int $creditoId): float
    {
        return round((float) StoreCreditMovement::where('store_credit_id', $creditoId)->sum('amount'), 2);
    }

    /** @return array{limite: float, usado: float, disponible: float} */
    public function cupo(int $negocioId, int $userId): array
    {
        $credito = StoreCredit::where('busines_id', $negocioId)->where('user_id', $userId)->first();

        if (!$credito) {
            return ['limite' => 0.0, 'usado' => 0.0, 'disponible' => 0.0];
        }

        $limite = (float) $credito->credit_limit;
        $usado  = $this->usado($credito->id);

        return [
            'limite'     => $limite,
            'usado'      => $usado,
            // Bajar el cupo por debajo de lo ya usado es válido: queda en cero
            // disponible hasta que abone, no en negativo.
            'disponible' => max(0.0, round($limite - $usado, 2)),
        ];
    }

    /**
     * La liquidación negativa que la tienda aún no ha compensado.
     *
     * Cada corte arrastra el saldo negativo del anterior, así que lo pendiente
     * es, a lo sumo, el último corte vivo en rojo que ningún otro ha absorbido.
     */
    public function corteEnRojoPendiente(int $negocioId): ?Settlement
    {
        return Settlement::where('type', 'business')
            ->where('business_id', $negocioId)
            ->where('state', '!=', Settlement::ANULADA)
            ->where('net_payable', '<', 0)
            ->whereNotExists(function ($q) {
                $q->from('settlements as s2')
                    ->whereColumn('s2.carried_from_id', 'settlements.id')
                    ->where('s2.state', '!=', Settlement::ANULADA)
                    ->selectRaw('1');
            })
            ->orderByDesc('id')
            ->first();
    }

    /** Lo que la tienda le debe a la plataforma ahora, en positivo. */
    public function deudaDeLaTienda(int $negocioId): float
    {
        $corte = $this->corteEnRojoPendiente($negocioId);

        return $corte ? round(-1 * (float) $corte->net_payable, 2) : 0.0;
    }

    /** Lo asignado entre todos los cupos de la tienda. */
    public function asignadoPorLaTienda(int $negocioId, ?int $exceptoUserId = null): float
    {
        return round((float) StoreCredit::where('busines_id', $negocioId)
            ->when($exceptoUserId, fn ($q) => $q->where('user_id', '!=', $exceptoUserId))
            ->sum('credit_limit'), 2);
    }

    /** El panorama de la tienda, para su pantalla y para el panel. */
    public function resumenDeLaTienda(int $negocioId): array
    {
        $tope     = Business::where('busines_id', $negocioId)->value('credit_debt_cap');
        $asignado = $this->asignadoPorLaTienda($negocioId);
        $deuda    = $this->deudaDeLaTienda($negocioId);
        $usado    = round((float) StoreCreditMovement::whereIn(
            'store_credit_id',
            StoreCredit::where('busines_id', $negocioId)->select('id'),
        )->sum('amount'), 2);

        return [
            'habilitado'        => $tope !== null && (float) $tope > 0,
            'tope'              => $tope === null ? null : (float) $tope,
            'asignado'          => $asignado,
            'por_asignar'       => $tope === null ? 0.0 : max(0.0, round((float) $tope - $asignado, 2)),
            'usado'             => $usado,
            'deuda_arrastrada'  => $deuda,
            'puede_vender'      => $tope !== null && (float) $tope > 0 && $deuda < (float) $tope,
        ];
    }

    /**
     * El tendero fija el cupo de un comprador.
     *
     * Se bloquea la fila del negocio para que dos asignaciones a la vez no
     * pasen juntas el tope: cada una vería el total sin la otra.
     */
    public function asignar(int $negocioId, int $userId, float $limite, ?int $porQuien = null): StoreCredit
    {
        if ($limite < 0) {
            throw new RuntimeException('El cupo no puede ser negativo.');
        }

        return DB::transaction(function () use ($negocioId, $userId, $limite, $porQuien) {
            $negocio = Business::where('busines_id', $negocioId)->lockForUpdate()->first();

            if (!$negocio) {
                throw new RuntimeException('Negocio no encontrado.');
            }

            if (!$this->estaAfiliado($negocioId, $userId)) {
                throw new RuntimeException('Solo puedes dar crédito a clientes afiliados a tu tienda.');
            }

            $tope = $negocio->credit_debt_cap;

            if ($tope === null || (float) $tope <= 0) {
                throw new RuntimeException(
                    'Tu tienda todavía no tiene crédito habilitado. Pídeselo al equipo de VeciPa’Ya.'
                );
            }

            $otros = $this->asignadoPorLaTienda($negocioId, $userId);

            if ($otros + $limite > (float) $tope + 0.005) {
                $quedan = max(0.0, (float) $tope - $otros);
                throw new RuntimeException(sprintf(
                    'Tu tope para dar crédito es %s y ya asignaste %s a otros clientes: a este le puedes dar hasta %s.',
                    $this->pesos((float) $tope),
                    $this->pesos($otros),
                    $this->pesos($quedan),
                ));
            }

            $credito = StoreCredit::firstOrNew(['busines_id' => $negocioId, 'user_id' => $userId]);
            $credito->credit_limit = $limite;
            $credito->created_by ??= $porQuien;
            $credito->save();

            return $credito;
        });
    }

    /**
     * Gasta crédito en un pedido. Va DENTRO de la transacción que crea el
     * pedido: si el crédito no alcanza, el pedido no existe.
     *
     * El cupo se bloquea mientras se decide: dos pedidos del mismo comprador a
     * la vez verían el mismo disponible y lo gastarían dos veces.
     */
    public function consumir(OrdersSales $order, int $userId, ?int $porQuien = null): StoreCreditMovement
    {
        $negocioId = (int) $order->busines_id;
        $total     = round((float) $order->total, 2);

        if (!$this->estaAfiliado($negocioId, $userId)) {
            throw new RuntimeException('Para pagar con crédito tienes que estar afiliado a esta tienda.');
        }

        $credito = StoreCredit::where('busines_id', $negocioId)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if (!$credito || (float) $credito->credit_limit <= 0) {
            throw new RuntimeException('Esta tienda no te ha dado crédito.');
        }

        $tope = Business::where('busines_id', $negocioId)->value('credit_debt_cap');

        if ($tope === null || (float) $tope <= 0 || $this->deudaDeLaTienda($negocioId) >= (float) $tope) {
            throw new RuntimeException(
                'Por ahora esta tienda no está vendiendo a crédito. Elige otro método de pago.'
            );
        }

        $disponible = max(0.0, (float) $credito->credit_limit - $this->usado($credito->id));

        if ($total > $disponible + 0.005) {
            throw new RuntimeException(sprintf(
                'Tu crédito disponible en esta tienda es %s y el pedido cuesta %s.',
                $this->pesos($disponible),
                $this->pesos($total),
            ));
        }

        return StoreCreditMovement::create([
            'store_credit_id' => $credito->id,
            'type'            => StoreCreditMovement::CONSUMO,
            'amount'          => $total,
            'order_id'        => $order->orderSales_id,
            'created_by'      => $porQuien ?? $userId,
        ]);
    }

    /** Devuelve lo que un pedido cancelado había gastado. Idempotente. */
    public function reversar(OrdersSales $order, ?int $porQuien = null): ?StoreCreditMovement
    {
        $consumo = StoreCreditMovement::where('order_id', $order->orderSales_id)
            ->where('type', StoreCreditMovement::CONSUMO)
            ->first();

        if (!$consumo) {
            return null;
        }

        $yaDevuelto = StoreCreditMovement::where('order_id', $order->orderSales_id)
            ->where('type', StoreCreditMovement::REVERSO)
            ->exists();

        if ($yaDevuelto) {
            return null;
        }

        return StoreCreditMovement::create([
            'store_credit_id' => $consumo->store_credit_id,
            'type'            => StoreCreditMovement::REVERSO,
            'amount'          => -1 * (float) $consumo->amount,
            'order_id'        => $order->orderSales_id,
            'created_by'      => $porQuien,
        ]);
    }

    /** El comprador le pagó a la tienda: se libera ese valor del cupo. */
    public function abonar(int $negocioId, int $userId, float $monto, ?int $porQuien = null, ?string $nota = null): StoreCreditMovement
    {
        if ($monto <= 0) {
            throw new RuntimeException('El abono tiene que ser mayor a cero.');
        }

        return DB::transaction(function () use ($negocioId, $userId, $monto, $porQuien, $nota) {
            $credito = StoreCredit::where('busines_id', $negocioId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if (!$credito) {
                throw new RuntimeException('Este cliente no tiene crédito en tu tienda.');
            }

            $usado = $this->usado($credito->id);

            if ($monto > $usado + 0.005) {
                throw new RuntimeException(sprintf(
                    'El cliente debe %s: el abono no puede ser mayor.',
                    $this->pesos($usado),
                ));
            }

            return StoreCreditMovement::create([
                'store_credit_id' => $credito->id,
                'type'            => StoreCreditMovement::ABONO,
                'amount'          => -1 * round($monto, 2),
                'notes'           => $nota,
                'created_by'      => $porQuien,
            ]);
        });
    }

    private function pesos(float $valor): string
    {
        return '$' . number_format($valor, 0, ',', '.');
    }
}
