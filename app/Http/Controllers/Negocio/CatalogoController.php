<?php

declare(strict_types=1);

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Http\Requests\Negocio\BuscarEnElCatalogoRequest;
use App\Http\Requests\Negocio\ProponerProductoRequest;
use App\Services\CatalogoDeProductos;
use App\Support\UnidadDeVenta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * El catálogo maestro, visto desde una tienda.
 *
 * Es de solo lectura salvo por `proponer`, y esa asimetría es el punto: el
 * tendero encuentra productos, no los inventa. Lo que escribe de verdad —precio
 * y existencias— vive en `ProductosDelNegocioController`.
 *
 * Todo va bajo el middleware `negocio`, que resuelve de qué local se trata sin
 * confiar en lo que mande el cliente. Acá eso importa por una razón que no es
 * obvia: la búsqueda devuelve `ya_lo_tienes`, y ese dato es del local que
 * pregunta. Si el negocio llegara por parámetro, cualquiera podría averiguar
 * qué vende el vecino preguntando producto por producto.
 */
class CatalogoController extends Controller
{
    public function __construct(private CatalogoDeProductos $catalogo) {}

    /** Buscar para el select filtrado del panel. */
    public function buscar(BuscarEnElCatalogoRequest $request)
    {
        $businessId = (int) $request->attributes->get('busines_id');

        return response()->json([
            'data' => $this->catalogo->buscar(
                (string) $request->query('q', ''),
                $businessId,
                (int) $request->query('limit', 25),
            ),
        ]);
    }

    /**
     * Lo que hay detrás de un código de barras.
     *
     * 404 cuando no está ni en el catálogo ni en la fuente externa. No es un
     * error: es el caso normal de un producto que nadie ha cargado todavía, y
     * el panel lo usa para ofrecer darlo de alta.
     */
    public function porCodigo(Request $request, string $barcode)
    {
        $businessId = (int) $request->attributes->get('busines_id');

        $ficha = $this->catalogo->porCodigo($barcode, $businessId);

        if (! $ficha) {
            return response()->json([
                'message' => 'Ese código no está en el catálogo. Puedes darlo de alta.',
                'barcode' => preg_replace('/\D/', '', $barcode),
            ], 404);
        }

        return response()->json(['data' => $ficha]);
    }

    /**
     * Dar de alta un producto que no existía.
     *
     * La categoría es obligatoria aunque en `products` sea nullable: sin ella
     * el producto no aparece en ninguna sección de la app y el tendero lo
     * carga para nada. Lo que la base permite y lo que el negocio necesita no
     * son lo mismo.
     */
    public function proponer(ProponerProductoRequest $request)
    {
        $datos = $request->validated();

        $producto = $this->catalogo->proponer(
            $datos,
            'tendero',
            (int) $request->attributes->get('busines_id') ?: null,
        );

        return response()->json([
            'message' => 'Producto agregado al catálogo.',
            'data' => [
                'products_id' => (int) $producto->products_id,
                'barcode' => $producto->barcode,
                'name' => $producto->name,
                'brand' => $producto->brand,
                'image' => $producto->image,
            ],
        ], 201);
    }

    /**
     * POST negocio/productos/propio — «hormiga culona, $3.000».
     *
     * Para lo que el cliente pidió, la tienda tiene y el catálogo no. Con
     * nombre y precio basta para venderlo ya; queda en la bandeja del panel
     * para que el equipo lo complete.
     */
    public function propio(Request $request)
    {
        $datos = $request->validate([
            'name' => 'required|string|min:2|max:120',
            'price' => 'required|numeric|min:1|max:100000000',
            'sale_unit' => 'nullable|string|in:'.implode(',', UnidadDeVenta::TODAS),
            'por_valor' => 'nullable|boolean',
            'amount' => 'nullable|numeric|min:0|max:100000',
            'costo' => 'nullable|numeric|min:0|max:100000000',
        ]);

        $negocio = (int) $request->attributes->get('busines_id');

        /*
         * El mismo nombre dos veces en la misma tienda es un doble toque, no
         * dos productos: se devuelve el que ya estaba.
         */
        $repetido = DB::table('products as p')
            ->join('products_business as pb', 'pb.products_id', '=', 'p.products_id')
            ->where('pb.busines_id', $negocio)
            ->where('p.busines_id_propietario', $negocio)
            ->whereRaw('LOWER(p.name) = ?', [mb_strtolower(trim($datos['name']))])
            ->value('p.products_id');

        if ($repetido) {
            return response()->json([
                'message' => 'Ya tenías un producto con ese nombre.',
                'data' => ['products_id' => (int) $repetido, 'ya_existia' => true],
            ]);
        }

        $fila = $this->catalogo->crearPropio($negocio, $datos);

        return response()->json([
            'message' => 'Listo, ya lo puedes vender.',
            'data' => [
                'products_id' => (int) $fila->products_id,
                'name' => trim($datos['name']),
                'price' => (float) $fila->price,
                'sale_unit' => $fila->sale_unit,
                'por_valor' => (bool) $fila->por_valor,
                'ya_existia' => false,
            ],
        ], 201);
    }
}
