<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * La extensión con la que se GUARDA un archivo subido.
 *
 * Salía de `getClientOriginalExtension()`: la decide quien sube. La
 * validación `mimes:` mira el CONTENIDO, así que un PNG llamado `foto.php`
 * pasaba como imagen y quedaba guardado como `.php` en el bucket —servido
 * con esa extensión por quien lo sirva—.
 *
 * Ahora sale del tipo detectado en el contenido (`guessExtension()`), y solo
 * si está en la lista de lo que la plataforma acepta. Lo demás se guarda con
 * la de respaldo, que nunca es ejecutable.
 */
final class ExtensionSegura
{
    /** Lo que la plataforma recibe: imágenes y PDF. */
    public const PERMITIDAS = ['jpg', 'png', 'webp', 'gif', 'pdf', 'heic', 'heif', 'avif'];

    public static function de(UploadedFile $archivo, string $respaldo = 'bin'): string
    {
        $detectada = strtolower((string) $archivo->guessExtension());

        // `image/jpeg` se adivina como `jpeg` en algunas versiones.
        if ($detectada === 'jpeg') {
            $detectada = 'jpg';
        }

        return in_array($detectada, self::PERMITIDAS, true) ? $detectada : $respaldo;
    }
}
