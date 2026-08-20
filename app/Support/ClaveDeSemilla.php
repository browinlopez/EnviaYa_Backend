<?php

namespace App\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * LA CLAVE DE LOS USUARIOS DE PRUEBA
 *
 * Estaba escrita en el repositorio: `Hash::make('password123')` para cuatro
 * usuarios, uno de ellos con rol 4 y correo `admin@gmail.com`. Cualquiera que
 * clonara el proyecto —o que leyera el repositorio— tenía la clave del
 * administrador, y basta con que alguien corra `db:seed` una vez en el servidor
 * de verdad para que ese usuario exista en producción.
 *
 * Ahora la clave sale del entorno y NUNCA del código:
 *
 *  · con `SEED_PASSWORD` puesta, se usa esa —así el entorno local y las pruebas
 *    de navegador siguen entrando con una clave conocida, pero la decisión está
 *    en el `.env` de cada máquina y no en el repositorio;
 *
 *  · sin ella, se genera una al azar y se IMPRIME una vez. Es incómodo a
 *    propósito: si a alguien le hace falta la clave, la copia de la salida; lo
 *    que no puede pasar es que se cree una cuenta con una clave que ya está
 *    publicada.
 *
 * Se memoriza por proceso para que los cuatro usuarios de una misma corrida
 * queden con la misma clave; con una distinta cada uno, la salida del comando
 * sería la única forma de saber cuál es cuál.
 */
class ClaveDeSemilla
{
    private static ?string $clave = null;
    private static bool $anunciada = false;

    public static function resolver(?Command $command = null): string
    {
        if (self::$clave === null) {
            // Por configuración y no con `env()` directo: con la configuración
            // en caché —lo normal en producción— `env()` fuera de config/
            // devuelve null.
            $delEntorno = (string) config('semillas.clave', '');

            self::$clave = $delEntorno !== ''
                ? $delEntorno
                : Str::password(16, symbols: false);

            if ($delEntorno === '' && !self::$anunciada) {
                self::$anunciada = true;

                $command?->newLine();
                $command?->warn('No hay SEED_PASSWORD en el entorno, así que se generó una clave:');
                $command?->line('  ' . self::$clave);
                $command?->line('  Cópiala ahora: no se vuelve a mostrar y no queda en ningún archivo.');
                $command?->newLine();
            }
        }

        return self::$clave;
    }

    /** Solo para las pruebas: olvida la clave memorizada. */
    public static function olvidar(): void
    {
        self::$clave = null;
        self::$anunciada = false;
    }
}
