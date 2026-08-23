<?php

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Services\CatalogoDeProductos;
use Illuminate\Http\Request;

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
    public function __construct(private CatalogoDeProductos $catalogo)
    {
    }

    /** Buscar para el select filtrado del panel. */
    public function buscar(Request $request)
    {
        $request->validate([
            'q'     => 'nullable|string|max:120',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

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

        if (!$ficha) {
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
    public function proponer(Request $request)
    {
        $datos = $request->validate([
            'name'        => 'required|string|max:255',
            'barcode'     => 'nullable|string|max:20',
            'brand'       => 'nullable|string|max:120',
            'description' => 'nullable|string',
            'category_id' => 'required|integer|exists:category,category_id',
            'image'       => 'nullable|string|max:2048',
        ]);

        $producto = $this->catalogo->proponer($datos, 'tendero');

        return response()->json([
            'message' => 'Producto agregado al catálogo.',
            'data'    => [
                'products_id' => (int) $producto->products_id,
                'barcode'     => $producto->barcode,
                'name'        => $producto->name,
                'brand'       => $producto->brand,
                'image'       => $producto->image,
            ],
        ], 201);
    }
}
