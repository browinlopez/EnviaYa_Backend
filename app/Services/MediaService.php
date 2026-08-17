<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Archivos de cualquier entidad en Cloudflare R2.
 *
 * El inventario vive en la tabla `media_files`, no en columnas del registro:
 * un negocio tiene logo, galería y documentos, y eso es una relación uno a
 * muchos. Además listar desde la base es inmediato, mientras que recorrer el
 * bucket con LIST cuesta una llamada por carpeta.
 *
 * Se guarda la CLAVE del objeto, nunca su URL:
 *  · la URL firmada mide ~600 caracteres y no cabía en `business.logo`
 *    (varchar 255), que es lo que rompía la subida;
 *  · y caduca, así que persistirla deja enlaces muertos.
 *
 * En el bucket cada entidad tiene su carpeta "<tipo>/<código>-<nombre>", para
 * poder atribuir un objeto suelto sin consultar la base.
 */
class MediaService
{
    private const DISCO = 'r2';
    private const MAX_BYTES = 8 * 1024 * 1024; // 8 MB

    /** Raíces permitidas: restringirlas evita escribir en cualquier sitio. */
    public const RAICES = [
        'negocios'  => 'negocios',
        'productos' => 'productos',
        'usuarios'  => 'usuarios',
        'conjuntos' => 'conjuntos',
        // Solo las de NEGOCIO: son las que la app pinta en su carrusel. Las de
        // producto no tienen columna de imagen en la base.
        'categorias' => 'categorias',
        // Piezas publicitarias. Van al mismo bucket que el resto en vez de a un
        // hospedaje aparte: la app ya sabe resolver estas URL y el panel ya
        // sabe subirlas, así que un segundo mecanismo solo para publicidad
        // sería otra cosa que mantener sin ganar nada.
        'banners' => 'banners',
    ];

    public const TIPOS = ['logo', 'galeria', 'documentos'];

    /**
     * Entidades con UNA sola imagen.
     *
     * Un producto o una categoría tienen una imagen y punto: no hay galería ni
     * documentos que valgan. Se declara acá y no en la interfaz para que la
     * regla se cumpla venga la petición de donde venga.
     */
    public const UNA_SOLA_IMAGEN = ['productos', 'categorias', 'banners'];

    /**
     * Entidades que SOLO admiten documentos.
     *
     * Las personas no llevan foto en esta plataforma: ni la app ni el panel
     * muestran un avatar cargado, así que subirla sería ocupar el bucket con
     * algo que nadie pinta. Lo que sí hace falta es su papelería (cédula, RUT,
     * certificados), y eso entra siempre como documento.
     */
    public const SOLO_DOCUMENTOS = ['usuarios'];

    public function admiteVarios(string $entidad): bool
    {
        return !in_array($entidad, self::UNA_SOLA_IMAGEN, true);
    }

    public function soloDocumentos(string $entidad): bool
    {
        return in_array($entidad, self::SOLO_DOCUMENTOS, true);
    }

    public function raizValida(string $entidad): string
    {
        if (!isset(self::RAICES[$entidad])) {
            throw new RuntimeException("Tipo de entidad no admitido: {$entidad}.");
        }

        return self::RAICES[$entidad];
    }

    public function admiteArchivos(string $entidad): bool
    {
        return isset(self::RAICES[$entidad]);
    }

    /**
     * El nombre del registro, que es con lo que se arma su carpeta.
     *
     * Vive acá y no en el controlador porque esta lista tiene que cubrir
     * EXACTAMENTE las mismas entidades que `RAICES`. Estaban separadas y
     * derivaron: se dio de alta `banners` en las raíces y no en la resolución
     * de nombres, así que la ficha de un banner pedía sus archivos y recibía
     * "El registro no existe" — un 404 que no hablaba del banner sino de una
     * lista incompleta.
     *
     * El `match` NO lleva `default` a propósito. Si mañana se agrega una raíz
     * sin su resolución, PHP lanza `UnhandledMatchError` en la primera
     * ejecución en vez de devolver null y disfrazarse de registro inexistente.
     * Un error ruidoso en desarrollo vale más que un 404 plausible en
     * producción.
     *
     * @return string|null null solo si la entidad es válida y el registro no está
     */
    public function nombreDe(string $entidad, int|string $id): ?string
    {
        $this->raizValida($entidad);

        return match ($entidad) {
            'negocios'   => DB::table('business')->where('busines_id', $id)->value('name'),
            'productos'  => DB::table('products')->where('products_id', $id)->value('name'),
            'usuarios'   => DB::table('user')->where('user_id', $id)->value('name'),
            'conjuntos'  => DB::table('residential_complexes')->where('complex_id', $id)->value('name'),
            'categorias' => DB::table('category_business')->where('id', $id)->value('name'),
            // El banner no tiene columna `name`: su rótulo es el título.
            'banners'    => DB::table('banners')->where('id', $id)->value('title'),
        };
    }

    public function carpeta(string $entidad, int|string $codigo, ?string $nombre): string
    {
        $slug = Str::slug((string) ($nombre ?? ''), '-') ?: 'sin-nombre';

        return $this->raizValida($entidad) . '/' . (int) $codigo . '-' . $slug;
    }

    public function configurado(): bool
    {
        $c = config('filesystems.disks.' . self::DISCO);

        return !empty($c['key']) && !empty($c['secret']) && !empty($c['endpoint']);
    }

    public function tienePublico(): bool
    {
        return (bool) config('filesystems.disks.' . self::DISCO . '.url');
    }

    /* ==================================================================
       ESCRITURA
       ================================================================== */

    public function subir(
        string $entidad,
        int|string $codigo,
        ?string $nombre,
        UploadedFile $archivo,
        string $tipo = 'galeria',
        ?int $usuarioId = null,
    ): array {
        if (!$this->configurado()) {
            throw new RuntimeException(
                'El almacenamiento R2 no está configurado. Falta R2_ACCESS_KEY_ID, ' .
                'R2_SECRET_ACCESS_KEY o R2_ENDPOINT en el archivo .env del servidor.'
            );
        }

        if (!in_array($tipo, self::TIPOS, true)) {
            throw new RuntimeException("Tipo de archivo no admitido: {$tipo}.");
        }

        // En entidades de imagen única todo entra como principal: aceptar
        // "galeria" crearía archivos que nada muestra y solo ocupan espacio.
        if (!$this->admiteVarios($entidad)) {
            $tipo = 'logo';
        }

        // Y en las de solo papelería, todo es documento: así no queda ninguna
        // imagen marcada como principal que después nadie enseña.
        if ($this->soloDocumentos($entidad)) {
            $tipo = 'documentos';
        }

        if ($archivo->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('El archivo supera los 8 MB permitidos.');
        }

        // Nombre único pero legible: conserva el original para reconocerlo en
        // el bucket, con marca de tiempo y azar para que dos subidas del mismo
        // archivo no se pisen.
        $base = Str::slug(pathinfo($archivo->getClientOriginalName(), PATHINFO_FILENAME), '-');
        $ext = strtolower($archivo->getClientOriginalExtension() ?: $archivo->guessExtension() ?: 'bin');
        $final = now()->format('Ymd-His') . '-' . Str::lower(Str::random(6)) . '-' . ($base ?: 'archivo') . '.' . $ext;

        $key = $this->carpeta($entidad, $codigo, $nombre) . '/' . $tipo . '/' . $final;

        try {
            Storage::disk(self::DISCO)->put($key, file_get_contents($archivo->getRealPath()), [
                'ContentType' => $archivo->getMimeType(),
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException($this->explicar($e));
        }

        $esPrincipal = $tipo === 'logo';

        DB::transaction(function () use ($entidad, $codigo, $tipo, $key, $archivo, $esPrincipal, $usuarioId) {
            // Solo puede haber una imagen principal por entidad.
            if ($esPrincipal) {
                DB::table('media_files')
                    ->where('entity_type', $entidad)
                    ->where('entity_id', $codigo)
                    ->update(['is_primary' => false]);
            }

            DB::table('media_files')->insert([
                'entity_type'   => $entidad,
                'entity_id'     => $codigo,
                'tipo'          => $tipo,
                'object_key'    => $key,
                'original_name' => $archivo->getClientOriginalName(),
                'mime_type'     => $archivo->getMimeType(),
                'size_bytes'    => $archivo->getSize(),
                'is_primary'    => $esPrincipal,
                'uploaded_by'   => $usuarioId,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        });

        // La imagen anterior se retira: si la entidad solo admite una, dejarla
        // en el bucket seria pagar almacenamiento por algo invisible.
        if (!$this->admiteVarios($entidad)) {
            $this->purgarAnteriores($entidad, $codigo, $key);
        }

        if ($esPrincipal) {
            $this->sincronizarPrincipal($entidad, $codigo, $key);
        }

        return $this->presentar((object) [
            'id' => DB::getPdo()->lastInsertId(),
            'object_key' => $key,
            'original_name' => $archivo->getClientOriginalName(),
            'mime_type' => $archivo->getMimeType(),
            'size_bytes' => $archivo->getSize(),
            'tipo' => $tipo,
            'is_primary' => $esPrincipal,
        ]);
    }

    /**
     * Guarda contenido generado por el propio servidor (no una subida).
     *
     * Sirve para los documentos que arma la plataforma —el acuerdo de
     * vinculación del domiciliario, por ejemplo—, que no llegan como
     * `UploadedFile` pero deben quedar en la misma carpeta y en el mismo
     * inventario que el resto de archivos de esa persona.
     */
    public function guardarContenido(
        string $entidad,
        int|string $codigo,
        ?string $nombre,
        string $contenido,
        string $nombreArchivo,
        string $mime,
        string $tipo = 'documentos',
        ?int $usuarioId = null,
    ): array {
        if (!$this->configurado()) {
            throw new RuntimeException(
                'El almacenamiento R2 no está configurado. Falta R2_ACCESS_KEY_ID, ' .
                'R2_SECRET_ACCESS_KEY o R2_ENDPOINT en el archivo .env del servidor.'
            );
        }

        if (!in_array($tipo, self::TIPOS, true)) {
            throw new RuntimeException("Tipo de archivo no admitido: {$tipo}.");
        }

        if (strlen($contenido) > self::MAX_BYTES) {
            throw new RuntimeException('El archivo generado supera los 8 MB permitidos.');
        }

        $key = $this->carpeta($entidad, $codigo, $nombre) . '/' . $tipo . '/' . $nombreArchivo;

        try {
            Storage::disk(self::DISCO)->put($key, $contenido, ['ContentType' => $mime]);
        } catch (\Throwable $e) {
            throw new RuntimeException($this->explicar($e));
        }

        $id = DB::table('media_files')->insertGetId([
            'entity_type'   => $entidad,
            'entity_id'     => $codigo,
            'tipo'          => $tipo,
            'object_key'    => $key,
            'original_name' => $nombreArchivo,
            'mime_type'     => $mime,
            'size_bytes'    => strlen($contenido),
            // Un documento nunca es la imagen principal de nadie.
            'is_primary'    => false,
            'uploaded_by'   => $usuarioId,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return $this->presentar((object) [
            'id' => $id,
            'object_key' => $key,
            'original_name' => $nombreArchivo,
            'mime_type' => $mime,
            'size_bytes' => strlen($contenido),
            'tipo' => $tipo,
            'is_primary' => false,
        ]);
    }

    /** Borra del bucket y de la tabla todo archivo distinto del actual. */
    private function purgarAnteriores(string $entidad, int|string $codigo, string $keyActual): void
    {
        $viejos = DB::table('media_files')
            ->where('entity_type', $entidad)
            ->where('entity_id', $codigo)
            ->where('object_key', '!=', $keyActual)
            ->get();

        foreach ($viejos as $v) {
            try {
                Storage::disk(self::DISCO)->delete($v->object_key);
            } catch (\Throwable) {
                // Si el objeto ya no está en el bucket da igual: lo que
                // importa es que deje de figurar en el inventario.
            }
        }

        DB::table('media_files')
            ->where('entity_type', $entidad)
            ->where('entity_id', $codigo)
            ->where('object_key', '!=', $keyActual)
            ->delete();
    }

    /**
     * Copia la URL de la imagen principal a la columna que consume la app.
     *
     * SOLO si hay dominio público: una URL firmada caduca y además no cabe en
     * la columna. Sin dominio configurado se deja el campo como está y el
     * panel avisa de que falta configurarlo.
     */
    private function sincronizarPrincipal(string $entidad, int|string $codigo, ?string $key): void
    {
        if (!$this->tienePublico()) {
            return;
        }

        $url = $key ? $this->url($key) : null;

        match ($entidad) {
            'negocios'  => DB::table('business')->where('busines_id', $codigo)->update(['logo' => $url]),
            'productos' => DB::table('products')->where('products_id', $codigo)->update(['image' => $url]),
            'categorias' => DB::table('category_business')->where('id', $codigo)->update(['image' => $url]),
            default     => null,
        };
    }

    public function eliminar(string $entidad, int|string $codigo, int $mediaId): void
    {
        $fila = DB::table('media_files')
            ->where('id', $mediaId)
            ->where('entity_type', $entidad)
            ->where('entity_id', $codigo)
            ->first();

        // Se comprueba la pertenencia contra la base: sin esto, un id
        // manipulado podría borrar archivos de otra entidad.
        if (!$fila) {
            throw new RuntimeException('El archivo no pertenece a este registro.');
        }

        try {
            Storage::disk(self::DISCO)->delete($fila->object_key);
        } catch (\Throwable $e) {
            throw new RuntimeException($this->explicar($e));
        }

        DB::table('media_files')->where('id', $mediaId)->delete();

        // Si era la principal, la columna de la app queda apuntando a nada.
        if ($fila->is_primary) {
            $siguiente = DB::table('media_files')
                ->where('entity_type', $entidad)
                ->where('entity_id', $codigo)
                ->where('tipo', 'logo')
                ->orderByDesc('id')
                ->first();

            if ($siguiente) {
                DB::table('media_files')->where('id', $siguiente->id)->update(['is_primary' => true]);
            }

            $this->sincronizarPrincipal($entidad, $codigo, $siguiente->object_key ?? null);
        }
    }

    /** Marca otra imagen como principal. */
    public function marcarPrincipal(string $entidad, int|string $codigo, int $mediaId): void
    {
        $fila = DB::table('media_files')
            ->where('id', $mediaId)
            ->where('entity_type', $entidad)
            ->where('entity_id', $codigo)
            ->first();

        if (!$fila) {
            throw new RuntimeException('El archivo no pertenece a este registro.');
        }

        DB::transaction(function () use ($entidad, $codigo, $mediaId) {
            DB::table('media_files')
                ->where('entity_type', $entidad)->where('entity_id', $codigo)
                ->update(['is_primary' => false]);
            DB::table('media_files')->where('id', $mediaId)->update(['is_primary' => true]);
        });

        $this->sincronizarPrincipal($entidad, $codigo, $fila->object_key);
    }

    /* ==================================================================
       LECTURA
       ================================================================== */

    public function listar(string $entidad, int|string $codigo): array
    {
        $this->raizValida($entidad);

        return DB::table('media_files')
            ->where('entity_type', $entidad)
            ->where('entity_id', $codigo)
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->get()
            ->map(fn($f) => $this->presentar($f))
            ->all();
    }

    private function presentar(object $f): array
    {
        return [
            'id'         => (int) $f->id,
            'key'        => $f->object_key,
            'url'        => $this->url($f->object_key),
            'name'       => $f->original_name ?: basename($f->object_key),
            'size'       => (int) ($f->size_bytes ?? 0),
            'mime'       => $f->mime_type,
            'tipo'       => $f->tipo,
            'is_primary' => (bool) $f->is_primary,
        ];
    }

    public function url(string $key): string
    {
        $publico = config('filesystems.disks.' . self::DISCO . '.url');

        if ($publico) {
            return rtrim($publico, '/') . '/' . ltrim($key, '/');
        }

        // Sin dominio público solo queda la URL firmada. Sirve para verla en
        // el panel, pero caduca: por eso jamás se guarda en la base.
        try {
            return Storage::disk(self::DISCO)->temporaryUrl($key, now()->addDays(7));
        } catch (\Throwable) {
            return '';
        }
    }

    /** Traduce los fallos frecuentes de R2 a algo accionable. */
    private function explicar(\Throwable $e): string
    {
        $m = $e->getMessage();

        if (str_contains($m, '403') || stripos($m, 'AccessDenied') !== false) {
            return 'Cloudflare rechazó la operación (403). El token de R2 es de solo lectura: '
                . 'crea uno con permiso "Object Read & Write" sobre el bucket.';
        }

        if (str_contains($m, '404') || stripos($m, 'NoSuchBucket') !== false) {
            return 'El bucket configurado en R2_BUCKET no existe o el endpoint no corresponde a esa cuenta.';
        }

        return 'No se pudo operar sobre el almacenamiento: ' . Str::limit($m, 180);
    }
}
