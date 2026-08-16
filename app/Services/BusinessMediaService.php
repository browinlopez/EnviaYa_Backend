<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Medios de un negocio en Cloudflare R2.
 *
 * Cada negocio tiene su propia carpeta, nombrada "<código> - <nombre>", para
 * que un objeto suelto en el bucket se pueda atribuir de un vistazo sin
 * consultar la base de datos.
 *
 * El nombre se normaliza (sin tildes ni espacios) porque forma parte de una
 * URL: "Tienda el progreso" produciría rutas con %20 que se rompen al
 * copiarlas a mano. El código va delante y es inmutable, así que renombrar el
 * negocio no deja huérfanos los archivos ya subidos: la carpeta cambia de
 * etiqueta pero se sigue localizando por el prefijo numérico.
 */
class BusinessMediaService
{
    /** Disco configurado en config/filesystems.php. */
    private const DISCO = 'r2';

    /** Raíz común, para no mezclar los medios de negocios con otros usos. */
    private const RAIZ = 'negocios';

    private const MAX_BYTES = 8 * 1024 * 1024; // 8 MB

    /**
     * Carpeta del negocio: "negocios/1-tienda-el-progreso".
     *
     * @param object $negocio  Fila con busines_id y name.
     */
    public function carpeta(object $negocio): string
    {
        $codigo = (int) $negocio->busines_id;
        $nombre = Str::slug((string) ($negocio->name ?? ''), '-') ?: 'sin-nombre';

        return self::RAIZ . "/{$codigo}-{$nombre}";
    }

    /** Prefijo que identifica al negocio aunque se haya renombrado. */
    public function prefijoDeCodigo(object $negocio): string
    {
        return self::RAIZ . '/' . (int) $negocio->busines_id . '-';
    }

    public function configurado(): bool
    {
        $c = config('filesystems.disks.' . self::DISCO);

        return !empty($c['key']) && !empty($c['secret']) && !empty($c['endpoint']);
    }

    /**
     * Guarda un archivo y devuelve su clave y su URL pública.
     *
     * @param string $tipo  Subcarpeta: logo, galeria, documentos…
     */
    public function subir(object $negocio, UploadedFile $archivo, string $tipo = 'galeria'): array
    {
        if (!$this->configurado()) {
            throw new RuntimeException(
                'El almacenamiento R2 no está configurado. Falta R2_ACCESS_KEY_ID, ' .
                'R2_SECRET_ACCESS_KEY o R2_ENDPOINT en el archivo .env del servidor.'
            );
        }

        if ($archivo->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('El archivo supera los 8 MB permitidos.');
        }

        // Nombre único pero legible: conserva el original para que se pueda
        // reconocer en el bucket, y le antepone marca de tiempo y azar para
        // que dos subidas del mismo archivo no se pisen.
        $base = Str::slug(pathinfo($archivo->getClientOriginalName(), PATHINFO_FILENAME), '-');
        $ext  = strtolower($archivo->getClientOriginalExtension() ?: $archivo->guessExtension() ?: 'bin');
        $nombre = now()->format('Ymd-His') . '-' . Str::lower(Str::random(6)) . '-' . ($base ?: 'archivo') . '.' . $ext;

        $ruta = $this->carpeta($negocio) . '/' . Str::slug($tipo, '-') . '/' . $nombre;

        Storage::disk(self::DISCO)->put($ruta, file_get_contents($archivo->getRealPath()), [
            'ContentType' => $archivo->getMimeType(),
        ]);

        return [
            'key'  => $ruta,
            'url'  => $this->url($ruta),
            'name' => $archivo->getClientOriginalName(),
            'size' => $archivo->getSize(),
            'type' => $archivo->getMimeType(),
        ];
    }

    /** Archivos del negocio, incluidos los subidos antes de un renombrado. */
    public function listar(object $negocio): array
    {
        if (!$this->configurado()) {
            return [];
        }

        $disco = Storage::disk(self::DISCO);

        // Se listan por el prefijo del código y no por la carpeta exacta: si
        // el negocio cambió de nombre, sus archivos viejos siguen en la ruta
        // anterior y deben seguir apareciendo.
        $carpetas = collect($disco->directories(self::RAIZ))
            ->filter(fn($d) => str_starts_with($d . '/', $this->prefijoDeCodigo($negocio)));

        return $carpetas
            ->flatMap(fn($c) => $disco->allFiles($c))
            ->map(fn($ruta) => [
                'key'  => $ruta,
                'url'  => $this->url($ruta),
                'name' => basename($ruta),
                'size' => $disco->size($ruta),
                'tipo' => basename(dirname($ruta)),
            ])
            ->sortByDesc('name')
            ->values()
            ->all();
    }

    public function eliminar(object $negocio, string $key): bool
    {
        // Solo se borra dentro de la carpeta del negocio: sin esta guarda, una
        // clave manipulada podría borrar objetos de otro negocio.
        if (!str_starts_with($key, $this->prefijoDeCodigo($negocio))) {
            throw new RuntimeException('El archivo no pertenece a este negocio.');
        }

        return Storage::disk(self::DISCO)->delete($key);
    }

    /**
     * URL de lectura.
     *
     * El endpoint de la API de R2 (r2.cloudflarestorage.com) NO sirve para
     * leer: exige firma. Si no hay dominio público configurado se devuelve una
     * URL temporal firmada, que funciona pero caduca — por eso el panel avisa
     * de que falta configurar R2_PUBLIC_URL.
     */
    public function url(string $key): string
    {
        $publico = config('filesystems.disks.' . self::DISCO . '.url');

        if ($publico) {
            return rtrim($publico, '/') . '/' . ltrim($key, '/');
        }

        return Storage::disk(self::DISCO)->temporaryUrl($key, now()->addDays(7));
    }

    public function tienePublico(): bool
    {
        return (bool) config('filesystems.disks.' . self::DISCO . '.url');
    }
}
