<?php

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Models\Product\Product;
use App\Models\Product\ProductBusiness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * El catálogo, desde el lado del tendero.
 *
 * POR QUÉ NO SE REUTILIZA `ProductController@update`
 *
 * Un producto puede estar en varios negocios: `products` guarda el nombre, la
 * descripción y la categoría UNA sola vez, y `products_business` guarda el
 * precio y el stock de cada tienda. Hoy hay 145 productos compartidos por más
 * de un negocio.
 *
 * Ese controlador escribe la fila de `products` sin mirar con quién más se
 * comparte. Sirve para el equipo interno, que administra el catálogo entero;
 * puesto en manos del tendero significa que corregirle una tilde a «Arroz
 * Diana 500g» se la corrige —o se la estropea— a todas las tiendas que lo
 * venden, sin que ninguna se entere.
 *
 * LA REGLA
 *
 *  · Precio y existencias son de la tienda. Siempre editables.
 *  · Nombre, descripción, categoría y disponibilidad son del producto.
 *    Editables solo si ninguna otra tienda lo vende.
 *
 * La disponibilidad entra en el segundo grupo aunque no lo parezca: `products`
 * tiene un solo `state` para todas las tiendas, así que apagarlo lo retira del
 * catálogo de todas. La tienda que quiere dejar de ofrecer algo suyo pone las
 * existencias en cero, que sí es una columna por tienda.
 *
 * No es una restricción de permisos sino de hecho: cuando el producto es solo
 * suyo, cambiarlo no le toca nada a nadie. La respuesta incluye `compartido`
 * para que el panel pueda decirlo en pantalla en vez de bloquear campos sin
 * explicación — un campo deshabilitado y mudo se lee como un panel roto.
 *
 * Arreglar esto de raíz —que cada tienda tenga su propio nombre para lo que
 * vende— es un cambio de modelo de datos y no cabía en este trabajo. Queda
 * anotado.
 */
class ProductosDelNegocioController extends Controller
{
    /** En cuántos negocios se vende cada uno de estos productos. */
    private function vecesVendido(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }

        return ProductBusiness::whereIn('products_id', $productIds)
            ->select('products_id', DB::raw('COUNT(*) as negocios'))
            ->groupBy('products_id')
            ->pluck('negocios', 'products_id')
            ->all();
    }

    public function index(Request $request)
    {
        $businessId = (int) $request->attributes->get('busines_id');

        $filas = ProductBusiness::with('product.category')
            ->where('busines_id', $businessId)
            ->get();

        $compartidos = $this->vecesVendido(
            $filas->pluck('products_id')->map(fn ($i) => (int) $i)->all(),
        );

        $productos = $filas
            ->filter(fn ($pb) => $pb->product !== null)
            ->map(function ($pb) use ($compartidos) {
                $p = $pb->product;

                return [
                    'products_id' => (int) $p->products_id,
                    'name'        => $p->name,
                    'description' => $p->description,
                    'image'       => $p->image,
                    'category'    => $p->category ? [
                        'category_id' => (int) $p->category->category_id,
                        'name'        => $p->category->name,
                    ] : null,
                    'state'       => (bool) $p->state,
                    'price'       => (float) $pb->price,
                    'amount'      => (int) $pb->amount,
                    'qualification' => $pb->qualification !== null ? (float) $pb->qualification : null,
                    /*
                     * Cuántas tiendas MÁS lo venden. Cero significa que es solo
                     * suyo y los campos compartidos se pueden editar.
                     */
                    'compartido'  => max(0, (int) ($compartidos[$p->products_id] ?? 1) - 1),
                ];
            })
            ->sortBy('name')
            ->values();

        return response()->json(['data' => $productos]);
    }

    public function update(Request $request, int $id)
    {
        $businessId = (int) $request->attributes->get('busines_id');

        $datos = $request->validate([
            'price'       => 'nullable|numeric|min:0',
            'amount'      => 'nullable|integer|min:0',
            'name'        => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'nullable|integer|exists:category,category_id',
            'state'       => 'nullable|boolean',
        ]);

        $pb = ProductBusiness::where('products_id', $id)
            ->where('busines_id', $businessId)
            ->first();

        /*
         * 404 y no 403, igual que con los celadores de otro conjunto:
         * confirmar que el producto existe pero es de otra tienda ya es
         * decir algo del catálogo del vecino.
         */
        if (!$pb) {
            return response()->json(['message' => 'Ese producto no es de tu negocio.'], 404);
        }

        $otras = ProductBusiness::where('products_id', $id)
            ->where('busines_id', '!=', $businessId)
            ->count();

        $tocaCompartido = array_intersect(
            array_keys(array_filter($datos, fn ($v) => $v !== null)),
            ['name', 'description', 'category_id', 'state'],
        );

        if ($otras > 0 && $tocaCompartido) {
            return response()->json([
                'message' => ($otras === 1
                    ? 'Otra tienda vende este mismo producto'
                    : "Otras {$otras} tiendas venden este mismo producto")
                    . ', así que su nombre, su categoría y su disponibilidad son de todas y no se cambian desde acá. '
                    . 'El precio y las existencias sí son tuyos: para dejar de ofrecerlo, pon las existencias en cero.',
            ], 422);
        }

        DB::transaction(function () use ($datos, $pb, $id, $otras) {
            $deLaTienda = array_filter(
                ['price' => $datos['price'] ?? null, 'amount' => $datos['amount'] ?? null],
                fn ($v) => $v !== null,
            );

            if ($deLaTienda) {
                $pb->fill($deLaTienda)->save();
            }

            if ($otras === 0) {
                $delProducto = array_filter(
                    [
                        'name'        => $datos['name'] ?? null,
                        'description' => $datos['description'] ?? null,
                        'category_id' => $datos['category_id'] ?? null,
                        'state'       => $datos['state'] ?? null,
                    ],
                    fn ($v) => $v !== null,
                );

                if ($delProducto) {
                    /*
                     * Por la instancia y no con `Product::where()->update()`.
                     *
                     * `Product` extiende `Audit`, que registra los cambios
                     * enganchándose a los eventos del modelo. Una
                     * actualización masiva del constructor de consultas no
                     * dispara ninguno: escribe la fila y no deja rastro. Se
                     * vio en pruebas —el cambio de precio quedó auditado y el
                     * de nombre no— y era justo el que hacía falta, porque el
                     * precio se puede volver a mirar y un nombre pisado no se
                     * recupera de ninguna parte.
                     */
                    $producto = Product::find($id);

                    if ($producto) {
                        $producto->fill($delProducto)->save();
                    }
                }
            }
        });

        return response()->json(['message' => 'Producto actualizado.']);
    }
}
