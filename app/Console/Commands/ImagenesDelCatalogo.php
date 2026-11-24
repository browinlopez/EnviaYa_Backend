<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MediaService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * ==========================================================================
 * LAS FOTOS DEL CATÁLOGO, TRAÍDAS A NUESTRO ALMACÉN.
 *
 * Medido antes de escribir esto: de 5.672 productos, 353 tienen imagen, y
 * TODAS apuntan a `encrypted-tbn*.gstatic.com` —miniaturas de Google
 * Shopping—. O sea que el catálogo de la plataforma depende hoy de un CDN
 * ajeno que:
 *
 *   · no promete que esas direcciones sigan existiendo mañana;
 *   · las sirve con la calidad de una miniatura, no de una foto;
 *   · puede cortar el acceso desde nuestro dominio cuando quiera.
 *
 * El día que caduquen, el catálogo entero se queda sin fotos y nadie sabrá
 * por qué: no hay error, simplemente dejan de cargar.
 *
 * Esto se las trae: descarga cada una y la sube a R2, que es donde viven el
 * resto de los medios. La columna `products.image` queda apuntando a nuestro
 * dominio.
 *
 *     php artisan catalogo:imagenes                    ensaya, no escribe
 *     php artisan catalogo:imagenes --aplicar          trae y sube
 *     php artisan catalogo:imagenes --carpeta=/fotos   desde el disco
 *
 * --------------------------------------------------------------------------
 * SE PUEDE CORRER MIL VECES.
 *
 * Lo que ya está en R2 se salta: se reconoce porque la URL empieza por el
 * dominio público configurado. Así una corrida interrumpida se retoma donde
 * iba en vez de volver a subirlo todo.
 *
 * --------------------------------------------------------------------------
 * LO QUE ESTO NO HACE, Y HAY QUE SABERLO.
 *
 * NO consigue fotos que no existan. Un producto sin imagen sigue sin imagen:
 * esto mueve de sitio lo que ya hay, no lo inventa. Para los productos nuevos
 * del catálogo hace falta que alguien ponga las fotos —el tendero con el
 * teléfono, o una carpeta— y entonces `--carpeta` las sube.
 *
 * Y la foto de producto que se descarga es de quien la tomó. Re-alojarla es
 * lo normal en comercio —son las fotos de lo que se vende— pero conviene
 * saber que se está copiando material de un tercero a nuestro bucket.
 */
class ImagenesDelCatalogo extends Command
{
    protected $signature = 'catalogo:imagenes
                            {--aplicar : Descargar y subir de verdad}
                            {--carpeta= : Subir desde una carpeta del disco en vez de internet}
                            {--limite=0 : Cuántos productos tocar como mucho}
                            {--negocio= : Sólo los productos que vende este negocio}';

    protected $description = 'Trae las imágenes del catálogo a R2 y reescribe products.image';

    /** Ocho segundos: una foto que no llega en ocho no va a llegar. */
    private const ESPERA = 8;

    /*
     * Y tres para CONECTAR, que no es lo mismo.
     *
     * `timeout` mide la peticion entera; si el servidor no contesta al
     * saludo --DNS que no resuelve, puerto que no abre-- se puede quedar
     * colgado ahi dentro del plazo general. Con trescientas fotos, un
     * dominio caido convierte una corrida de dos minutos en una de media
     * hora.
     */
    private const CONECTAR = 3;

    /** Lo que se acepta. Un SVG puede traer JavaScript dentro. */
    private const TIPOS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function handle(MediaService $medios): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $carpeta = $this->option('carpeta');

        if (! $medios->configurado()) {
            $this->error('R2 no está configurado: faltan R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY o R2_ENDPOINT.');

            return self::FAILURE;
        }

        if (! $medios->tienePublico()) {
            $this->error('Falta R2_PUBLIC_URL. Sin dominio público la imagen se sube pero la columna no se puede actualizar.');

            return self::FAILURE;
        }

        $publico = rtrim((string) config('filesystems.disks.r2.url'), '/');

        $productos = $this->productos($publico, $carpeta !== null);

        if ($productos->isEmpty()) {
            $this->info('No hay nada que traer: o ya están todas en R2, o no hay imágenes de dónde sacarlas.');

            return self::SUCCESS;
        }

        $this->line($aplicar ? 'APLICANDO' : 'ENSAYO (no escribe nada)');
        $this->line("  {$productos->count()} productos por mirar");
        $this->newLine();

        $hechos = 0;
        $fallos = 0;

        foreach ($productos as $p) {
            $origen = $carpeta !== null
                ? $this->delDisco((string) $carpeta, $p)
                : [$p->image, null];

            [$de, $bytes] = $origen;

            if ($de === null) {
                continue;
            }

            if (! $aplicar) {
                $this->line(sprintf('  · %-46s %s', mb_substr($p->name, 0, 46), mb_substr((string) $de, 0, 48)));
                $hechos++;

                continue;
            }

            try {
                $bytes ??= $this->bajar((string) $de);

                $mime = $this->mimeDe($bytes);

                if ($mime === null) {
                    throw new \RuntimeException('no es una imagen que aceptemos');
                }

                /*
                 * `subir` Y NO `guardarContenido`, QUE ERA EL ERROR.
                 *
                 * `guardarContenido` es para DOCUMENTOS: lo dice su propio
                 * código --«un documento nunca es la imagen principal de
                 * nadie»--, así que marca `is_primary = false` y no toca la
                 * columna. Con él las fotos subían a R2 y `products.image`
                 * seguía apuntando a Google: lo peor de los dos mundos, porque
                 * el bucket se llenaba y nada cambiaba.
                 *
                 * `subir` es el camino de las imágenes: marca la principal y
                 * llama a `sincronizarPrincipal`, que es quien escribe la URL
                 * pública en `products.image`.
                 */
                $this->comoSubida($bytes, $mime, function (UploadedFile $archivo) use ($medios, $p) {
                    $medios->subir('productos', (int) $p->products_id, $p->name, $archivo, 'logo');
                });

                $hechos++;
                $this->line(sprintf('  ✓ %s', mb_substr($p->name, 0, 60)));
            } catch (\Throwable $e) {
                $fallos++;
                $this->line(sprintf('  ✗ %-44s %s', mb_substr($p->name, 0, 44), $e->getMessage()));
            }
        }

        $this->newLine();
        $this->info($aplicar ? "{$hechos} subidas, {$fallos} fallaron." : "{$hechos} se traerían.");

        if (! $aplicar) {
            $this->line('  Para hacerlo: php artisan catalogo:imagenes --aplicar');
        }

        return self::SUCCESS;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Los que hay que tocar.
     *
     * Desde internet: los que tienen una imagen que NO es nuestra. Desde una
     * carpeta: todos, porque la foto puede ser para uno que no tenía ninguna.
     */
    private function productos(string $publico, bool $desdeCarpeta)
    {
        $consulta = DB::table('products')
            ->where('state', 1)
            ->when(! $desdeCarpeta, fn ($q) => $q
                ->whereNotNull('image')
                ->where('image', '<>', '')
                ->where('image', 'not like', $publico.'%'),
            )
            ->when($this->option('negocio'), fn ($q) => $q->whereIn(
                'products_id',
                DB::table('products_business')
                    ->where('busines_id', (int) $this->option('negocio'))
                    ->select('products_id'),
            ))
            ->orderBy('products_id');

        $limite = (int) $this->option('limite');

        return $limite > 0
            ? $consulta->limit($limite)->get(['products_id', 'name', 'image', 'barcode'])
            : $consulta->get(['products_id', 'name', 'image', 'barcode']);
    }

    /**
     * La foto en el disco, buscada por código de barras y luego por nombre.
     *
     * Por código primero porque es exacto. Por nombre después, normalizado
     * igual que lo hace el buscador --sin tildes, sin signos, en minúsculas--
     * para que «Acetaminofén 500 mg.jpg» encuentre su producto.
     *
     * @return array{0: ?string, 1: ?string} [de dónde, los bytes]
     */
    private function delDisco(string $carpeta, object $p): array
    {
        foreach (array_keys(self::TIPOS) as $mime) {
            $ext = self::TIPOS[$mime];

            $candidatos = array_filter([
                $p->barcode ? "{$carpeta}/{$p->barcode}.{$ext}" : null,
                "{$carpeta}/{$p->products_id}.{$ext}",
                "{$carpeta}/".$this->comoArchivo($p->name).".{$ext}",
            ]);

            foreach ($candidatos as $ruta) {
                if (is_file($ruta)) {
                    return [$ruta, file_get_contents($ruta) ?: null];
                }
            }
        }

        return [null, null];
    }

    /** El nombre del producto como nombre de archivo. */
    private function comoArchivo(string $nombre): string
    {
        $sin = strtr(mb_strtolower($nombre), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        ]);

        return trim(preg_replace('/[^a-z0-9]+/', '-', $sin) ?? '', '-');
    }

    /**
     * Baja la imagen.
     *
     * Con tiempo máximo y sin seguir redirecciones infinitas: un CDN que
     * responde despacio no puede colgar una corrida de trescientas fotos.
     */
    private function bajar(string $url): string
    {
        $r = Http::connectTimeout(self::CONECTAR)
            ->timeout(self::ESPERA)
            ->withHeaders(['User-Agent' => 'VeciPaYa/1.0'])
            ->get($url);

        if (! $r->successful()) {
            throw new \RuntimeException('respondió '.$r->status());
        }

        $bytes = $r->body();

        if ($bytes === '') {
            throw new \RuntimeException('llegó vacía');
        }

        return $bytes;
    }

    /**
     * Pasa unos bytes por donde `MediaService` espera un archivo subido.
     *
     * Pide un `UploadedFile` porque su camino normal es un formulario. Una
     * foto bajada de internet es exactamente eso --un archivo que llegó de
     * fuera-- así que se escribe en un temporal y se envuelve, en vez de
     * abrirle al servicio una segunda puerta que haga lo mismo con otro
     * nombre.
     *
     * El temporal se borra pase lo que pase: una corrida de trescientas fotos
     * que deja trescientos archivos tirados llena el disco del servidor.
     */
    private function comoSubida(string $bytes, string $mime, callable $hacer): void
    {
        $temporal = tempnam(sys_get_temp_dir(), 'foto');

        if ($temporal === false) {
            throw new \RuntimeException('no se pudo crear el archivo temporal');
        }

        try {
            file_put_contents($temporal, $bytes);

            $hacer(new UploadedFile(
                $temporal,
                'foto.'.self::TIPOS[$mime],
                $mime,
                null,
                true, // ya está en disco: no viene de una petición real
            ));
        } finally {
            @unlink($temporal);
        }
    }

    /**
     * Qué es de verdad, mirando los bytes y no la extensión.
     *
     * Un archivo que se llama `.jpg` puede ser cualquier cosa, y acá se está
     * subiendo a un bucket público con lo que diga el servidor de origen.
     */
    private function mimeDe(string $bytes): ?string
    {
        $info = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $info->buffer($bytes) ?: '';

        return isset(self::TIPOS[$mime]) ? $mime : null;
    }
}
