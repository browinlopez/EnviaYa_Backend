<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Query\Builder;

/**
 * Buscar «lo que contenga este texto» sin que el texto traiga comodines.
 *
 * `where(col, 'like', "%{$buscar}%")` mete lo que escribe el usuario tal
 * cual en el patrón: un `%` lo encuentra TODO y un `_` vale por cualquier
 * letra. En el libro de la portería eso convertía el buscador en «dame las
 * 300 últimas entradas» con una sola tecla.
 *
 * EL CARÁCTER DE ESCAPE ES `!` Y NO `\`, a propósito. MySQL usa `\` por
 * defecto, pero SQLite —donde corren las pruebas— no tiene escape por
 * defecto, y escribir `ESCAPE '\'` significa un carácter en MySQL y dos en
 * SQLite (que entonces lo rechaza). `ESCAPE '!'` es lo mismo en los dos
 * motores, y con un escape explícito la `\` del usuario pasa a ser una
 * barra literal también en MySQL.
 */
final class Like
{
    public const ESCAPE = '!';

    /** El texto con `!`, `%` y `_` escapados para ir dentro de un LIKE. */
    public static function escape(string $texto): string
    {
        return str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $texto,
        );
    }

    /**
     * `columna LIKE '%texto%'` con el texto escapado.
     *
     * La columna es SIEMPRE un nombre escrito en el código, nunca algo que
     * venga de la petición: va dentro de `whereRaw`.
     */
    public static function contiene(Builder $consulta, string $columna, string $texto, string $boolean = 'and'): Builder
    {
        return $consulta->whereRaw(
            $consulta->getGrammar()->wrap($columna)." LIKE ? ESCAPE '".self::ESCAPE."'",
            ['%'.self::escape($texto).'%'],
            $boolean,
        );
    }
}
