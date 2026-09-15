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
                /*
                 * Y CON EL PAGO CONFIRMADO.
                 *
                 * Antes solo miraba el estado, así que un pedido entregado
                 * cuyo pago en línea fue rechazado entraba igual al corte: se
                 * le transfería al negocio dinero que nadie llegó a cobrar.
                 * `confirmados()` es el mismo criterio que usa el resto del
                 * sistema para saber si un pedido está pagado.
                 */
                ->whereNotIn('o.payment_state', \App\Models\Order\OrdersSales::SIN_PAGO)
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
                    // Congeladas al crear el pedido. Ver la migración
                    // `add_dinero_congelado_to_orderssales`.
                    'o.platform_fee',
                    'o.cash_due',
                    'o.total',
                    'o.methods_id',
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

            $totales = [
                'gross' => 0.0, 'delivery' => 0.0, 'discounts' => 0.0,
                'net' => 0.0, 'retenido' => 0.0, 'credito' => 0.0,
            ];

            foreach ($pedidos as $p) {
                [$bruto, $domicilio, $descuento, $neto, $retenido, $credito] = $this->repartir($tipo, $p);

                SettlementItem::create([
                    'settlement_id' => $liq->id,
                    'order_id'      => $p->orderSales_id,
                    'gross'         => $bruto,
                    'delivery_fee'  => $domicilio,
                    'discount'      => $descuento,
                    'credit'        => $credito,
                    'net'           => $neto,
                ]);
                $totales['credito']   += $credito;

                $totales['gross']     += $bruto;
                $totales['delivery']  += $domicilio;
                $totales['discounts'] += $descuento;
                $totales['net']       += $neto;
                $totales['retenido']  += $retenido;
            }

            /*
             * EL ROJO DEL CORTE ANTERIOR ENTRA A ESTE.
             *
             * Un día con más ventas a crédito que venta cobrada deja a la tienda
             * debiéndole a la plataforma. No se le cobra aparte: se descuenta
             * del siguiente corte, y se anota de cuál vino para que no se
             * descuente dos veces. Anular este corte libera ese rojo para el
             * próximo, porque la búsqueda ignora los cortes anulados.
             */
            $arrastre = null;
            if ($tipo === 'business') {
                $arrastre = app(CreditoDeTienda::class)->corteEnRojoPendiente($destinatarioId);
                if ($arrastre && $arrastre->id === $liq->id) {
                    $arrastre = null;
                }
            }
            $arrastrado = $arrastre ? (float) $arrastre->net_payable : 0.0;
            $liq->update([
                'credit_sales'    => round($totales['credito'], 2),
                'carried_in'      => round($arrastrado, 2),
                'carried_from_id' => $arrastre?->id,
                'orders_count'  => $pedidos->count(),
                'gross'         => round($totales['gross'], 2),
                'delivery_fees' => round($totales['delivery'], 2),
                'discounts'     => round($totales['discounts'], 2),
                /*
                 * Lo retenido se SUMA de lo congelado en cada pedido, ya no se
                 * deduce restando totales. Antes salía como residuo y para un
                 * negocio daba siempre 0 —o sea, la plataforma no cobraba
                 * comisión— y para un domiciliario daba la parte del domicilio
                 * que no era suya. Con el efectivo de por medio esa resta ya no
                 * significaría nada.
                 */
                'platform_fee'  => round($totales['retenido'], 2),
                'net_payable'   => round($totales['net'] + $arrastrado, 2),
            ]);

            return $liq->fresh();
        });
    }

    /**
     * Qué le toca a cada quien de un pedido.
     *
     * NEGOCIO: el subtotal de productos, menos el descuento del cupón y menos
     * la comisión de la plataforma. El domicilio no es suyo. La comisión sale
     * congelada del pedido y NO se recalcula con el porcentaje de hoy: subirlo
     * del 3 % al 5 % no puede cambiarle lo que ya se le liquidó el mes pasado.
     *
     * DOMICILIARIO: su comisión, MENOS el efectivo que recaudó y todavía no ha
     * consignado. Por eso el neto puede ser NEGATIVO, y entonces el corte no es
     * un pago: es una deuda. Recauda el total del pedido y gana una fracción
     * del domicilio, así que lo normal es que deba.
     *
     * CRÉDITO DE LA TIENDA: el pedido lo cobra la tienda directamente al
     * comprador —productos Y domicilio—, así que ese total se le DESCUENTA.
     * Ejemplo: productos 10.000, domicilio 2.000, comisión 300 → la tienda
     * gana 9.700 pero recibe 12.000 del cliente: el pedido le resta 2.300 al
     * corte. Por eso el neto del negocio YA NO se topa en cero.
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     *         bruto, domicilio, descuento, neto, retenido, crédito
     */
    private function repartir(string $tipo, object $p): array
    {
        $credito   = (int) ($p->methods_id ?? 0) === CreditoDeTienda::METODO
            ? (float) ($p->total ?? 0)
            : 0.0;
        $subtotal  = (float) ($p->subtotal ?? 0);
        $domicilio = (float) ($p->domicilio ?? 0);
        $descuento = (float) ($p->discount ?? 0);
        $comision  = (float) ($p->domiciliary_fee ?? 0);
        $retencion = (float) ($p->platform_fee ?? 0);
        $efectivo  = (float) ($p->cash_due ?? 0);

        if ($tipo === 'business') {
            $neto = $subtotal - $descuento - $retencion - $credito;

            return [$subtotal, 0.0, $descuento, $neto, $retencion, $credito];
        }

        // Sin `max(0, ...)` a propósito: si debe más de lo que ganó, el saldo
        // tiene que poder salir en rojo. Taparlo con un cero sería perder la
        // deuda.
        return [0.0, $domicilio, 0.0, $comision - $efectivo, 0.0, 0.0];
    }
}
