<?php

declare(strict_types=1);

namespace App\Services\Push;

// Van dentro de los datos para no cambiar la firma de los transportes, que las sacan.
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
