<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order\OrdersSales;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * LOS PEDIDOS PROGRAMADOS, A TIEMPO.
 *
 * Un pedido para mañana a las 3 llega hoy, y a la tienda se le avisa hoy. Para
 * mañana a las 3 ya lo olvidó. Por eso, media hora antes de la hora pedida:
 *
 *   · a la TIENDA se le recuerda que lo tenga listo (y, si todavía no lo
 *     aceptó, que lo acepte);
 *   · si va a domicilio y ya está aceptado, se anuncia a los domiciliarios,
 *     que es lo que el aceptar no hizo por ser para tan tarde.
 *
 * Una sola vez por pedido: `recordado_at`.
 */
class RecordatorioDeProgramados
{
    /** Cuánto antes de la hora pedida se recuerda. */
    public const MINUTOS_ANTES = 30;

    /** Si falta más que el recordatorio para la hora pedida. */
    public static function faltaMucho(OrdersSales $order, ?CarbonInterface $ahora = null): bool
    {
        if (! $order->is_scheduled || ! $order->delivery_date) {
            return false;
        }

        $ahora ??= now();

        return $order->delivery_date->gt($ahora->copy()->addMinutes(self::MINUTOS_ANTES));
    }

    /** Recuerda los que toca ahora. Devuelve cuántos. */
    /** El segundo aviso: un cuarto de hora antes. */
    public const MINUTOS_ANTES_ULTIMO = 15;

    /**
     * DOS AVISOS, A LOS DOS: a la tienda y al cliente, media hora antes y un
     * cuarto de hora antes. La tienda, para tenerlo listo; el cliente, para
     * no olvidarse de que pidió --y, si lo recoge, con su código a mano--.
     *
     * Cada uno sale UNA vez: `recordado_at` marca el de media hora y
     * `recordado_15_at` el de un cuarto de hora. Si el pedido se programó
     * con menos de media hora de margen, los dos tocarían a la vez: sale solo
     * el último, que es el que vale, y se marcan los dos.
     */
    public static function recordar(?CarbonInterface $ahora = null): int
    {
        $ahora ??= now();

        $pedidos = OrdersSales::query()
            ->where('is_scheduled', true)
            ->whereIn('state', [OrdersSales::NUEVO, OrdersSales::PREPARANDO])
            ->where(fn ($q) => $q->whereNull('recordado_at')->orWhereNull('recordado_15_at'))
            ->whereNotNull('delivery_date')
            ->where('delivery_date', '<=', $ahora->copy()->addMinutes(self::MINUTOS_ANTES))
            ->where('delivery_date', '>=', $ahora->copy()->subHours(2))
            ->with(['business.owners', 'buyer'])
            ->get();

        $avisados = 0;
        foreach ($pedidos as $pedido) {
            $ultimo = $pedido->delivery_date->lte($ahora->copy()->addMinutes(self::MINUTOS_ANTES_ULTIMO));

            if ($ultimo && ! $pedido->recordado_15_at) {
                self::recordarUno($pedido, 15, primeraVez: ! $pedido->recordado_at);
                $avisados++;
            } elseif (! $ultimo && ! $pedido->recordado_at) {
                self::recordarUno($pedido, 30, primeraVez: true);
                $avisados++;
            }
        }

        return $avisados;
    }

    private static function recordarUno(OrdersSales $pedido, int $minutos, bool $primeraVez): void
    {
        $hora = self::hora($pedido->delivery_date);
        $como = $pedido->pickup ? 'lo recogen en el negocio' : 'va a domicilio';
        $falta = $minutos === 15 ? 'En 15 minutos' : 'En media hora';
        $mensaje = (int) $pedido->state === OrdersSales::NUEVO
            ? "{$falta}: el pedido programado #{$pedido->orderSales_id} es para las {$hora} y {$como}. Todavía no lo has aceptado."
            : "{$falta}: el pedido programado #{$pedido->orderSales_id} es para las {$hora} y {$como}. Tenlo listo.";

        foreach ($pedido->business->owners as $duenio) {
            if ($duenio->user_id) {
                Avisos::para(
                    $duenio->user_id,
                    $minutos === 15 ? 'pedido_programado_15' : 'pedido_programado_pronto',
                    $mensaje,
                    ['order_id' => $pedido->orderSales_id],
                );
            }
        }

        /*
         * AL CLIENTE, lo mismo con sus palabras. Si lo recoge, el código va
         * en el aviso: es lo que tendrá que decir en el mostrador.
         */
        if ($pedido->buyer?->user_id) {
            $tienda = $pedido->business->name;
            $paraCliente = $pedido->pickup
                ? "{$falta} tu pedido de {$tienda} estará listo para recoger, a las {$hora}."
                    .($pedido->pin_entrega ? " Tu código para recogerlo es {$pedido->pin_entrega}." : '')
                : "{$falta} llega tu pedido de {$tienda}: lo programaste para las {$hora}.";

            Avisos::para(
                $pedido->buyer->user_id,
                $minutos === 15 ? 'recordatorio_programado_15' : 'recordatorio_programado',
                $paraCliente,
                ['order_id' => $pedido->orderSales_id],
            );
        }

        $pedido->recordado_at ??= now();
        if ($minutos === 15) {
            $pedido->recordado_15_at = now();
        }
        $pedido->save();

        if ($primeraVez && ! $pedido->pickup && (int) $pedido->state === OrdersSales::PREPARANDO) {
            AvisoDePedidoParaTomar::anunciar($pedido);
        }
    }

    public static function hora(CarbonInterface $fecha): string
    {
        $f = Carbon::instance($fecha)->setTimezone(config('app.timezone'));
        $h = $f->format('g');
        $m = $f->format('i');
        $ampm = $f->format('A') === 'AM' ? 'a. m.' : 'p. m.';

        return ($m === '00' ? $h : "{$h}:{$m}").' '.$ampm;
    }

    /** «hoy a las 3 p. m.», «mañana a las 3 p. m.», «el 28/11 a las 3 p. m.» */
    public static function cuando(CarbonInterface $fecha, ?CarbonInterface $ahora = null): string
    {
        $ahora ??= now();
        $f = Carbon::instance($fecha);
        $dia = $f->isSameDay($ahora)
            ? 'hoy'
            : ($f->isSameDay($ahora->copy()->addDay()) ? 'mañana' : 'el '.$f->format('d/m'));

        return "{$dia} a las ".self::hora($f);
    }
}
