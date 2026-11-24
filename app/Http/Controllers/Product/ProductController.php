<?php

declare(strict_types=1);

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Product\ListarProductosRequest;
use App\Http\Resources\Producto\ProductoDelCatalogoPropioResource;
use App\Models\Business;
use App\Models\Product\Product;
use App\Models\Product\ProductBusiness;
use App\Services\NegocioDelUsuario;
use App\Support\ProductTypeSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    use ComprobarPertenencia;

    // Listar todos los productos de un negocio
    public function index(ListarProductosRequest $request)
    {

        /*
         * Un identificador en el cuerpo es una sugerencia, no una
         * credencial: son correlativos. Sin esto, con la cuenta de un
         * comprador se leian los pedidos de cualquier tienda.
         */
        if ($no = $this->negarNegocioAjeno($request, $request->business_id)) {
            return $no;
        }

        // Cargar productos con su categoría y los datos extra de todos los tipos
        $business = Business::with(array_merge(
            ['products.category', 'products.group'],
            array_map(fn ($r) => "products.$r", ProductTypeSchema::allRelations()),
        ))->findOrFail($request->business_id);

        $products = $business->products->map(function ($product) use ($business) {
            $extraData = ProductTypeSchema::has((int) $business->type)
                ? ProductTypeSchema::extraFor((int) $business->type, $product)
                : null;

            /*
             * `extra` y el tipo se le PASAN al Resource: el controlador
             * precarga de una vez las relaciones de todos los tipos de
             * negocio, y resolverlas dentro serian varias consultas por
             * producto.
             */
            return (new ProductoDelCatalogoPropioResource(
                $product, $business->type, $extraData,
            ))->resolve();
        });

        return response()->json($products);
    }

    // Crear producto
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'required|integer|exists:category,category_id',
            'price' => 'required|numeric|min:0',
            'amount' => 'required|integer|min:0',
            'business_id' => 'required|integer|exists:business,busines_id',

            /*
             * MARCA Y CÓDIGO DE BARRAS, QUE FALTABAN LOS DOS.
             *
             * La marca la pedía el esquema de TIENDA y se guardaba en
             * `grocery_products.brand`, al lado de `products.brand` que ya
             * existía. Como la carga masiva escribe en la segunda y el buscador
             * busca por la segunda, la marca tecleada en el formulario no se
             * podía encontrar. Al quitar el duplicado hay que pedirla acá, que
             * es donde se escribe el producto.
             *
             * Y el código de barras NO SE PEDÍA EN NINGÚN SITIO: un producto
             * dado de alta por este camino no se podía encontrar escaneándolo
             * nunca más. Único en todo el catálogo compartido, así que se
             * valida contra la tabla: dos tiendas no pueden reclamar el mismo.
             */
            'brand' => 'nullable|string|max:120',
            'barcode' => 'nullable|string|max:20|unique:products,barcode',
        ]);

        /*
         * QUE EL NEGOCIO SEA SUYO.
         *
         * Esta ruta la usa la app publicada, que manda `business_id` en el
         * cuerpo, y no habia nada que comprobara que ese numero fuera de
         * quien pregunta: con el identificador de otra tienda —que es
         * correlativo— se le podian meter productos a su catalogo.
         *
         * La puerta nueva (`POST /v1/negocio/productos`) ya no crea nada:
         * elige del catalogo maestro. Esta se queda porque las versiones de
         * la app que ya estan en los telefonos la siguen usando y apagarla
         * las deja sin poder cargar; pero con la pertenencia comprobada. Se
         * retira cuando la mayoria haya actualizado.
         */
        if (! app(NegocioDelUsuario::class)
            ->administra($request->user()?->user_id, (int) $request->business_id)) {
            return response()->json(['message' => 'Ese negocio no es tuyo.'], 403);
        }

        $business = Business::findOrFail($request->business_id);
        $type = (int) $business->type;

        if (! ProductTypeSchema::has($type)) {
            return response()->json([
                'message' => 'El negocio no tiene un tipo de producto configurado',
            ], 422);
        }

        $this->rejectForeignFields($request, $type);
        $this->assertCategoryMatchesType((int) $request->category_id, $type);

        // Campos extra con las reglas del tipo de negocio (los required
        // de este tipo sí se exigen; antes todo era nullable).
        $extra = $request->validate(ProductTypeSchema::rules($type));

        /*
         * Y los COMUNES, que viven en `products` y no en la tabla del tipo.
         * Opcionales los tres, asi que una app que no los mande sigue dando de
         * alta productos igual que antes.
         */
        $comunes = $request->validate(ProductTypeSchema::reglasComunes());

        DB::beginTransaction();
        try {
            // Crear producto base
            $product = Product::create(array_merge([
                'name' => $request->name,
                'description' => $request->description,
                'category_id' => $request->category_id,
                'brand' => $request->brand,
                'barcode' => $request->barcode ?: null,
                'state' => true,
            ], $comunes));

            // Relación con el negocio
            ProductBusiness::create([
                'busines_id' => $business->busines_id,
                'products_id' => $product->products_id,
                'price' => $request->price,
                'amount' => $request->amount,
                'qualification' => 0,
            ]);

            /*
             * Datos adicionales en la tabla del tipo, SI el tipo tiene.
             *
             * Tienda ya no tiene campos propios --los tres que tenia eran
             * comunes o duplicados-- asi que `fields` le queda vacio. Sin esta
             * comprobacion, dar de alta un producto en una tienda reventaria
             * con una fila de nulos en `grocery_products`.
             */
            $model = ProductTypeSchema::model($type);

            if ($model && $extra !== []) {
                $model::create(array_merge(
                    ['products_id' => $product->products_id],
                    $extra,
                ));
            }

            DB::commit();

            return response()->json([
                'message' => 'Producto creado correctamente',
                'product' => $product->load(ProductTypeSchema::allRelations()),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al crear producto',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Rechaza con 422 los campos que pertenecen a otros tipos de negocio,
     * en vez de ignorarlos en silencio (típico síntoma de un formulario
     * apuntando al tipo equivocado).
     */
    private function rejectForeignFields(Request $request, int $type): void
    {
        $foreign = array_filter(
            ProductTypeSchema::foreignFieldNames($type),
            fn (string $field) => $request->filled($field),
        );

        if ($foreign !== []) {
            throw ValidationException::withMessages(
                collect($foreign)->mapWithKeys(fn ($f) => [
                    $f => "El campo $f no aplica para este tipo de negocio.",
                ])->all(),
            );
        }
    }

    /**
     * La categoría del producto debe estar asignada al tipo del negocio.
     *
     * La asignación es de muchos a muchos: se comprueba contra
     * `category_category_business` y no contra la columna heredada, que solo
     * guarda la primera de las categorías de negocio elegidas.
     */
    private function assertCategoryMatchesType(int $categoryId, int $type): void
    {
        $pertenece = DB::table('category_category_business')
            ->where('category_id', $categoryId)
            ->where('business_category_id', $type)
            ->exists();

        if (! $pertenece) {
            throw ValidationException::withMessages([
                'category_id' => 'La categoría no pertenece al tipo de este negocio.',
            ]);
        }
    }
}
