<?php

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Http\Requests\Negocio\SubirCatalogoRequest;
use App\Jobs\ProcesarCargaDeCatalogo;
use App\Models\Product\CatalogUpload;
use App\Services\PlantillaDeCatalogo;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Subir un Excel con el catálogo de la tienda.
 *
 * Tres endpoints y una plantilla. La plantilla importa más de lo que parece: si
 * cada tienda inventa sus columnas, el importador se convierte en un adivino.
 */
class CargaDeCatalogoController extends Controller
{
    public function subir(SubirCatalogoRequest $request)
    {

        $businessId = (int) $request->attributes->get('busines_id');

        $ruta = $request->file('archivo')->store('cargas-de-catalogo');

        $carga = CatalogUpload::create([
            'busines_id' => $businessId,
            'user_id' => $request->user()?->user_id,
            'archivo' => $ruta,
            'nombre_original' => $request->file('archivo')->getClientOriginalName(),
            'category_id' => $request->input('categoria_id'),
            'estado' => 'pendiente',
        ]);

        ProcesarCargaDeCatalogo::dispatch((int) $carga->catalog_upload_id);

        return response()->json([
            'message' => 'Recibimos tu archivo. Te avisamos cuando termine.',
            'data' => $this->comoFicha($carga),
        ], 202);
    }

    /** Cómo va —o cómo fue— una carga. */
    public function ver(Request $request, int $id)
    {
        $businessId = (int) $request->attributes->get('busines_id');

        $carga = CatalogUpload::where('catalog_upload_id', $id)
            ->where('busines_id', $businessId)
            ->first();

        if (! $carga) {
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
    /**
     * La plantilla, como Excel (.xlsx): columnas ordenadas, ayuda en cada
     * celda y la categoría como lista. Con `?con=productos` trae además el
     * catálogo actual de la tienda, para cambiar precios y existencias de
     * muchos productos y volver a subirlo. Ver PlantillaDeCatalogo.
     */
    public function plantilla(Request $request, PlantillaDeCatalogo $plantilla): StreamedResponse
    {
        $negocioId = (int) $request->attributes->get('busines_id');
        $conProductos = $request->query('con') === 'productos';

        $libro = $plantilla->generar($negocioId, $conProductos);
        $nombre = $conProductos ? 'mi-catalogo.xlsx' : 'plantilla-catalogo.xlsx';

        return response()->streamDownload(function () use ($libro) {
            (new Xlsx($libro))->save('php://output');
        }, $nombre, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function comoFicha(CatalogUpload $c, bool $conErrores = true): array
    {
        $ficha = [
            'catalog_upload_id' => (int) $c->catalog_upload_id,
            'nombre_original' => $c->nombre_original,
            'estado' => $c->estado,
            'filas' => (int) $c->filas,
            'creados' => (int) $c->creados,
            'actualizados' => (int) $c->actualizados,
            'rechazados' => (int) $c->rechazados,
            'created_at' => $c->created_at,
        ];

        if ($conErrores) {
            $ficha['errores'] = $c->resumen['errores'] ?? [];
            $ficha['truncado'] = (bool) ($c->resumen['truncado'] ?? false);
        }

        return $ficha;
    }
}
