<?php

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Jobs\ProcesarCargaDeCatalogo;
use App\Models\Product\CatalogUpload;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Subir un Excel con el catálogo de la tienda.
 *
 * Tres endpoints y una plantilla. La plantilla importa más de lo que parece: si
 * cada tienda inventa sus columnas, el importador se convierte en un adivino.
 */
class CargaDeCatalogoController extends Controller
{
    public function subir(Request $request)
    {
        $request->validate([
            // 10 MB da para varios miles de filas y corta el archivo que
            // alguien sube por error.
            'archivo' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
        ]);

        $businessId = (int) $request->attributes->get('busines_id');

        $ruta = $request->file('archivo')->store('cargas-de-catalogo');

        $carga = CatalogUpload::create([
            'busines_id'      => $businessId,
            'user_id'         => $request->user()?->user_id,
            'archivo'         => $ruta,
            'nombre_original' => $request->file('archivo')->getClientOriginalName(),
            'estado'          => 'pendiente',
        ]);

        ProcesarCargaDeCatalogo::dispatch((int) $carga->catalog_upload_id);

        return response()->json([
            'message' => 'Recibimos tu archivo. Te avisamos cuando termine.',
            'data'    => $this->comoFicha($carga),
        ], 202);
    }

    /** Cómo va —o cómo fue— una carga. */
    public function ver(Request $request, int $id)
    {
        $businessId = (int) $request->attributes->get('busines_id');

        $carga = CatalogUpload::where('catalog_upload_id', $id)
            ->where('busines_id', $businessId)
            ->first();

        if (!$carga) {
            return response()->json(['message' => 'Esa carga no es de tu negocio.'], 404);
        }

        return response()->json(['data' => $this->comoFicha($carga)]);
    }

    /** Las últimas cargas, para que la de ayer siga a la vista. */
    public function historial(Request $request)
    {
        $businessId = (int) $request->attributes->get('busines_id');

        $cargas = CatalogUpload::where('busines_id', $businessId)
            ->orderByDesc('catalog_upload_id')
            ->limit(20)
            ->get()
            ->map(fn ($c) => $this->comoFicha($c, false));

        return response()->json(['data' => $cargas]);
    }

    /**
     * La plantilla.
     *
     * Tres columnas obligatorias y dos opcionales, y no doce: el nombre, la
     * marca y la categoría solo hacen falta para lo que TODAVÍA NO ESTÁ en el
     * catálogo. Para lo que ya está, basta el código y el precio.
     *
     * Va como CSV con BOM porque es lo que Excel abre sin preguntar nada y sin
     * romper las tildes.
     */
    public function plantilla(): StreamedResponse
    {
        $filas = [
            ['codigo_barras', 'nombre', 'marca', 'categoria', 'precio', 'cantidad'],
            ['7702011012345', 'ACEITE 3 EN 1', '3-EN-UNO', 'Medicina', '2500', '20'],
            ['', 'Solo si el producto NO esta en el catalogo', '', '', '', ''],
        ];

        return response()->streamDownload(function () use ($filas) {
            $salida = fopen('php://output', 'w');

            // Sin el BOM, Excel abre el archivo en ANSI y parte las tildes.
            fwrite($salida, "\xEF\xBB\xBF");

            foreach ($filas as $f) {
                fputcsv($salida, $f, ';');
            }

            fclose($salida);
        }, 'plantilla-catalogo.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function comoFicha(CatalogUpload $c, bool $conErrores = true): array
    {
        $ficha = [
            'catalog_upload_id' => (int) $c->catalog_upload_id,
            'nombre_original'   => $c->nombre_original,
            'estado'            => $c->estado,
            'filas'             => (int) $c->filas,
            'creados'           => (int) $c->creados,
            'actualizados'      => (int) $c->actualizados,
            'rechazados'        => (int) $c->rechazados,
            'created_at'        => $c->created_at,
        ];

        if ($conErrores) {
            $ficha['errores'] = $c->resumen['errores'] ?? [];
            $ficha['truncado'] = (bool) ($c->resumen['truncado'] ?? false);
        }

        return $ficha;
    }
}
