<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order\OrdersSales;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ==========================================================================
 * LA FOTO DE QUE SE ENTREGÓ.
 *
 * Hoy una entrega es la palabra del domiciliario y nada más: toca «Sí, lo
 * entregué», el pedido pasa a estado 4 y ahí termina. Cuando el cliente
 * llama diciendo que no le llegó, no hay nada que mirar —ni para darle la
 * razón a él ni para dársela al domiciliario—, y la discusión la pierde
 * siempre el mismo: quien no tiene cómo probarlo.
 *
 * --------------------------------------------------------------------------
 * NO HAY MIGRACIÓN, Y NO ES UN ATAJO.
 *
 * `media_files` ya es polimórfica --`entity_type`, `entity_id`, `tipo`-- y
 * es donde viven los logos, la galería y los papeles de los domiciliarios.
 * Una tabla propia para esto sería una cuarta forma de guardar un archivo
 * en el mismo bucket, con su propia idea de qué es la clave y quién puede
 * borrarla. Acá `entity_type = 'order'` y `tipo = 'entrega'`.
 *
 * --------------------------------------------------------------------------
 * LA FOTO NO ES LA ENTREGA.
 *
 * Se guarda ANTES de marcar el pedido como entregado y en una llamada
 * aparte, a propósito. Si fueran lo mismo:
 *
 *   · una foto que no sube --y en la calle no sube la mitad-- impediría
 *     cerrar una entrega que SÍ ocurrió, y el domiciliario se quedaría
 *     plantado en la puerta con el cliente mirándolo;
 *   · y reintentar la subida reintentaría la entrega.
 *
 * Así que la evidencia es opcional y puede llegar tarde. Lo que no puede
 * es llegar de otro: sólo el domiciliario que lleva ESE pedido la sube.
 */
class EvidenciaDeEntrega
{
    /** El mismo disco que el resto de los medios. */
    private const DISCO = 'r2';

    private const RAIZ = 'entregas';

    /**
     * 4 MB. La mitad que los medios del negocio, y a propósito: esto se sube
     * desde la calle, con la red del teléfono y con el cliente esperando.
     * Una foto de 8 MB en 3G son dos minutos de nadie moviéndose.
     */
    private const MAX_BYTES = 4 * 1024 * 1024;

    public function configurado(): bool
    {
        return (bool) config('filesystems.disks.'.self::DISCO.'.key');
    }

    /**
     * Guarda la foto de una entrega y la deja apuntada en `media_files`.
     *
     * @return array{id:int, url:string, key:string}
     */
    public function guardar(OrdersSales $pedido, UploadedFile $archivo, int $subidaPor): array
    {
        if (! $this->configurado()) {
            throw new RuntimeException('El almacenamiento no está configurado en este servidor.');
        }

        if ($archivo->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('La foto supera los 4 MB. Vuelve a tomarla con menos calidad.');
        }

        $ext = strtolower($archivo->getClientOriginalExtension() ?: $archivo->guessExtension() ?: 'jpg');

        /*
         * El nombre lleva el pedido delante. Mirando el bucket a secas se
         * sabe de qué entrega es cada foto sin cruzar nada con la base, que
         * es justo lo que hace falta el día que alguien reclama.
         */
        $nombre = 'pedido-'.$pedido->orderSales_id
            .'-'.now()->format('Ymd-His')
            .'-'.Str::lower(Str::random(6))
            .'.'.$ext;

        $ruta = self::RAIZ.'/'.now()->format('Y-m').'/'.$nombre;

        Storage::disk(self::DISCO)->put($ruta, file_get_contents($archivo->getRealPath()), [
            'ContentType' => $archivo->getMimeType(),
        ]);

        $id = DB::table('media_files')->insertGetId([
            'entity_type' => 'order',
            'entity_id' => $pedido->orderSales_id,
            'tipo' => 'entrega',
            'object_key' => $ruta,
            'original_name' => $archivo->getClientOriginalName(),
            'mime_type' => $archivo->getMimeType(),
            'size_bytes' => $archivo->getSize(),
            'is_primary' => 1,
            'uploaded_by' => $subidaPor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => (int) $id, 'url' => $this->url($ruta), 'key' => $ruta];
    }

    /**
     * La foto de una entrega, si la hay.
     *
     * El enlace es TEMPORAL --siete días-- y no público: es una foto de la
     * puerta de la casa de alguien, con lo que eso enseña. Un enlace
     * permanente que se filtra una vez queda filtrado para siempre.
     *
     * @return array{url:string, cuando:string}|null
     */
    public function de(int $orderId): ?array
    {
        $fila = DB::table('media_files')
            ->where('entity_type', 'order')
            ->where('entity_id', $orderId)
            ->where('tipo', 'entrega')
            ->orderByDesc('id')
            ->first(['object_key', 'created_at']);

        if (! $fila || ! $this->configurado()) {
            return null;
        }

        return ['url' => $this->url($fila->object_key), 'cuando' => (string) $fila->created_at];
    }

    private function url(string $key): string
    {
        return Storage::disk(self::DISCO)->temporaryUrl($key, now()->addDays(7));
    }
}
