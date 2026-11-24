<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order\OrdersSales;

/**
 * EL PIN DE ENTREGA: TRES DÍGITOS QUE SÓLO TIENE EL COMPRADOR.
 *
 * Una entrega era la palabra del domiciliario: tocaba «Sí, lo entregué» y el
 * pedido pasaba a entregado. Si el cliente decía «nunca me llegó», no había
 * con qué contestarle. Con el PIN, el domiciliario sólo cierra la entrega si
 * tiene delante a quien lo ve en su app.
 *
 * TRES DÍGITOS Y NO SEIS: se dicen en voz alta en una puerta, con la moto
 * encendida. Seis dígitos se equivocan; tres no. A cambio se adivinan
 * probando, y por eso hay un tope de intentos: a los cinco fallos se bloquea,
 * y el negocio --que también ve el PIN-- se lo puede dar.
 *
 * Los pedidos despachados antes de que esto existiera no tienen PIN, y para
 * ellos no se exige: no se puede pedir un número que nadie recibió.
 */
class PinDeEntrega
{
    public const INTENTOS = 5;

    public static function generar(): string
    {
        return str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Null si se puede entregar; si no, la respuesta de error (mensaje, motivo
     * y código HTTP). Cuenta el intento fallido en el pedido.
     */
    public static function comprobar(OrdersSales $pedido, ?string $pin): ?array
    {
        if (! $pedido->pin_entrega) {
            return null;
        }

        if ((int) $pedido->pin_intentos >= self::INTENTOS) {
            return [
                'message' => 'Se acabaron los intentos del PIN. Pídeselo al negocio: lo ve en el pedido.',
                'reason' => 'pin_bloqueado',
                'status' => 423,
            ];
        }

        $pin = preg_replace('/\D/', '', (string) $pin);

        if ($pin === '') {
            return [
                'message' => 'Pídele al cliente el PIN de entrega: son 3 números que ve en su app.',
                'reason' => 'pin_requerido',
                'status' => 422,
            ];
        }

        if (! hash_equals((string) $pedido->pin_entrega, $pin)) {
            $pedido->pin_intentos = (int) $pedido->pin_intentos + 1;
            $pedido->save();

            $quedan = self::INTENTOS - (int) $pedido->pin_intentos;

            return [
                'message' => $quedan > 0
                    ? "Ese PIN no es. Te quedan {$quedan} intentos."
                    : 'Ese PIN no es, y se acabaron los intentos. Pídeselo al negocio.',
                'reason' => 'pin_incorrecto',
                'intentos_restantes' => max(0, $quedan),
                'status' => 422,
            ];
        }

        return null;
    }
}
