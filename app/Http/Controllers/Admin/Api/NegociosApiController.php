<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NegocioRequest;
use App\Models\Order\OrdersSales;
use App\Queries\NegociosDelPanel;
use App\Services\BusinessMediaService;
use App\Services\MediaService;
use App\Support\ImagenPrincipalEnLaFila;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NegociosApiController extends Controller
{
    use ImagenPrincipalEnLaFila;

    /**
     * El listado del panel.
     *
     * LA CONSULTA VIVE EN `App\Queries\NegociosDelPanel`, que es lo que §12
     * pide --«consultas complejas en `Queries/`»-- y lo que §21 concreta para
     * un listado con varias relaciones y agregados. Aqui quedan las tres
     * decisiones que SI son del controlador: que estado cuenta como venta,
     * pasar las filas por el presentador de imagenes, y responder.
     *
     * `OrdersSales::ENTREGADO` se pasa en vez de leerse dentro de la Query
     * para no crear una copia mas del numero. Cuando esto se escribio, el
     * mismo 4 vivia como constante PRIVADA en seis clases y el comentario
     * decia que unificarlas era otro trabajo: ese trabajo ya se hizo --los
     * cinco estados son publicos en el modelo-- y por eso aqui se pasa la
     * constante y no un literal.
     */
    public function businesses(MediaService $medios)
    {
        $filas = (new NegociosDelPanel(OrdersSales::ENTREGADO))->listado();

        return response()->json(
            $this->conImagenPrincipal($filas, 'negocios', 'busines_id', 'logo', $medios)
        );
    }

    public function showBusiness($id)
    {
        $negocio = (new NegociosDelPanel(OrdersSales::ENTREGADO))->ficha($id);
        abort_if(! $negocio, 404, 'El negocio no existe.');

        return response()->json($negocio);
    }

    /** El catalogo para los selectores encadenados del formulario. */
    public function locations()
    {
        return response()->json(
            (new NegociosDelPanel(OrdersSales::ENTREGADO))->departamentosConMunicipios()
        );
    }

    /** Campos editables de un negocio, compartidos por crear y actualizar. */
    public function storeBusiness(NegocioRequest $request)
    {
        $datos = $request->validated();

        $datos['state'] = $datos['state'] ?? 1;
        $datos['qualification'] = 0;
        // La fecha la pone la aplicación y no la base: `CURRENT_TIMESTAMP` es
        // el reloj del servidor, que en el VPS no es el de Bogotá.
        $datos['created_at'] = now();
        $datos['updated_at'] = now();

        $id = DB::table('business')->insertGetId($datos);

        return response()->json([
            'message' => 'Negocio creado.',
            'busines_id' => $id,
        ], 201);
    }

    public function updateBusiness(Request $request, $id)
    {
        abort_if(! DB::table('business')->where('busines_id', $id)->exists(), 404, 'El negocio no existe.');

        /*
         * EN LINEA Y NO EN UN FormRequest: el `abort_if` de arriba contesta
         * 404 cuando el negocio no existe, y eso va ANTES que un error de
         * validacion. Las reglas no se repiten: se piden a `NegocioRequest`.
         */
        $datos = $request->validate(NegocioRequest::reglas(creando: false));

        if ($datos) {
            DB::table('business')->where('busines_id', $id)->update($datos);
        }

        return $this->showBusiness($id);
    }

    /**
     * Sube una imagen o documento a la carpeta del negocio.
     *
     * El archivo viaja por el backend y nunca desde el navegador: las llaves
     * de R2 dan permiso de escritura sobre todo el bucket y no pueden salir
     * del servidor.
     */
    public function uploadBusinessMedia(Request $request, $id, BusinessMediaService $medios)
    {
        $negocio = DB::table('business')->where('busines_id', $id)->first(['busines_id', 'name']);
        abort_if(! $negocio, 404, 'El negocio no existe.');

        $request->validate([
            'file' => 'required|file|max:8192|mimes:jpg,jpeg,png,webp,gif,pdf',
            'tipo' => 'sometimes|string|in:'.implode(',', MediaService::TIPOS),
        ]);

        $tipo = $request->input('tipo', 'galeria');

        try {
            $subido = $medios->subir($negocio, $request->file('file'), $tipo);
        } catch (RuntimeException $e) {
            // Solo lo que el servicio lanza a proposito, con mensaje
            // escrito para quien lo lee. Una averia del almacenamiento
            // no es un 422: sube, sale 500 y queda en Sentry.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // El logo además queda apuntado en el registro: es el que consume la
        // app móvil.
        if ($tipo === 'logo') {
            DB::table('business')->where('busines_id', $id)->update(['logo' => $subido['url']]);
        }

        return response()->json([
            'message' => 'Archivo cargado.',
            'file' => $subido,
            'folder' => $medios->carpeta($negocio),
            'public' => $medios->tienePublico(),
        ], 201);
    }

    public function businessMedia($id, BusinessMediaService $medios)
    {
        $negocio = DB::table('business')->where('busines_id', $id)->first(['busines_id', 'name']);
        abort_if(! $negocio, 404, 'El negocio no existe.');

        return response()->json([
            'folder' => $medios->carpeta($negocio),
            'configured' => $medios->configurado(),
            'public' => $medios->tienePublico(),
            'files' => $medios->listar($negocio),
        ]);
    }

    public function deleteBusinessMedia(Request $request, $id, BusinessMediaService $medios)
    {
        $negocio = DB::table('business')->where('busines_id', $id)->first(['busines_id', 'name', 'logo']);
        abort_if(! $negocio, 404, 'El negocio no existe.');

        $datos = $request->validate(['key' => 'required|string']);

        try {
            $medios->eliminar($negocio, $datos['key']);
        } catch (RuntimeException $e) {
            // Solo lo que el servicio lanza a proposito, con mensaje
            // escrito para quien lo lee. Una averia del almacenamiento
            // no es un 422: sube, sale 500 y queda en Sentry.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Si se borró justo el archivo que servía de logo, la referencia en la
        // tabla queda apuntando a nada: se limpia para que la app no muestre
        // una imagen rota.
        if ($negocio->logo && str_contains($negocio->logo, basename($datos['key']))) {
            DB::table('business')->where('busines_id', $id)->update(['logo' => null]);
        }

        return response()->json(['message' => 'Archivo eliminado.']);
    }

    /* ==================================================================
       PRODUCTOS
       ================================================================== */
}
