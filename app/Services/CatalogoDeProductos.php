<?php

namespace App\Services;

use App\Models\Product\Product;
use App\Models\Product\ProductBusiness;
use Illuminate\Support\Facades\DB;

/**
 * EL CATÁLOGO MAESTRO.
 *
 * `products` es de la plataforma; `products_business` es de cada tienda. Este
 * servicio es la frontera entre las dos cosas, y existe para que esa frontera
 * se cruce siempre por el mismo sitio.
 *
 * LA REGLA QUE IMPONE
 *
 * El tendero NO escribe en `products`. Ni al crear ni al editar. Antes de esto
 * `POST /v1/negocio/productos` iba a `ProductController@store`, que recibía
 * nombre y categoría en texto libre y creaba una fila nueva en el catálogo
 * maestro: cualquier local podía meter productos al catálogo de toda la
 * plataforma con el nombre que se le ocurriera. El otro lado ya estaba
 * defendido —`ProductosDelNegocioController` no deja editar el nombre de un
 * producto compartido— pero el alta quedó abierta, así que el catálogo se
 * ensuciaba por donde nadie miraba.
 *
 * Ahora el tendero ELIGE del catálogo y pone dos números: precio y cantidad.
 * Su terreno es `products_business`, y ahí manda entero.
 *
 * Proponer un producto que no existe sigue siendo posible —si no, no se podría
 * vender nada nuevo— pero es un camino aparte, más lento a propósito, y lo que
 * entra por ahí queda marcado con `origen = 'tendero'` para poder auditarlo
 * después sin frenar a nadie.
 */
class CatalogoDeProductos
{
    public function __construct(private FuenteExternaDeProductos $fuente)
    {
    }

    /**
     * Buscar en el catálogo maestro.
     *
     * Devuelve también si la tienda que pregunta YA lo vende, porque sin ese
     * dato el tendero elige algo que ya tiene, el servidor lo rechaza y él no
     * entiende por qué. Es más barato decirlo en la lista.
     */
    public function buscar(string $texto, int $businessId, int $limite = 25): array
    {
        $texto = trim($texto);

        $consulta = Product::query()
            ->with('category')
            ->where('state', 1);

        if ($texto !== '') {
            /*
             * Un código de barras se escribe entero o no se escribe: buscarlo
             * por trozos daría coincidencias absurdas —cualquier código que
             * contenga «77»— así que va exacto y el resto va por parecido.
             */
            $consulta->where(function ($q) use ($texto) {
                $q->where('name', 'like', "%{$texto}%")
                    ->orWhere('brand', 'like', "%{$texto}%")
                    ->orWhere('barcode', $texto);
            });
        }

        $productos = $consulta->orderBy('name')->limit($limite)->get();

        $yaLosTiene = ProductBusiness::where('busines_id', $businessId)
            ->whereIn('products_id', $productos->pluck('products_id'))
            ->pluck('products_id')
            ->map(fn ($i) => (int) $i)
            ->all();

        return $productos
            ->map(fn ($p) => $this->comoFicha($p, in_array((int) $p->products_id, $yaLosTiene, true)))
            ->all();
    }

    /**
     * Un producto por su código de barras.
     *
     * Si no está en el catálogo propio se pregunta afuera. Eso no crea nada:
     * devuelve un borrador para que el tendero lo confirme, porque una fuente
     * ajena puede traer el nombre en otro idioma o de otra presentación, y
     * meterlo a ciegas ensucia el catálogo igual que el texto libre.
     */
    public function porCodigo(string $barcode, int $businessId): ?array
    {
        $barcode = preg_replace('/\D/', '', $barcode);

        if ($barcode === '') {
            return null;
        }

        $producto = Product::with('category')->where('barcode', $barcode)->first();

        if ($producto) {
            $loTiene = ProductBusiness::where('busines_id', $businessId)
                ->where('products_id', $producto->products_id)
                ->exists();

            return $this->comoFicha($producto, $loTiene);
        }

        $borrador = $this->fuente->buscar($barcode);

        if (!$borrador) {
            return null;
        }

        // `products_id` en null es la señal de que todavía no existe: el panel
        // enseña el borrador y ofrece proponerlo.
        return [
            'products_id' => null,
            'barcode'     => $barcode,
            'name'        => $borrador['name'],
            'brand'       => $borrador['brand'],
            'image'       => $borrador['image'],
            'description' => null,
            'category'    => null,
            'ya_lo_tienes' => false,
            'es_borrador' => true,
            'fuente'      => $borrador['fuente'],
        ];
    }

    /**
     * Crear un producto que no estaba en el catálogo.
     *
     * Entra directo, no a una cola de aprobación. Una cola que nadie atiende
     * deja al tendero esperando y termina con él llamando por teléfono; la
     * marca de origen permite revisarlo después sin bloquear la venta de hoy.
     */
    public function proponer(array $datos, string $origen = 'tendero'): Product
    {
        $barcode = isset($datos['barcode'])
            ? preg_replace('/\D/', '', (string) $datos['barcode'])
            : null;

        if ($barcode === '') {
            $barcode = null;
        }

        if ($barcode !== null) {
            $existente = Product::where('barcode', $barcode)->first();

            // Dos personas escaneando el mismo código en dos tiendas a la vez:
            // gana el que llegó primero y el segundo se lleva el mismo
            // producto, que es lo correcto.
            if ($existente) {
                return $existente;
            }
        }

        return Product::create([
            'name'        => trim($datos['name']),
            'barcode'     => $barcode,
            'brand'       => isset($datos['brand']) ? trim($datos['brand']) : null,
            'description' => $datos['description'] ?? null,
            'category_id' => $datos['category_id'] ?? null,
            'image'       => $datos['image'] ?? null,
            'state'       => 1,
            'origen'      => $origen,
        ]);
    }

    /**
     * Poner un producto del catálogo en una tienda.
     *
     * `updateOrCreate` y no `create`: agregar algo que ya se tiene es querer
     * corregirle el precio, no un error que merezca un rechazo. Antes esto lo
     * cuidaba un `exists()` en PHP, que con dos peticiones a la vez insertaba
     * dos veces; ahora lo garantiza el único de la base.
     */
    public function agregarATienda(int $businessId, int $productId, float $precio, int $cantidad): ProductBusiness
    {
        return ProductBusiness::updateOrCreate(
            ['busines_id' => $businessId, 'products_id' => $productId],
            ['price' => $precio, 'amount' => $cantidad],
        );
    }

    /**
     * Copiar el surtido de otra tienda.
     *
     * SIN LOS PRECIOS, a propósito. Se copia QUÉ vende, no A CUÁNTO: el precio
     * es lo único que de verdad distingue a una tienda de la de al lado, y
     * copiarlo hace que anuncie un número que no va a respetar.
     *
     * Llegan con precio en cero y cantidad en cero, o sea invisibles para el
     * comprador hasta que el tendero les ponga precio. Eso convierte la copia
     * en una lista de trabajo en vez de en un catálogo mentiroso.
     */
    public function copiarDe(int $destino, int $origen): array
    {
        if ($destino === $origen) {
            return ['copiados' => 0, 'ya_tenia' => 0];
        }

        $suyos = ProductBusiness::where('busines_id', $destino)
            ->pluck('products_id')
            ->map(fn ($i) => (int) $i)
            ->all();

        $delOtro = ProductBusiness::where('busines_id', $origen)
            ->pluck('products_id')
            ->map(fn ($i) => (int) $i)
            ->all();

        $nuevos = array_values(array_diff($delOtro, $suyos));

        if (!$nuevos) {
            return ['copiados' => 0, 'ya_tenia' => count(array_intersect($delOtro, $suyos))];
        }

        $filas = array_map(fn ($id) => [
            'busines_id'    => $destino,
            'products_id'   => $id,
            'price'         => 0,
            'amount'        => 0,
            'qualification' => 0,
        ], $nuevos);

        DB::table('products_business')->insert($filas);

        return [
            'copiados' => count($nuevos),
            'ya_tenia' => count(array_intersect($delOtro, $suyos)),
        ];
    }

    /**
     * Tiendas de las que tiene sentido copiar: del mismo tipo, con catálogo, y
     * que no sea ella misma.
     */
    public function tiendasParaCopiar(int $businessId): array
    {
        $tipo = DB::table('business')->where('busines_id', $businessId)->value('type');

        return DB::table('business as b')
            ->join('products_business as pb', 'pb.busines_id', '=', 'b.busines_id')
            ->where('b.busines_id', '!=', $businessId)
            ->where('b.state', 1)
            ->when($tipo !== null, fn ($q) => $q->where('b.type', $tipo))
            ->groupBy('b.busines_id', 'b.name')
            ->select('b.busines_id', 'b.name', DB::raw('COUNT(pb.products_id) as productos'))
            ->havingRaw('COUNT(pb.products_id) > 0')
            ->orderByDesc('productos')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'busines_id' => (int) $r->busines_id,
                'name'       => $r->name,
                'productos'  => (int) $r->productos,
            ])
            ->all();
    }

    private function comoFicha(Product $p, bool $loTiene): array
    {
        return [
            'products_id' => (int) $p->products_id,
            'barcode'     => $p->barcode,
            'name'        => $p->name,
            'brand'       => $p->brand,
            'description' => $p->description,
            'image'       => $p->image,
            'category'    => $p->category ? [
                'category_id' => (int) $p->category->category_id,
                'name'        => $p->category->name,
            ] : null,
            'ya_lo_tienes' => $loTiene,
            'es_borrador'  => false,
        ];
    }
}
