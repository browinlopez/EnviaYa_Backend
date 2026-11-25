<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CrearProductoDelCatalogoRequest;
use App\Queries\ProductosDelPanel;
use App\Services\MediaService;
use App\Support\ImagenPrincipalEnLaFila;
use App\Support\ListadoPaginado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductosApiController extends Controller
{
    use ImagenPrincipalEnLaFila;

    /**
     * El listado del catalogo.
     *
     * LA CONSULTA VIVE EN `App\Queries\ProductosDelPanel`, que es lo que §12
     * pide. Aqui queda lo que si es del controlador: traducir la peticion a
     * filtros, pedirle a `ListadoPaginado` la pagina, pasar las filas por el
     * presentador de imagenes y responder.
     */
    public function products(Request $request, MediaService $medios)
    {
        $catalogo = new ProductosDelPanel;

        $q = $catalogo->listado(
            categoria: trim((string) $request->query('category', '')) ?: null,
            negocio: ((int) $request->query('business_id')) ?: null,
            nuevosDeTenderos: $request->boolean('pendientes'),
        );

        $r = ListadoPaginado::responder(
            $request,
            $q,
            buscables: ['p.name', 'p.description', 'b.name'],
            ordenables: [
                'name' => 'p.name',
                'price' => 'pb.price',
                'amount' => 'pb.amount',
                'business_name' => 'b.name',
                'category_name' => 'c.name',
            ],
            ordenPorDefecto: 'name',
            direccionPorDefecto: 'asc',
            resumen: fn ($f) => $catalogo->resumen($f),
        );

        $r['data'] = $this->conImagenPrincipal(
            collect($r['data']), 'productos', 'products_id', 'image', $medios
        );
        // El contador de la pestaña «Nuevos de tenderos».
        $r['pendientesDeTenderos'] = $catalogo->pendientesDeTenderos();

        return response()->json($r);
    }

    /** El desglose por categoria para el selector de la pantalla. */
    public function productCategories()
    {
        return response()->json((new ProductosDelPanel)->porCategoria());
    }

    /**
     * Alta de producto.
     *
     * El producto vive en `products` y su precio y existencias en
     * `products_business`: si se indica negocio, se crean las dos filas en la
     * misma transacción, porque un producto sin oferta no aparece en ninguna
     * tienda.
     */
    public function storeProduct(CrearProductoDelCatalogoRequest $request)
    {
        $datos = $request->validated();

        $id = DB::transaction(function () use ($datos) {
            $productoId = DB::table('products')->insertGetId([
                'name' => $datos['name'],
                'description' => $datos['description'] ?? null,
                'image' => $datos['image'] ?? null,
                'category_id' => $datos['category_id'] ?? null,
                'state' => 1,
                // La fecha la pone la aplicación y no la base: `CURRENT_TIMESTAMP`
                // es el reloj del servidor, que en el VPS no es el de Bogotá.
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (! empty($datos['busines_id'])) {
                DB::table('products_business')->insert([
                    'busines_id' => $datos['busines_id'],
                    'products_id' => $productoId,
                    'price' => $datos['price'] ?? 0,
                    'amount' => $datos['amount'] ?? 0,
                    'qualification' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $productoId;
        });

        return response()->json(['message' => 'Producto creado.', 'products_id' => $id], 201);
    }

    public function showProduct($id)
    {
        $producto = (new ProductosDelPanel)->ficha($id);
        abort_if(! $producto, 404, 'El producto no existe.');

        return response()->json($producto);
    }

    public function updateProduct(Request $request, $id)
    {
        abort_if(! DB::table('products')->where('products_id', $id)->exists(), 404, 'El producto no existe.');

        $datos = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'image' => 'sometimes|nullable|string',
            'state' => 'sometimes|boolean',
            // Lo que el tendero no puso al crearlo rápido: el equipo lo completa.
            'category_id' => 'sometimes|nullable|integer|exists:category,category_id',
            'barcode' => 'sometimes|nullable|string|max:20|unique:products,barcode,'.$id.',products_id',
            'brand' => 'sometimes|nullable|string|max:120',
        ]);

        if ($datos) {
            DB::table('products')->where('products_id', $id)->update($datos);
        }

        return $this->showProduct($id);
    }

    /**
     * POST admin/products/{id}/catalogo-general {general: bool}
     *
     * LA DECISIÓN DEL EQUIPO sobre un producto que creó un tendero: pasarlo
     * al catálogo general --las demás tiendas lo encuentran al buscar-- o
     * dejarlo solo en su tienda. Las dos lo sacan de la bandeja.
     */
    public function catalogoGeneral(Request $request, $id)
    {
        abort_if(! DB::table('products')->where('products_id', $id)->exists(), 404, 'El producto no existe.');

        $datos = $request->validate(['general' => 'required|boolean']);

        DB::table('products')->where('products_id', $id)->update([
            'en_catalogo_general' => $datos['general'],
            'revisado_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->showProduct($id);
    }

    /* ==================================================================
       ÓRDENES
       ================================================================== */
}
