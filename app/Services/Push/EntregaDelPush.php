<?php

declare(strict_types=1);

namespace App\Services\Push;

/**
 * CÓMO SE ENTREGA UN PUSH, ADEMÁS DE QUÉ DICE.
 *
 * Casi todos los avisos valen igual dentro de una hora que ahora. Los de la
 * portería no: «¿Dejas entrar a Carlos?» sirve cinco minutos. Después la
 * visita ya entró o se fue, y un teléfono que vuelve a tener señal a la media
 * hora no debería sonar por algo que ya no se puede contestar.
 *
 * Dos opciones viajan, apartadas, junto a los datos del aviso:
 *
 *  · ETIQUETA: los avisos con la misma etiqueta se reemplazan en la barra en
 *    vez de apilarse. Cuando alguien del apartamento contesta, a los demás
 *    se les cambia «¿Dejas entrar…?» por «Andrea lo dejó entrar».
 *  · VENCE: hasta cuándo vale (segundos Unix). Si el teléfono no estaba
 *    conectado y vuelve después, el proveedor ya no lo entrega.
 *
 * Van con guion bajo dentro de los datos para no cambiar la firma de los
 * transportes, y cada transporte las saca antes de mandar: no llegan a la app.
 */
final class EntregaDelPush
{
    public const ETIQUETA = '_etiqueta';

    public const VENCE = '_vence';

    /**
     * @param  array<string, mixed>  $datos
     * @return array{0: array<string, mixed>, 1: ?string, 2: ?int}
     */
    public static function separar(array $datos): array
    {
        $etiqueta = isset($datos[self::ETIQUETA]) ? (string) $datos[self::ETIQUETA] : null;
        $vence = isset($datos[self::VENCE]) ? (int) $datos[self::VENCE] : null;

        unset($datos[self::ETIQUETA], $datos[self::VENCE]);

        return [$datos, $etiqueta ?: null, $vence ?: null];
    }

    /** ¿Ya no vale la pena mandarlo? */
    public static function vencido(array $datos): bool
    {
        [, , $vence] = self::separar($datos);

        return $vence !== null && $vence <= time();
    }

    /** Lo que le queda de vida, para el `ttl` de Firebase. Nunca negativo. */
    public static function segundosQueQuedan(int $vence): int
    {
        return max(0, $vence - time());
    }
}
