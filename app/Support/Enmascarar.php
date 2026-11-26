<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Un dato personal con solo sus últimos cuatro caracteres a la vista.
 *
 * `••••5678` alcanza para que el celador confirme «sí, es la cédula que
 * termina en 5678» con la persona delante, sin que el libro de la portería
 * se vuelva un directorio de cédulas y teléfonos de todo el que pasó.
 */
final class Enmascarar
{
    public const PUNTOS = '••••';

    public static function ultimos4(?string $valor): ?string
    {
        if ($valor === null || trim($valor) === '') {
            return $valor;
        }

        $limpio = trim($valor);

        // Con cuatro o menos no queda nada que esconder sin enseñarlo entero.
        if (mb_strlen($limpio) <= 4) {
            return self::PUNTOS;
        }

        return self::PUNTOS.mb_substr($limpio, -4);
    }
}
