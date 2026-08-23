<?php

namespace App\Jobs;

use App\Models\Product\CatalogUpload;
use App\Services\ImportadorDeCatalogo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * La carga corre fuera de la petición.
 *
 * Un Excel de cuatrocientas filas hace cuatrocientas consultas al catálogo y
 * cuatrocientas escrituras. Eso no cabe en el tiempo de una petición HTTP: el
 * tendero se queda mirando una barra hasta que el navegador se rinde, y como el
 * proceso sí siguió corriendo, no sabe si se cargó o no y vuelve a subirlo.
 *
 * Con el archivo guardado y el expediente en `catalog_uploads`, la respuesta es
 * inmediata y el resultado se consulta después.
 *
 * NO SE REINTENTA. La operación es idempotente por `(negocio, producto)`, así
 * que repetirla no duplica nada; pero si falló a la mitad, volver a correrla
 * sola tapa el motivo. Que quede `fallida` y se vea es mejor que un reintento
 * silencioso que a lo mejor tampoco funciona.
 */
class ProcesarCargaDeCatalogo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(public int $cargaId)
    {
    }

    public function handle(ImportadorDeCatalogo $importador): void
    {
        $carga = CatalogUpload::find($this->cargaId);

        if (!$carga) {
            return;
        }

        $importador->procesar($carga);
    }

    public function failed(\Throwable $e): void
    {
        CatalogUpload::where('catalog_upload_id', $this->cargaId)->update([
            'estado'  => 'fallida',
            'resumen' => json_encode(['error' => $e->getMessage()]),
        ]);
    }
}
