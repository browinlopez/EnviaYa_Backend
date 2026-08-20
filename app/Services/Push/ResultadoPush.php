<?php

namespace App\Services\Push;

/**
 * Qué pasó con UN token.
 *
 * `permanente` es la distinción que importa: un token rechazado porque la app se
 * desinstaló no vuelve a servir nunca, y hay que marcarlo para no gastar envíos
 * en él. Un error de red o un 500 del proveedor es temporal y el mismo token
 * servirá en el siguiente intento. Tratarlos igual significa o bien perder
 * suscriptores buenos, o bien reintentar eternamente contra teléfonos que ya no
 * existen.
 */
class ResultadoPush
{
    private function __construct(
        public readonly bool $ok,
        public readonly bool $permanente,
        public readonly ?string $motivo,
    ) {
    }

    public static function entregado(): self
    {
        return new self(true, false, null);
    }

    /** El token ya no vale: app desinstalada, sesión cerrada, token rotado. */
    public static function tokenMuerto(string $motivo): self
    {
        return new self(false, true, $motivo);
    }

    /** Fallo pasajero: red, cuota, error del proveedor. Se puede reintentar. */
    public static function falloTemporal(string $motivo): self
    {
        return new self(false, false, $motivo);
    }
}
