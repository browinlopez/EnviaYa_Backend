<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\MediaService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * RESUELVE LA IMAGEN PRINCIPAL DE UNAS FILAS YA LEÍDAS.
 *
 * Esto estaba en `App\Support\Consultas\AyudasDeAdmin` junto a tres consultas,
 * y NO ES UNA CONSULTA: recibe filas hechas, pide un `MediaService` y decora
 * cada una con una URL. Es presentación. Por eso no se fue a `app/Queries/`
 * con las otras tres cuando ese rasgo se deshizo: meterla ahí habría sido
 * cambiar una carpeta mal nombrada por otra.
 *
 * SIGUE SIENDO UN RASGO, a diferencia de las consultas, y es deliberado: no
 * tiene estado, no tiene constantes que se cuelen en quien lo use --que era la
 * mitad del problema del rasgo viejo-- y sus tres consumidores lo aplican a
 * las filas justo antes de responder. Una clase para una función sin estado
 * sería la ceremonia que §05 prohíbe.
 */
trait ImagenPrincipalEnLaFila
{
    /**
     * Resuelve la imagen principal de una colección desde `media_files`.
     *
     * El campo `logo`/`image` del registro solo se rellena cuando hay dominio
     * público configurado, porque una URL firmada caduca y no se puede
     * persistir. Para que el panel muestre la imagen igualmente, se resuelve
     * al vuelo desde la tabla de archivos.
     *
     * Se hace en UNA consulta para toda la colección: pedir la imagen fila a
     * fila sería el problema N+1 de manual.
     *
     * `stdClass` y no `object`: los genéricos de `Collection` son INVARIANTES,
     * así que un `Collection<int, object>` NO acepta el
     * `Collection<int, stdClass>` que devuelve `DB::table()`. Declararlo con
     * el supertipo, que es lo que parece más permisivo, es justo lo que lo
     * rompe.
     *
     * @param  Collection<int, stdClass>  $filas
     * @return Collection<int, stdClass>
     */
    protected function conImagenPrincipal($filas, string $entidad, string $campoId, string $campoImagen, MediaService $medios)
    {
        $ids = $filas->pluck($campoId)->filter()->all();

        if (! $ids) {
            return $filas;
        }

        $principales = DB::table('media_files')
            ->where('entity_type', $entidad)
            ->whereIn('entity_id', $ids)
            ->where('is_primary', true)
            ->pluck('object_key', 'entity_id');

        return $filas->map(function ($fila) use ($principales, $campoId, $campoImagen, $medios) {
            $key = $principales[$fila->{$campoId}] ?? null;

            /*
             * El archivo marcado como principal MANDA sobre la columna.
             *
             * La columna suele arrastrar la URL con la que se sembró el
             * registro (una imagen de Google, por ejemplo). Si alguien sube un
             * logo desde el panel, ese es el logo actual: dejar ganar a la
             * columna haría que la subida no se viera y pareciera que falló.
             */
            if ($key) {
                $fila->{$campoImagen} = $medios->url($key);
            }

            return $fila;
        });
    }
}
