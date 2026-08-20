<?php

namespace App\Services\Push;

/**
 * POR DÓNDE SALE UNA NOTIFICACIÓN
 *
 * La interfaz existe para que el resto del módulo —segmentar, encolar, contar lo
 * entregado, pintarlo en la pantalla— no dependa de Firebase. Cambiar de
 * proveedor, o no tener ninguno todavía, no puede obligar a tocar nada más.
 *
 * Devuelve el resultado POR TOKEN y no un booleano de todo el lote: en un envío
 * de mil teléfonos siempre hay unos cuantos que ya no existen, y lo que hay que
 * hacer con esos es marcarlos para no volver a intentarlo. Un "falló el lote"
 * obligaría a reintentar los novecientos que sí llegaron.
 */
interface TransportePush
{
    /**
     * @param  list<string>  $tokens
     * @param  array<string, string>  $datos  Carga útil para la app (a dónde llevar al tocar).
     * @return array<string, ResultadoPush>  token => resultado
     */
    public function enviar(array $tokens, string $titulo, string $cuerpo, array $datos = []): array;

    /** Si no está configurado, la pantalla lo dice en vez de fingir que envió. */
    public function configurado(): bool;

    /** Nombre legible para el registro y para la pantalla. */
    public function nombre(): string;
}
