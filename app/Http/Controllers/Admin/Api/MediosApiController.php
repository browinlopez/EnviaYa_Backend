<?php

namespace App\Http\Controllers\Admin\Api;

use App\Services\Ajustes;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Conjunto\ComplexStaff;
use App\Models\Operacion\DomiciliaryDocument;
use App\Models\Reviews\BusinessReview;
use App\Models\Reviews\DomiciliaryReview;
use App\Models\User;
use App\Services\BusinessMediaService;
use App\Services\ContratoService;
use App\Services\MediaService;
use App\Services\VinculosDelRol;
use App\Support\ListadoPaginado;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MediosApiController extends Controller
{

    /**
     * Nombre del registro para construir su carpeta, o corta con el 404 que
     * corresponda.
     *
     * Son dos fallos distintos y antes se contaban como uno: "esta entidad no
     * maneja archivos" es un error de quien llama, y "ese registro no existe"
     * es un dato que se borró. Decirlos igual mandaba a buscar el registro
     * equivocado.
     */
    private function nombreDeEntidad(string $entidad, $id, MediaService $medios): string
    {
        abort_unless(
            $medios->admiteArchivos($entidad),
            404,
            "Los registros de tipo \"{$entidad}\" no manejan archivos.",
        );

        $nombre = $medios->nombreDe($entidad, $id);
        abort_if($nombre === null, 404, 'El registro no existe.');

        return $nombre;
    }

    /**
     * Estado del almacenamiento, para la pantalla de Ajustes.
     *
     * Media plataforma depende de R2 (logos, galerías, documentos) y hasta
     * ahora la única forma de saber si estaba bien configurado era abrir la
     * ficha de un registro y tratar de subir algo.
     */
    public function storageStatus(MediaService $medios)
    {
        $porTipo = DB::table('media_files')
            ->groupBy('entity_type')
            ->orderBy('entity_type')
            ->get([
                'entity_type',
                DB::raw('COUNT(*) as files'),
                DB::raw('COALESCE(SUM(size_bytes), 0) as bytes'),
            ]);

        $disco = config('filesystems.disks.r2');

        return response()->json([
            'configured' => $medios->configurado(),
            'public'     => $medios->tienePublico(),
            'bucket'     => $disco['bucket'] ?? null,
            // El endpoint lleva la cuenta de Cloudflare en el subdominio; se
            // manda solo el host para no exponer más de lo necesario.
            'endpoint'   => $disco['endpoint'] ? parse_url($disco['endpoint'], PHP_URL_HOST) : null,
            'public_url' => $disco['url'] ?? null,
            'files'      => (int) $porTipo->sum('files'),
            'bytes'      => (int) $porTipo->sum('bytes'),
            'by_entity'  => $porTipo,
        ]);
    }

    public function media(Request $request, string $entidad, $id, MediaService $medios)
    {
        $nombre = $this->nombreDeEntidad($entidad, $id, $medios);

        return response()->json([
            'folder' => $medios->carpeta($entidad, $id, $nombre),
            'configured' => $medios->configurado(),
            'public' => $medios->tienePublico(),
            // El panel oculta las pestañas cuando la entidad admite una sola
            // imagen, en vez de ofrecer opciones que el servidor ignora.
            'multiple' => $medios->admiteVarios($entidad),
            'documents_only' => $medios->soloDocumentos($entidad),
            'files' => $medios->listar($entidad, $id),
        ]);
    }

    /**
     * Sube un archivo a la carpeta de la entidad.
     *
     * Pasa por el backend y nunca desde el navegador: las llaves de R2 dan
     * permiso sobre todo el bucket y no pueden salir del servidor.
     */
    public function uploadMedia(Request $request, string $entidad, $id, MediaService $medios)
    {
        $nombre = $this->nombreDeEntidad($entidad, $id, $medios);

        $request->validate([
            'file' => 'required|file|max:8192|mimes:jpg,jpeg,png,webp,gif,pdf',
            'tipo' => 'sometimes|string|in:logo,galeria,documentos',
        ]);

        $tipo = $request->input('tipo', 'galeria');

        try {
            // El servicio registra el archivo en `media_files` y, si el logo
            // cambia, sincroniza la columna que consume la app. Acá ya no se
            // toca la base: guardar la URL firmada en `business.logo` es lo
            // que rompía la subida (600 caracteres en un varchar(255)).
            $subido = $medios->subir(
                $entidad,
                $id,
                $nombre,
                $request->file('file'),
                $tipo,
                $request->user()->user_id ?? null,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Archivo cargado.',
            'file' => $subido,
            'folder' => $medios->carpeta($entidad, $id, $nombre),
            'public' => $medios->tienePublico(),
        ], 201);
    }

    public function deleteMedia(Request $request, string $entidad, $id, MediaService $medios)
    {
        $nombre = $this->nombreDeEntidad($entidad, $id, $medios);

        $datos = $request->validate(['id' => 'required|integer']);

        try {
            // El servicio comprueba la pertenencia contra la base y, si era la
            // imagen principal, promueve otra y sincroniza la columna.
            $medios->eliminar($entidad, $id, (int) $datos['id']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Archivo eliminado.']);
    }

    /** Marca cuál de las imágenes es la principal de la entidad. */
    public function setPrimaryMedia(Request $request, string $entidad, $id, MediaService $medios)
    {
        $this->nombreDeEntidad($entidad, $id, $medios);

        $datos = $request->validate(['id' => 'required|integer']);

        try {
            $medios->marcarPrincipal($entidad, $id, (int) $datos['id']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Imagen principal actualizada.']);
    }

    /* ==================================================================
       MEDIOS DEL NEGOCIO (Cloudflare R2)
       ================================================================== */
}
