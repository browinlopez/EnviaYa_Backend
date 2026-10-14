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
            /*
             * `extensions` Y NO `mimes`, Y NO ES UN DESCUIDO.
             *
             * `mimes` no mira la extensión: adivina el tipo por el contenido y
             * comprueba que ESE tipo corresponda a la extensión. El .xls que
             * exportan los puntos de venta de las tiendas es BIFF2, de 1987, y
             * nadie lo tiene en su tabla de tipos: se adivina como
             * `application/octet-stream`, que no corresponde a `xls`, y la
             * regla lo rechazaba con «debe ser un archivo de tipo xlsx, xls,
             * csv, txt» sobre un archivo .xls que Excel abre sin protestar.
             *
             * Imposible de entender desde el otro lado, e imposible de ver en
             * una prueba con `UploadedFile::fake()`, porque el archivo falso
             * declara el tipo que uno le dice.
             *
             * `extensions` comprueba la extensión, que es lo que aquí se
             * quiere. Lo que el archivo sea por dentro lo decide el lector, y
             * si no sabe leerlo lo dice con un motivo que se entiende.
             *
             * 10 MB da para varios miles de filas y corta el archivo que
             * alguien sube por error.
             */
            'archivo' => 'required|file|extensions:xlsx,xls,csv,txt|max:10240',
            /*
             * Para lo que el archivo no clasifique. Los que exporta un punto
             * de venta no traen columna de categoría, y sin esto la carga
             * entera se rechazaba fila por fila.
             */
            'categoria_id' => 'nullable|integer|exists:category,category_id',
        ]);

        $businessId = (int) $request->attributes->get('busines_id');

        $ruta = $request->file('archivo')->store('cargas-de-catalogo');

        $carga = CatalogUpload::create([
            'busines_id'      => $businessId,
            'user_id'         => $request->user()?->user_id,
            'archivo'         => $ruta,
            'nombre_original' => $request->file('archivo')->getClientOriginalName(),
            'category_id'     => $request->input('categoria_id'),
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
     *
     * LA PLANTILLA ES UNA COMODIDAD, NO UN REQUISITO. El importador también
     * entiende el archivo que el tendero exporta de su propio punto de venta,
     * con sus nombres de columna —`barras`, `venta1`, `existencia`— y en el
     * .xls viejo que esos programas generan. Obligar a renombrar veintisiete
     * columnas a mano sería pedirle que haga el trabajo que viene a evitar.
     */
    public function plantilla(): StreamedResponse
    {
        $filas = [
            ['codigo_barras', 'nombre', 'marca', 'categoria', 'precio', 'cantidad'],
            ['7702011012345', 'ACEITE 3 EN 1', '3-EN-UNO', 'Medicina', '2500', '20'],
            ['', 'Solo si el producto NO esta en el catalogo', '', '', '', ''],
            ['', '', '', '', '', ''],
            ['Tambien se acepta el archivo de tu punto de venta', '', '', '', '', ''],
            ['con sus columnas: barras, venta1, existencia', '', '', '', '', ''],
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
