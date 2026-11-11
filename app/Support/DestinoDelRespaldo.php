<?php

namespace App\Support;

use RuntimeException;

/**
 * LA COPIA DE SEGURIDAD NO VA A UN BUCKET PÚBLICO.
 *
 * `config/filesystems.php` lo explica en su cabecera y lo resuelve con dos
 * discos: `r2` para los medios —fotos de productos, logos— que **lleva `url`
 * porque la app tiene que poder enseñarlas—, y `r2_respaldos` para las copias,
 * deliberadamente **sin `url`**.
 *
 * El problema es que lo que elige entre los dos es una variable de entorno,
 * `BACKUP_DISK`, y los dos nombres se parecen. Escribir `r2` donde iba
 * `r2_respaldos` no da ningún error: la copia se sube, el monitor la encuentra
 * sana, el aviso diario dice que todo está bien **y el volcado de la base de
 * datos entera queda descargable por cualquiera que pruebe la URL** —personas,
 * teléfonos, direcciones, pagos—.
 *
 * Se escribió al preparar las instrucciones del despliegue, después de
 * recomendar por escrito `BACKUP_DISK=r2`. Esa recomendación era el fallo
 * exacto que esto impide.
 *
 * QUÉ HACE: antes de volcar la base, mira el disco de destino. Si tiene `url`,
 * aborta. Spatie convierte la excepción en un respaldo fallido y manda el
 * aviso por el canal de siempre, así que el síntoma es un correo que dice qué
 * pasa, en vez de un silencio tranquilizador.
 *
 * NO COMPRUEBA SI EL BUCKET ES PÚBLICO DE VERDAD —eso se decide en Cloudflare
 * y desde aquí no se ve—. Comprueba lo que sí puede ver: que el disco esté
 * declarado para servir enlaces, que es como se declara un disco destinado a
 * contenido público. Un bucket privado configurado con `url` sigue siendo un
 * sitio equivocado para una copia.
 */
class DestinoDelRespaldo
{
    /**
     * Revienta si el disco donde se va a guardar la copia sirve enlaces.
     *
     * @param  list<string>|null  $discos  por omisión, los de `backup.backup.destination.disks`
     */
    public static function comprobar(?array $discos = null): void
    {
        $discos ??= (array) config('backup.backup.destination.disks', []);

        foreach ($discos as $nombre) {
            $nombre = (string) $nombre;
            $publico = config('filesystems.disks.'.$nombre.'.url');

            if ($publico === null || $publico === '') {
                continue;
            }

            throw new RuntimeException(
                "La copia de seguridad apunta al disco «{$nombre}», que sirve "
                ."enlaces públicos ({$publico}). Una copia es la base de datos "
                .'entera: no puede vivir en un disco con URL. Para Cloudflare R2 '
                .'el disco de copias es «r2_respaldos», no «r2».'
            );
        }
    }
}
