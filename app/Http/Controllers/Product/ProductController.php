<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Order\OrdersSalesDetail;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\Product\ProductBusiness;
use App\Support\ProductTypeSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    use ComprobarPertenencia;

    // Listar todos los productos de un negocio
    public function index(Request $request)
    {
        $request->validate([
            'business_id' => 'required|integer|exists:business,busines_id'
        ]);

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
            ['products.category'],
            array_map(fn ($r) => "products.$r", ProductTypeSchema::allRelations()),
        ))->findOrFail($request->business_id);

        $products = $business->products->map(function ($product) use ($business) {
            $extraData = ProductTypeSchema::has((int) $business->type)
                ? ProductTypeSchema::extraFor((int) $business->type, $product)
                : null;

            return [
                'product_id'    => $product->products_id,
                'name'          => $product->name,
                'description'   => $product->description,
                'category'      => $product->category ? [
                    'category_id' => $product->category->category_id,
                    'name'        => $product->category->name,
                ] : null,
                'image'         => $product->image,
                'state'         => $product->state,
                'price'         => $product->pivot->price,
                'amount'        => $product->pivot->amount,
                'qualification' => $product->pivot->qualification,
                'business_type' => $business->type,
                'extra'         => $extraData, // datos extra según tipo
            ];
        });

        return response()->json($products);
    }

    /**
     * Describe el formulario de producto para el tipo del negocio dado:
     * campos extra (etiqueta, tipo de input, obligatoriedad) y categorías
     * válidas. La app arma el formulario con esto en vez de hardcodearlo.
     */
    public function schema(Request $request)
    {
        $request->validate([
            'business_id' => 'required|integer|exists:business,busines_id',
        ]);

        $business = Business::findOrFail($request->business_id);
        $type = (int) $business->type;

        if (!ProductTypeSchema::has($type)) {
            return response()->json([
                'message' => 'El negocio no tiene un tipo de producto configurado',
            ], 422);
        }

        return response()->json(array_merge(
            ProductTypeSchema::forApi($type),
            [
                // Una categoría puede servir a varios tipos de negocio, así que
                // el filtro va contra la tabla de vínculos, no contra la columna.
                'categories' => Category::active()
                    ->whereIn(
                        'category_id',
                        DB::table('category_category_business')
                            ->where('business_category_id', $type)
                            ->pluck('category_id')
                    )
                    ->get(['category_id', 'name']),
            ],
        ));
    }

    // Crear producto
    public function store(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'required|integer|exists:category,category_id',
            'price'       => 'required|numeric|min:0',
            'amount'      => 'required|integer|min:0',
            'business_id' => 'required|integer|exists:business,busines_id',
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
        if (!app(\App\Services\NegocioDelUsuario::class)
            ->administra($request->user()?->user_id, (int) $request->business_id)) {
            return response()->json(['message' => 'Ese negocio no es tuyo.'], 403);
        }

        $business = Business::findOrFail($request->business_id);
        $type = (int) $business->type;

        if (!ProductTypeSchema::has($type)) {
            return response()->json([
                'message' => 'El negocio no tiene un tipo de producto configurado',
            ], 422);
        }

        $this->rejectForeignFields($request, $type);
        $this->assertCategoryMatchesType((int) $request->category_id, $type);

        // Campos extra con las reglas del tipo de negocio (los required
        // de este tipo sí se exigen; antes todo era nullable).
        $extra = $request->validate(ProductTypeSchema::rules($type));

        DB::beginTransaction();
        try {
            // Crear producto base
            $product = Product::create([
                'name'        => $request->name,
                'description' => $request->description,
                'category_id' => $request->category_id,
                'state'       => true
            ]);

            // Relación con el negocio
            ProductBusiness::create([
                'busines_id'   => $business->busines_id,
                'products_id'  => $product->products_id,
                'price'        => $request->price,
                'amount'       => $request->amount,
                'qualification' => 0
            ]);

            // Datos adicionales en la tabla del tipo correspondiente
            $model = ProductTypeSchema::model($type);
            $model::create(array_merge(
                ['products_id' => $product->products_id],
                $extra,
            ));

            DB::commit();

            return response()->json([
                'message' => 'Producto creado correctamente',
                'product' => $product->load(ProductTypeSchema::allRelations())
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al crear producto',
                'error' => $e->getMessage()
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

        if (!$pertenece) {
            throw ValidationException::withMessages([
                'category_id' => 'La categoría no pertenece al tipo de este negocio.',
            ]);
        }
    }

    // Mostrar producto individual
    public function show(Request $request)
    {
        $request->validate([
            'products_id' => 'required|integer|exists:products,products_id'
        ]);

        $product = Product::with(array_merge(
            ['businesses', 'category'],
            ProductTypeSchema::allRelations(),
        ))->findOrFail($request->products_id);

        $businessType = $product->businesses->first()->type ?? null;

        return response()->json([
            'product_id'   => $product->products_id,
            'name'         => $product->name,
            'description'  => $product->description,
            'category'     => $product->category ? [
                'category_id' => $product->category->category_id,
                'name'        => $product->category->name,
            ] : null,
            'image'        => $product->image,
            'state'        => $product->state,
            'businesses'   => $product->businesses->map(function ($business) {
                return [
                    'business_id'   => $business->busines_id,
                    'name'          => $business->name,
                    'phone'         => $business->phone,
                    'address'       => $business->address,
                    'qualification' => $business->qualification,
                    'razon_social'  => $business->razonSocial_DCD,
                    'NIT'           => $business->NIT,
                    'logo'          => $business->logo,
                    'city'          => $business->city,
                    'state'         => $business->state,
                    'type'          => $business->type,
                ];
            }),
            'extra' => ProductTypeSchema::has((int) $businessType)
                ? ProductTypeSchema::extraFor((int) $businessType, $product)
                : null,
        ]);
    }

    // Actualizar producto
    public function update(Request $request)
    {
        $request->validate([
            'products_id'   => 'required|integer|exists:products,products_id',
            'business_id'   => 'nullable|integer|exists:business,busines_id',
            'name'          => 'nullable|string|max:255',
            'description'   => 'nullable|string',
            'category_id'   => 'nullable|integer|exists:category,category_id',
            'state'         => 'nullable|boolean',
            'price'         => 'nullable|numeric|min:0',
            'amount'        => 'nullable|integer|min:0',
            'qualification' => 'nullable|numeric|min:0|max:5'
        ]);

        $product = Product::with(ProductTypeSchema::allRelations())
            ->findOrFail($request->products_id);

        // El negocio puede venir en el request; si no, se resuelve por el
        // dueño autenticado. (Antes se leía user->business_id, columna que
        // no existe, y el precio/stock nunca se actualizaba.)
        $businessId = $request->business_id
            ?? $request->user()?->owner?->businesses()->first()?->busines_id;

        $pb = $businessId
            ? ProductBusiness::with('business')
                ->where('products_id', $product->products_id)
                ->where('busines_id', $businessId)
                ->first()
            : null;

        $businessType = $pb ? (int) $pb->business->type : null;

        $extra = [];
        if ($businessType !== null && ProductTypeSchema::has($businessType)) {
            $this->rejectForeignFields($request, $businessType);

            if ($request->filled('category_id')) {
                $this->assertCategoryMatchesType((int) $request->category_id, $businessType);
            }

            // Reglas del tipo en modo parcial: valida formato de lo que
            // venga sin exigir los campos required.
            $extra = $request->validate(ProductTypeSchema::updateRules($businessType));
        }

        DB::beginTransaction();

        try {
            $product->update($request->only([
                'name',
                'description',
                'category_id',
                'state'
            ]));

            if ($pb) {
                $pb->update($request->only([
                    'price',
                    'amount',
                    'qualification'
                ]));
            }

            // Actualizar datos adicionales del tipo correspondiente
            if ($businessType !== null && ProductTypeSchema::has($businessType) && $extra !== []) {
                $relation = ProductTypeSchema::relation($businessType);

                if ($product->{$relation}) {
                    $product->{$relation}->update($extra);
                } else {
                    // Producto viejo sin fila de subtipo: se crea al editar.
                    $model = ProductTypeSchema::model($businessType);
                    $model::create(array_merge(
                        ['products_id' => $product->products_id],
                        $extra,
                    ));
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Producto actualizado correctamente',
                'product' => $product->load(array_merge(
                    ['businesses', 'category'],
                    ProductTypeSchema::allRelations(),
                ))
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al actualizar el producto',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    // Top 10 productos mejor calificados
    public function topRated()
    {
        $products = ProductBusiness::with('product')
            ->orderBy('qualification', 'desc')
            ->take(10)
            ->get();

        $formatted = $products->map(function ($item) {
            return [
                'product_id' => $item->product->products_id,
                'name' => $item->product->name,
                'description' => $item->product->description,
                'category_id' => $item->product->category_id,
                'image' => $item->product->image,
                'state' => $item->product->state,
                'price' => $item->price,
                'amount' => $item->amount,
                'qualification' => $item->qualification,
                'business_id' => $item->busines_id,
            ];
        });

        return response()->json($formatted);
    }

    public function mostPopularProducts(Request $request)
    {
        $request->validate([
            'business_id' => 'required|integer|exists:business,busines_id',
            'limit' => 'nullable|integer|min:1|max:50', // opcional para top N
        ]);

        $limit = $request->get('limit', 10); // por defecto top 10

        $products = OrdersSalesDetail::selectRaw('product_id, SUM(amount) as total_ordered')
            ->whereHas('order', function ($query) use ($request) {
                $query->where('busines_id', $request->business_id);
            })
            ->with('product') // para traer datos del producto
            ->groupBy('product_id')
            ->orderByDesc('total_ordered')
            ->take($limit)
            ->get();

        // Precio actual de cada producto en este negocio
        $prices = ProductBusiness::where('busines_id', $request->business_id)
            ->whereIn('products_id', $products->pluck('product_id'))
            ->pluck('price', 'products_id');

        $formatted = $products->map(function ($item) use ($prices) {
            return [
                'product_id' => $item->product->products_id,
                'name' => $item->product->name,
                'description' => $item->product->description,
                'category_id' => $item->product->category_id,
                'image' => $item->product->image,
                'state' => $item->product->state,
                'price' => (float) ($prices[$item->product_id] ?? 0),
                'total_ordered' => (int) $item->total_ordered, // cantidad total pedida
            ];
        });

        return response()->json([
            'message' => 'Productos más populares del negocio',
            'products' => $formatted
        ]);
    }
}
