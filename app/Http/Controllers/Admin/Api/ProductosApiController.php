<?php

namespace App\Http\Controllers\Admin\Api;

use App\Support\Consultas\AyudasDeAdmin;
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

class ProductosApiController extends Controller
{
    use AyudasDeAdmin;


    public function products(Request $request, MediaService $medios)
    {
        // El producto vive en `products` y su precio/existencias en
        // `products_business`: la misma referencia puede aparecer varias
        // veces, una por tienda, y cada fila es una oferta distinta.
        // Las unidades vendidas van por subconsulta: unir el detalle de
        // pedidos aquí duplicaría cada renglón una vez por tienda que ofrece
        // el mismo producto.
        $q = DB::table('products as p')
                ->leftJoin('products_business as pb', 'pb.products_id', '=', 'p.products_id')
                ->leftJoin('business as b', 'b.busines_id', '=', 'pb.busines_id')
                ->leftJoin('category as c', 'c.category_id', '=', 'p.category_id')
                ->select([
                    'p.products_id', 'p.name', 'p.description', 'p.image', 'p.state',
                    'pb.busines_products_id', 'pb.price', 'pb.amount', 'pb.qualification',
                    'b.busines_id', 'b.name as business_name', 'c.name as category_name',
                ])
                ->selectSub(
                    DB::table('orderssales_detail')
                        ->selectRaw('COALESCE(SUM(amount), 0)')
                        ->whereColumn('orderssales_detail.product_id', 'p.products_id'),
                    'sold',
                )
                ;

        if ($categoria = trim((string) $request->query('category', ''))) {
            $q->where('c.name', $categoria);
        }

        // El join a `business` ya estaba —se usa para el nombre y para
        // ordenar—, así que filtrar por negocio no cuesta una consulta más.
        if ($negocio = (int) $request->query('business_id')) {
            $q->where('b.busines_id', $negocio);
        }

        $r = ListadoPaginado::responder(
            $request,
            $q,
            buscables: ['p.name', 'p.description', 'b.name'],
            ordenables: [
                'name'          => 'p.name',
                'price'         => 'pb.price',
                'amount'        => 'pb.amount',
                'business_name' => 'b.name',
                'category_name' => 'c.name',
            ],
            ordenPorDefecto: 'name',
            direccionPorDefecto: 'asc',
            resumen: fn ($f) => $this->resumenDeProductos($f),
        );

        $r['data'] = $this->conImagenPrincipal(
            collect($r['data']), 'productos', 'products_id', 'image', $medios
        );

        return response()->json($r);
    }

    /**
     * Desglose por categoría, agregado en la base.
     *
     * La pantalla lo usa para su selector. Estaba resolviéndolo en el navegador
     * recorriendo el catálogo entero — es decir, pedía las 755 ofertas ADEMÁS
     * de la página, y con eso la paginación no ahorraba nada. Son unas decenas
     * de filas: se calculan con un GROUP BY y se traen aparte.
     */
    public function productCategories()
    {
        return response()->json(
            DB::table('products as p')
                ->leftJoin('products_business as pb', 'pb.products_id', '=', 'p.products_id')
                ->join('category as c', 'c.category_id', '=', 'p.category_id')
                ->groupBy('c.category_id', 'c.name')
                ->orderByDesc(DB::raw('COUNT(*)'))
                ->get([
                    DB::raw('c.name as name'),
                    DB::raw('COUNT(*) as items'),
                    DB::raw('SUM(CASE WHEN pb.amount = 0 THEN 1 ELSE 0 END) as agotados'),
                    DB::raw('COALESCE(SUM(pb.price * pb.amount), 0) as valor'),
                ])
        );
    }

    /**
     * Indicadores del catálogo, sobre todo lo filtrado.
     *
     * "Referencias" cuenta OFERTAS y no productos distintos: la misma
     * referencia en tres tiendas son tres filas con tres precios y tres
     * existencias, y es lo que se está mirando en la tabla.
     */
    private function resumenDeProductos($q): array
    {
        $r = ListadoPaginado::soloAgregados($q, "
            COUNT(*) as total,
            COALESCE(SUM(pb.price * pb.amount), 0) as inventario,
            SUM(CASE WHEN pb.amount = 0 THEN 1 ELSE 0 END) as agotados,
            SUM(CASE WHEN p.image IS NULL OR p.image = '' THEN 1 ELSE 0 END) as sin_imagen
        ");

        return [
            'total'      => (int) ($r->total ?? 0),
            'inventario' => round((float) ($r->inventario ?? 0), 2),
            'agotados'   => (int) ($r->agotados ?? 0),
            'sinImagen'  => (int) ($r->sin_imagen ?? 0),
        ];
    }

    /**
     * Alta de producto.
     *
     * El producto vive en `products` y su precio y existencias en
     * `products_business`: si se indica negocio, se crean las dos filas en la
     * misma transacción, porque un producto sin oferta no aparece en ninguna
     * tienda.
     */
    public function storeProduct(Request $request)
    {
        $datos = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'image'       => 'nullable|string',
            'category_id' => 'nullable|integer|exists:category,category_id',
            'busines_id'  => 'nullable|integer|exists:business,busines_id',
            'price'       => 'nullable|numeric|min:0',
            'amount'      => 'nullable|integer|min:0',
        ]);

        $id = DB::transaction(function () use ($datos) {
            $productoId = DB::table('products')->insertGetId([
                'name'        => $datos['name'],
                'description' => $datos['description'] ?? null,
                'image'       => $datos['image'] ?? null,
                'category_id' => $datos['category_id'] ?? null,
                'state'       => 1,
                // La fecha la pone la aplicación y no la base: `CURRENT_TIMESTAMP`
                // es el reloj del servidor, que en el VPS no es el de Bogotá.
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            if (!empty($datos['busines_id'])) {
                DB::table('products_business')->insert([
                    'busines_id'    => $datos['busines_id'],
                    'products_id'   => $productoId,
                    'price'         => $datos['price'] ?? 0,
                    'amount'        => $datos['amount'] ?? 0,
                    'qualification' => 0,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }

            return $productoId;
        });

        return response()->json(['message' => 'Producto creado.', 'products_id' => $id], 201);
    }

    public function showProduct($id)
    {
        $producto = DB::table('products')->where('products_id', $id)->first();
        abort_if(!$producto, 404, 'El producto no existe.');

        return response()->json($producto);
    }

    public function updateProduct(Request $request, $id)
    {
        abort_if(!DB::table('products')->where('products_id', $id)->exists(), 404, 'El producto no existe.');

        $datos = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'image'       => 'sometimes|nullable|string',
            'state'       => 'sometimes|boolean',
        ]);

        if ($datos) {
            DB::table('products')->where('products_id', $id)->update($datos);
        }

        return $this->showProduct($id);
    }

    /* ==================================================================
       ÓRDENES
       ================================================================== */
}
