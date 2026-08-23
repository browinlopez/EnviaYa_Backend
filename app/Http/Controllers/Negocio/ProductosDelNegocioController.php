<?php

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Models\Product\Product;
use App\Models\Product\ProductBusiness;
use App\Services\CatalogoDeProductos;
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
    public function __construct(private CatalogoDeProductos $catalogo)
    {
    }

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
                    'barcode'     => $p->barcode,
                    'brand'       => $p->brand,
                    'description' => $p->description,
                    'image'       => $p->image,
                    'category'    => $p->category ? [
                        'category_id' => (int) $p->category->category_id,
                        'name'        => $p->category->name,
                    ] : null,
                    'state'       => (bool) $p->state,
                    'price'       => (float) $pb->price,
                    'amount'      => (int) $pb->amount,
                    /*
                     * Sin precio no se ofrece al comprador. Los que llegan por
                     * copia de otra tienda nacen asi, y el panel tiene que
                     * poder senalarlos: son la lista de trabajo del tendero.
                     */
                    'sin_precio'  => (float) $pb->price <= 0,
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

    /**
     * AGREGAR UN PRODUCTO DEL CATÁLOGO A ESTA TIENDA.
     *
     * Reemplaza al alta de texto libre. Antes, `POST /v1/negocio/productos` iba
     * a `ProductController@store`: recibía nombre, descripción y categoría, y
     * creaba una fila nueva en `products`. Es decir, cada tendero podía meter
     * productos al catálogo maestro de toda la plataforma con el nombre que se
     * le ocurriera, mientras el `update` de acá arriba le impedía —con razón—
     * cambiarle una tilde a un producto compartido. Se defendía una puerta y la
     * otra estaba abierta.
     *
     * Ahora entran tres datos y ninguno es texto: cuál producto del catálogo, a
     * cuánto lo vende y cuántos tiene. Si llega `name` o `category_id`, se
     * ignoran.
     */
    public function agregar(Request $request)
    {
        $businessId = (int) $request->attributes->get('busines_id');

        $datos = $request->validate([
            'products_id' => 'required|integer|exists:products,products_id',
            'price'       => 'required|numeric|min:0',
            'amount'      => 'required|integer|min:0',
        ]);

        $yaLoTenia = ProductBusiness::where('busines_id', $businessId)
            ->where('products_id', $datos['products_id'])
            ->exists();

        $this->catalogo->agregarATienda(
            $businessId,
            (int) $datos['products_id'],
            (float) $datos['price'],
            (int) $datos['amount'],
        );

        /*
         * Agregar algo que ya se tiene es querer corregirle el precio, no un
         * error: se acepta y se dice cuál de las dos cosas pasó, para que el
         * panel no cante «agregado» cuando lo que hizo fue actualizar.
         */
        return response()->json([
            'message' => $yaLoTenia
                ? 'Ya lo tenías: se actualizó el precio y las existencias.'
                : 'Producto agregado a tu tienda.',
            'actualizado' => $yaLoTenia,
        ], $yaLoTenia ? 200 : 201);
    }

    /**
     * QUITAR UN PRODUCTO DE ESTA TIENDA.
     *
     * Borra la fila de `products_business` y no toca `products`: el producto
     * sigue en el catálogo para las demás tiendas. Es la diferencia entre «yo
     * ya no vendo esto» y «esto no existe», y solo la primera es decisión suya.
     */
    public function quitar(Request $request, int $id)
    {
        $businessId = (int) $request->attributes->get('busines_id');

        $borradas = ProductBusiness::where('busines_id', $businessId)
            ->where('products_id', $id)
            ->delete();

        if (!$borradas) {
            return response()->json(['message' => 'Ese producto no es de tu negocio.'], 404);
        }

        return response()->json(['message' => 'Producto retirado de tu tienda.']);
    }

    /**
     * PRECIOS EN BLOQUE.
     *
     * El inventario se levanta una vez; los precios cambian todo el tiempo. Un
     * catálogo con precios de hace tres meses es peor que no tener catálogo,
     * porque el cliente pide, llega otra cifra y el que queda mal es el
     * domiciliario en la puerta.
     *
     * Una petición por producto convierte «subir todo un 5 %» en cuarenta
     * peticiones y cuarenta oportunidades de que una falle a la mitad. Entran
     * todas juntas y en una transacción.
     */
    public function precios(Request $request)
    {
        $businessId = (int) $request->attributes->get('busines_id');

        $datos = $request->validate([
            'cambios'               => 'required|array|min:1|max:500',
            'cambios.*.products_id' => 'required|integer',
            'cambios.*.price'       => 'nullable|numeric|min:0',
            'cambios.*.amount'      => 'nullable|integer|min:0',
        ]);

        $mios = ProductBusiness::where('busines_id', $businessId)
            ->pluck('products_id')
            ->map(fn ($i) => (int) $i)
            ->all();

        $tocados = 0;
        $ajenos = [];

        DB::transaction(function () use ($datos, $businessId, $mios, &$tocados, &$ajenos) {
            foreach ($datos['cambios'] as $c) {
                $id = (int) $c['products_id'];

                if (!in_array($id, $mios, true)) {
                    $ajenos[] = $id;
                    continue;
                }

                $campos = array_filter(
                    ['price' => $c['price'] ?? null, 'amount' => $c['amount'] ?? null],
                    fn ($v) => $v !== null,
                );

                if (!$campos) {
                    continue;
                }

                ProductBusiness::where('busines_id', $businessId)
                    ->where('products_id', $id)
                    ->update($campos);

                $tocados++;
            }
        });

        return response()->json([
            'message'      => "Se actualizaron {$tocados} productos.",
            'actualizados' => $tocados,
            /*
             * Los que no son suyos se informan en vez de reventar la petición
             * entera: con una lista larga, un id equivocado no puede tirar
             * abajo los otros cuatrocientos cambios buenos.
             */
            'ignorados'    => $ajenos,
        ]);
    }

    /** De qué tiendas tiene sentido copiar el surtido. */
    public function tiendasParaCopiar(Request $request)
    {
        $businessId = (int) $request->attributes->get('busines_id');

        return response()->json([
            'data' => $this->catalogo->tiendasParaCopiar($businessId),
        ]);
    }

    /**
     * COPIAR EL SURTIDO DE OTRA TIENDA.
     *
     * Entre tiendas de barrio del mismo tipo el grueso de lo que se vende se
     * repite, y levantar cuatrocientas filas a mano cuando la de tres cuadras
     * más allá ya las tiene es trabajo tirado.
     *
     * Los precios NO se copian: llegan en cero, o sea invisibles para el
     * comprador hasta que el tendero los ponga. Eso convierte la copia en una
     * lista de trabajo en vez de en un catálogo que anuncia precios ajenos.
     */
    public function copiar(Request $request)
    {
        $businessId = (int) $request->attributes->get('busines_id');

        $datos = $request->validate([
            'desde' => 'required|integer|exists:business,busines_id',
        ]);

        if ((int) $datos['desde'] === $businessId) {
            return response()->json(['message' => 'Esa ya es tu tienda.'], 422);
        }

        $r = $this->catalogo->copiarDe($businessId, (int) $datos['desde']);

        return response()->json([
            'message' => $r['copiados'] === 0
                ? 'No había nada nuevo que copiar.'
                : "Se copiaron {$r['copiados']} productos, todos sin precio. Ponles precio para que se vean.",
            'copiados' => $r['copiados'],
            'ya_tenia' => $r['ya_tenia'],
        ]);
    }
}
