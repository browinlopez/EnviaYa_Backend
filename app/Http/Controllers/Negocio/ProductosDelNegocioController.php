<?php

declare(strict_types=1);

namespace App\Http\Controllers\Negocio;

use App\Support\UnidadDeVenta;
use App\Models\Product\ProductGroup;
use App\Services\MediaService;
use App\Support\ProductTypeSchema;
use App\Http\Controllers\Controller;
use App\Http\Requests\Negocio\ActualizarProductoRequest;
use App\Http\Requests\Negocio\AgregarProductoRequest;
use App\Http\Requests\Negocio\CopiarSurtidoRequest;
use App\Http\Requests\Negocio\PreciosEnLoteRequest;
use App\Http\Resources\Producto\ProductoDeLaTiendaResource;
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
    public function __construct(private CatalogoDeProductos $catalogo) {}

    /** En cuántos negocios se vende cada uno de estos productos. */
    private function vecesVendido(array $productIds): array
    {
        if (! $productIds) {
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

        $tipo = (int) DB::table('business')->where('busines_id', $businessId)->value('type');
        $relacion = ProductTypeSchema::relation($tipo);

        // La fila de su tipo de una vez: si no, una consulta por producto.
        $filas = ProductBusiness::with(array_filter([
            'product.category',
            'product.group',
            $relacion ? "product.{$relacion}" : null,
        ]))
            ->where('busines_id', $businessId)
            ->get();

        $compartidos = $this->vecesVendido(
            $filas->pluck('products_id')->map(fn ($i) => (int) $i)->all(),
        );

        $productos = $filas
            ->filter(fn ($pb) => $pb->product !== null)
            ->map(fn ($pb) => (new ProductoDeLaTiendaResource(
                $pb,
                // Cuantas tiendas MAS lo venden: el contador trae el total.
                otrasTiendas: max(0, (int) ($compartidos[$pb->product->products_id] ?? 1) - 1),
                tipoDeNegocio: $tipo ?: null,
            ))->resolve())
            ->sortBy('name')
            ->values();

        return response()->json(['data' => $productos]);
    }

    /**
     * La foto de un producto que solo vende este negocio.
     *
     * Un restaurante tiene su carta propia: sus platos no los vende nadie
     * más y la foto la pone él. Lo del catálogo compartido no se toca desde
     * una tienda —la foto de la Coca-Cola es la de todas—, así que ahí se
     * responde lo mismo que al nombre.
     */
    public function subirFoto(Request $request, int $id, MediaService $medios)
    {
        $businessId = (int) $request->attributes->get('busines_id');

        $pb = ProductBusiness::where('products_id', $id)
            ->where('busines_id', $businessId)
            ->first();

        if (! $pb) {
            return response()->json(['message' => 'Ese producto no es de tu negocio.'], 404);
        }

        $otras = ProductBusiness::where('products_id', $id)
            ->where('busines_id', '!=', $businessId)
            ->count();

        if ($otras > 0) {
            return response()->json([
                'message' => 'Otros negocios venden este mismo producto: su foto es de todos y no se cambia desde acá.',
            ], 422);
        }

        $request->validate(['file' => 'required|file|image|max:8192']);

        if (! $medios->configurado()) {
            return response()->json([
                'message' => 'El almacenamiento de archivos no está configurado en el servidor.',
            ], 503);
        }

        try {
            $archivo = $medios->subir(
                'productos',
                $id,
                Product::where('products_id', $id)->value('name'),
                $request->file('file'),
                'logo',
                $request->user()->user_id,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Foto actualizada.',
            'image' => $archivo['url'] ?? null,
        ]);
    }

    /**
     * Otra presentación de un producto que solo vende este negocio: la
     * porción familiar del sancocho, el chicharrón por media libra.
     *
     * Copia el producto —categoría, foto, descripción, los datos de su tipo y
     * cómo se vende— con su propia etiqueta y su propio precio, y junta los
     * dos en un grupo (`product_groups`): la app los enseña en una sola
     * tarjeta para elegir. Si el producto todavía no tenía grupo, se crea y
     * él queda con la etiqueta que diga `variante_actual` («Personal»).
     *
     * Lo del catálogo compartido no: sus grupos los arma el equipo, y una
     * tienda no puede meterle presentaciones a lo que venden las demás.
     */
    public function crearVariante(Request $request, int $id)
    {
        $datos = $request->validate([
            'variante' => 'required|string|max:40',
            'price' => 'required|numeric|min:0',
            'amount' => 'nullable|numeric|min:0',
            'variante_actual' => 'nullable|string|max:40',
        ]);
        $businessId = (int) $request->attributes->get('busines_id');
        $tipo = (int) ($request->attributes->get('negocio')->type ?? 0);

        $pb = ProductBusiness::where('products_id', $id)
            ->where('busines_id', $businessId)
            ->first();

        if (! $pb) {
            return response()->json(['message' => 'Ese producto no es de tu negocio.'], 404);
        }

        $otras = ProductBusiness::where('products_id', $id)
            ->where('busines_id', '!=', $businessId)
            ->count();

        if ($otras > 0) {
            return response()->json([
                'message' => 'Otros negocios venden este mismo producto: sus presentaciones las arma el equipo en el catálogo general.',
            ], 422);
        }

        $producto = Product::with(array_filter(['group', ProductTypeSchema::relation($tipo)]))->find($id);
        $etiqueta = trim($datos['variante']);

        // Dos presentaciones con la misma etiqueta serían el mismo botón dos veces.
        if ($producto->group_id) {
            $repetida = Product::where('group_id', $producto->group_id)
                ->whereRaw('LOWER(variante) = ?', [mb_strtolower($etiqueta)])
                ->exists();
            if ($repetida) {
                return response()->json([
                    'message' => "Ya tienes una presentación «{$etiqueta}».",
                    'errors' => ['variante' => ["Ya tienes una presentación «{$etiqueta}»."]],
                ], 422);
            }
        }

        $nueva = DB::transaction(function () use ($producto, $pb, $datos, $etiqueta, $businessId, $tipo) {
            if (! $producto->group_id) {
                $grupo = ProductGroup::create([
                    'name' => $producto->name,
                    'brand' => $producto->brand,
                    'category_id' => $producto->category_id,
                    'image' => $producto->image,
                    'description' => $producto->description,
                    'origen' => 'tendero',
                ]);
                $producto->fill([
                    'group_id' => $grupo->group_id,
                    'variante' => trim((string) ($datos['variante_actual'] ?? '')) ?: 'Normal',
                ])->save();
            }

            $grupoNombre = ProductGroup::where('group_id', $producto->group_id)->value('name') ?? $producto->name;

            $copia = Product::create([
                'name' => "{$grupoNombre} {$etiqueta}",
                'description' => $producto->description,
                'brand' => $producto->brand,
                'category_id' => $producto->category_id,
                'image' => $producto->image,
                'content' => $producto->content,
                'presentation' => $producto->presentation,
                'requiere_frio' => (bool) $producto->requiere_frio,
                'state' => 1,
                'origen' => 'tendero',
                'busines_id_propietario' => $businessId,
                'en_catalogo_general' => false,
                'group_id' => $producto->group_id,
                'variante' => $etiqueta,
            ]);

            // Lo de su tipo también: la porción familiar tiene los mismos
            // alérgenos que la personal.
            $relacion = ProductTypeSchema::relation($tipo);
            $modelo = ProductTypeSchema::model($tipo);
            if ($relacion && $modelo && $producto->{$relacion}) {
                $modelo::create(array_merge(
                    $producto->{$relacion}->only(ProductTypeSchema::fieldNames($tipo)),
                    ['products_id' => $copia->products_id],
                ));
            }

            $mide = UnidadDeVenta::esPeso($pb->sale_unit ?? 'unidad');

            return ProductBusiness::create([
                'busines_id' => $businessId,
                'products_id' => $copia->products_id,
                'category_id' => $pb->category_id,
                'price' => (float) $datos['price'],
                'sale_unit' => $pb->sale_unit ?? 'unidad',
                'por_valor' => (bool) $pb->por_valor,
                'min_qty' => $pb->min_qty,
                'step_qty' => $pb->step_qty,
                'amount' => $mide ? 0 : (int) ($datos['amount'] ?? $pb->amount ?? 0),
                'stock_weight' => $mide ? (float) ($datos['amount'] ?? $pb->stock_weight ?? 0) : 0,
                'qualification' => 0,
            ]);
        });

        return response()->json([
            'message' => "Listo: «{$etiqueta}» ya se puede pedir.",
            'data' => ['products_id' => (int) $nueva->products_id],
        ], 201);
    }

    public function update(ActualizarProductoRequest $request, int $id)
    {
        $businessId = (int) $request->attributes->get('busines_id');

        $datos = $request->validated();

        $pb = ProductBusiness::where('products_id', $id)
            ->where('busines_id', $businessId)
            ->first();

        /*
         * 404 y no 403, igual que con los celadores de otro conjunto:
         * confirmar que el producto existe pero es de otra tienda ya es
         * decir algo del catálogo del vecino.
         */
        if (! $pb) {
            return response()->json(['message' => 'Ese producto no es de tu negocio.'], 404);
        }

        $otras = ProductBusiness::where('products_id', $id)
            ->where('busines_id', '!=', $businessId)
            ->count();

        $negocio = $request->attributes->get('negocio');
        $tipo = (int) ($negocio->type ?? 0);

        /*
         * Lo propio del producto que llegó en la petición: los comunes
         * (contenido, presentación, frío) y los de su tipo de negocio. Se
         * mira si LLEGARON (`array_key_exists`) y no si traen valor: mandar
         * null es borrarlo, y un «vegano: false» también es un cambio.
         */
        $camposComunes = ProductTypeSchema::camposComunes();
        $camposDelTipo = $tipo && ProductTypeSchema::has($tipo)
            ? ProductTypeSchema::fieldNames($tipo)
            : [];
        $comunes = array_intersect_key($datos, array_flip($camposComunes));
        $delTipo = array_intersect_key($datos, array_flip($camposDelTipo));

        $tocaCompartido = array_merge(
            array_intersect(
                array_keys(array_filter($datos, fn ($v) => $v !== null)),
                ['name', 'description', 'category_id', 'state', 'variante'],
            ),
            array_keys($comunes),
            array_keys($delTipo),
        );

        if ($otras > 0 && $tocaCompartido) {
            return response()->json([
                'message' => ($otras === 1
                    ? 'Otro negocio vende este mismo producto'
                    : "Otros {$otras} negocios venden este mismo producto")
                    .', así que su nombre, su categoría y su disponibilidad son de todas y no se cambian desde acá. '
                    .'El precio y las existencias sí son tuyos: para dejar de ofrecerlo, pon las existencias en cero.',
            ], 422);
        }

        DB::transaction(function () use ($datos, $pb, $id, $otras, $comunes, $delTipo, $tipo) {
            $deLaTienda = array_filter(
                [
                    'price' => $datos['price'] ?? null,
                    'costo' => $datos['costo'] ?? null,
                    'amount' => $datos['amount'] ?? null,
                    'expiration_date' => $datos['expiration_date'] ?? null,
                    // Como lo vende ESTA tienda. `null` es «no lo mandaste» y
                    // no se pisa lo que hubiera, igual que el resto.
                    'sale_unit' => $datos['sale_unit'] ?? null,
                    'stock_weight' => $datos['stock_weight'] ?? null,
                    'min_qty' => $datos['min_qty'] ?? null,
                    'step_qty' => $datos['step_qty'] ?? null,
                    'por_valor' => isset($datos['por_valor']) ? (bool) $datos['por_valor'] : null,
                ],
                fn ($v) => $v !== null,
            );

            if ($deLaTienda) {
                $pb->fill($deLaTienda)->save();
            }

            if ($otras === 0) {
                $delProducto = array_filter(
                    [
                        'name' => $datos['name'] ?? null,
                        'description' => $datos['description'] ?? null,
                        'category_id' => $datos['category_id'] ?? null,
                        'state' => $datos['state'] ?? null,
                        'variante' => isset($datos['variante']) ? trim($datos['variante']) : null,
                    ],
                    fn ($v) => $v !== null,
                );

                // Contenido, presentación y frío viven en `products`.
                $delProducto = array_merge($delProducto, $comunes);

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

                /*
                 * Lo de su tipo va en la tabla de su tipo (restaurant_products,
                 * pharmacy_products…), una fila por producto: si no la tenía,
                 * se crea. Es lo que deja a un restaurante llevar su carta
                 * completa —alérgenos, preparación, acompañamientos— sin
                 * subir un archivo.
                 */
                $modelo = $tipo ? ProductTypeSchema::model($tipo) : null;

                if ($delTipo && $modelo) {
                    $modelo::updateOrCreate(['products_id' => $id], $delTipo);
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
    public function agregar(AgregarProductoRequest $request)
    {
        $businessId = (int) $request->attributes->get('busines_id');

        $datos = $request->validated();

        $yaLoTenia = ProductBusiness::where('busines_id', $businessId)
            ->where('products_id', $datos['products_id'])
            ->exists();

        $this->catalogo->agregarATienda(
            $businessId,
            (int) $datos['products_id'],
            (float) $datos['price'],
            (int) $datos['amount'],
            array_filter([
                'sale_unit' => $datos['sale_unit'] ?? null,
                'stock_weight' => $datos['stock_weight'] ?? null,
                'min_qty' => $datos['min_qty'] ?? null,
                'step_qty' => $datos['step_qty'] ?? null,
                'por_valor' => isset($datos['por_valor']) ? (bool) $datos['por_valor'] : null,
            ], fn ($v) => $v !== null),
        );

        /*
         * Agregar algo que ya se tiene es querer corregirle el precio, no un
         * error: se acepta y se dice cuál de las dos cosas pasó, para que el
         * panel no cante «agregado» cuando lo que hizo fue actualizar.
         */
        return response()->json([
            'message' => $yaLoTenia
                ? 'Ya lo tenías: se actualizó el precio y las existencias.'
                : 'Producto agregado a tu negocio.',
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

        if (! $borradas) {
            return response()->json(['message' => 'Ese producto no es de tu negocio.'], 404);
        }

        return response()->json(['message' => 'Producto retirado de tu negocio.']);
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
    public function precios(PreciosEnLoteRequest $request)
    {
        $businessId = (int) $request->attributes->get('busines_id');

        $datos = $request->validated();

        $mios = ProductBusiness::where('busines_id', $businessId)
            ->pluck('products_id')
            ->map(fn ($i) => (int) $i)
            ->all();

        $tocados = 0;
        $ajenos = [];

        DB::transaction(function () use ($datos, $businessId, $mios, &$tocados, &$ajenos) {
            foreach ($datos['cambios'] as $c) {
                $id = (int) $c['products_id'];

                if (! in_array($id, $mios, true)) {
                    $ajenos[] = $id;

                    continue;
                }

                $campos = array_filter(
                    ['price' => $c['price'] ?? null, 'amount' => $c['amount'] ?? null],
                    fn ($v) => $v !== null,
                );

                if (! $campos) {
                    continue;
                }

                ProductBusiness::where('busines_id', $businessId)
                    ->where('products_id', $id)
                    ->update($campos);

                $tocados++;
            }
        });

        return response()->json([
            'message' => "Se actualizaron {$tocados} productos.",
            'actualizados' => $tocados,
            /*
             * Los que no son suyos se informan en vez de reventar la petición
             * entera: con una lista larga, un id equivocado no puede tirar
             * abajo los otros cuatrocientos cambios buenos.
             */
            'ignorados' => $ajenos,
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
    public function copiar(CopiarSurtidoRequest $request)
    {
        $businessId = (int) $request->attributes->get('busines_id');

        $datos = $request->validated();

        if ((int) $datos['desde'] === $businessId) {
            return response()->json(['message' => 'Esa ya es tu negocio.'], 422);
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
