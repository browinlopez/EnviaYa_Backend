<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * DE QUÉ HABLA UN MENSAJE DE CHAT, EN UNA FRASE.
 *
 * El chat no solo lleva texto: lleva TARJETAS. Cuando el tendero arma un
 * pedido para el cliente, lo que viaja por el chat es un JSON que la app
 * convierte en una tarjeta con sus productos y sus botones.
 *
 * Eso está bien dentro del chat. El problema era el aviso: la notificación
 * copiaba el contenido tal cual y recortaba a 120 caracteres, así que en la
 * campana y en la pantalla bloqueada aparecía esto:
 *
 *     {"type":"preorder","preorder_id":1792531797473,"status":"pending",…
 *
 * Un JSON crudo en la cara del cliente. Esta clase traduce esas tarjetas a
 * una frase que se entiende —«Te armó un pedido: 3 productos · $12.400»— y
 * deja el texto normal como estaba.
 *
 * SE DECIDE POR EL CONTENIDO, no por un campo aparte, porque el tipo ya viaja
 * dentro del JSON y añadir otra columna solo daría dos sitios donde mirar.
 */
class ResumenDeMensaje
{
    /** Lo más largo que se manda a la campana. Más allá, el sistema recorta. */
    private const LARGO = 120;

    public static function para(?string $contenido): string
    {
        $texto = trim((string) $contenido);

        if ($texto === '') {
            return 'Te envió un mensaje.';
        }

        $datos = self::comoTarjeta($texto);

        if ($datos === null) {
            return Str::limit($texto, self::LARGO);
        }

        return match ($datos['type']) {
            'preorder'        => self::pedidoArmado($datos),
            'preorder_status' => self::estadoDelPedido($datos),
            /*
             * Una tarjeta que todavía no conocemos. Se dice algo verdadero y
             * neutro en vez de enseñar el JSON: el día que alguien añada un
             * tipo nuevo y olvide pasar por acá, el peor caso es un aviso
             * poco específico, no un volcado de datos.
             */
            default           => 'Te envió un mensaje.',
        };
    }

    /** El JSON de una tarjeta, o null si es texto corriente. */
    private static function comoTarjeta(string $texto): ?array
    {
        if (!str_starts_with($texto, '{')) {
            return null;
        }

        $datos = json_decode($texto, true);

        return is_array($datos) && is_string($datos['type'] ?? null) ? $datos : null;
    }

    private static function pedidoArmado(array $datos): string
    {
        $estado = (string) ($datos['status'] ?? 'pending');

        if ($estado !== 'pending') {
            return self::estadoDelPedido($datos);
        }

        $cuantos = is_array($datos['items'] ?? null) ? count($datos['items']) : 0;
        $total = self::plata($datos['total'] ?? null);

        if ($cuantos === 0) {
            return 'Te armó un pedido. Ábrelo para confirmarlo.';
        }

        $productos = $cuantos === 1 ? '1 producto' : "{$cuantos} productos";

        return $total === null
            ? "Te armó un pedido: {$productos}. Ábrelo para confirmarlo."
            : "Te armó un pedido: {$productos} · {$total}.";
    }

    private static function estadoDelPedido(array $datos): string
    {
        return match ((string) ($datos['status'] ?? '')) {
            'confirmed' => 'Confirmó el pedido.',
            'cancelled' => 'Canceló el pedido.',
            default     => 'Actualizó el pedido.',
        };
    }

    /** «$12.400», igual que en la app: punto para los miles. */
    private static function plata($valor): ?string
    {
        if (!is_numeric($valor)) {
            return null;
        }

        return '$' . number_format((float) $valor, 0, ',', '.');
    }
}
