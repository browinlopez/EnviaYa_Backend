<?php

namespace App\Services;

use App\Models\Operacion\Settlement;
use App\Models\Operacion\SettlementItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Genera el corte de cuentas de un periodo.
 *
 * Toda la aritmética vive acá y no en el controlador porque es la parte que hay
 * que poder leer entera para creerle. Dos reglas la gobiernan:
 *
 *  1. Solo entran pedidos ENTREGADOS (state 4). Un pedido en camino todavía
 *     puede cancelarse, y pagarlo por adelantado obligaría a descontarlo del
 *     corte siguiente.
 *
 *  2. Un pedido no se liquida dos veces. Se excluyen los que ya estén en
 *     cualquier otro corte no anulado del mismo tipo, así que reejecutar el
 *     periodo no duplica nada — que es justo lo que pasa cuando alguien vuelve
 *     a generar "por si acaso".
 */
class LiquidacionService
{
    /**
     * @param string $tipo 'business' | 'domiciliary'
     */
    public function generar(
        string $tipo,
        int $destinatarioId,
        string $desde,
        string $hasta,
        ?int $creadoPor = null,
    ): Settlement {
        if (!in_array($tipo, ['business', 'domiciliary'], true)) {
            throw new RuntimeException('Tipo de liquidación no válido.');
        }

        $columna = $tipo === 'business' ? 'busines_id' : 'domiciliary_id';

        return DB::transaction(function () use ($tipo, $destinatarioId, $desde, $hasta, $creadoPor, $columna) {
            $pedidos = DB::table('orderssales as o')
                ->where("o.{$columna}", $destinatarioId)
                ->where('o.state', 4) // entregado
                ->whereDate('o.sale_date', '>=', $desde)
                ->whereDate('o.sale_date', '<=', $hasta)
                // Ya liquidados en otro corte vivo del mismo tipo.
                ->whereNotExists(function ($sub) use ($tipo) {
                    $sub->from('settlement_items as si')
                        ->join('settlements as s', 's.id', '=', 'si.settlement_id')
                        ->whereColumn('si.order_id', 'o.orderSales_id')
                        ->where('s.type', $tipo)
                        ->where('s.state', '!=', Settlement::ANULADA)
                        ->selectRaw('1');
                })
                ->get([
                    'o.orderSales_id',
                    'o.subtotal',
                    'o.domicilio',
                    'o.domiciliary_fee',
                    'o.discount',
                ]);

            if ($pedidos->isEmpty()) {
                throw new RuntimeException(
                    'No hay pedidos entregados sin liquidar en ese periodo.'
                );
            }

            $liq = Settlement::create([
                'type'           => $tipo,
                'business_id'    => $tipo === 'business' ? $destinatarioId : null,
                'domiciliary_id' => $tipo === 'domiciliary' ? $destinatarioId : null,
                'period_start'   => $desde,
                'period_end'     => $hasta,
                'state'          => Settlement::BORRADOR,
                'created_by'     => $creadoPor,
            ]);

            $totales = ['gross' => 0.0, 'delivery' => 0.0, 'discounts' => 0.0, 'net' => 0.0];

            foreach ($pedidos as $p) {
                [$bruto, $domicilio, $descuento, $neto] = $this->repartir($tipo, $p);

                SettlementItem::create([
                    'settlement_id' => $liq->id,
                    'order_id'      => $p->orderSales_id,
                    'gross'         => $bruto,
                    'delivery_fee'  => $domicilio,
                    'discount'      => $descuento,
                    'net'           => $neto,
                ]);

                $totales['gross']     += $bruto;
                $totales['delivery']  += $domicilio;
                $totales['discounts'] += $descuento;
                $totales['net']       += $neto;
            }

            $liq->update([
                'orders_count'  => $pedidos->count(),
                'gross'         => round($totales['gross'], 2),
                'delivery_fees' => round($totales['delivery'], 2),
                'discounts'     => round($totales['discounts'], 2),
                // Lo que retiene la plataforma es la diferencia entre lo que
                // generó el periodo y lo que se transfiere.
                'platform_fee'  => round($totales['gross'] + $totales['delivery'] - $totales['net'] - $totales['discounts'], 2),
                'net_payable'   => round($totales['net'], 2),
            ]);

            return $liq->fresh();
        });
    }

    /**
     * Qué le toca a cada quien de un pedido.
     *
     * NEGOCIO: el subtotal de productos menos el descuento del cupón. El
     * domicilio no es suyo, y el descuento se le resta porque la promoción se
     * aplicó sobre su venta.
     *
     * DOMICILIARIO: solo `domiciliary_fee`, que ya quedó congelado al crear el
     * pedido con el reparto pactado ese día. Recalcularlo con la comisión de
     * hoy cambiaría lo que se le debe por entregas que ya hizo.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} bruto, domicilio, descuento, neto
     */
    private function repartir(string $tipo, object $p): array
    {
        $subtotal  = (float) ($p->subtotal ?? 0);
        $domicilio = (float) ($p->domicilio ?? 0);
        $descuento = (float) ($p->discount ?? 0);
        $comision  = (float) ($p->domiciliary_fee ?? 0);

        if ($tipo === 'business') {
            return [$subtotal, 0.0, $descuento, max(0, $subtotal - $descuento)];
        }

        return [0.0, $domicilio, 0.0, $comision];
    }
}
