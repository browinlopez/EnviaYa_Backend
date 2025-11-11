<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Order\OrdersSalesDetail;
use App\Models\Product\CarPartsProducts;
use App\Models\Product\GroceryProduct;
use App\Models\Product\PharmacyProduct;
use App\Models\Product\Product;
use App\Models\Product\ProductBusiness;
use App\Models\Product\RestaurantProducts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    // Listar todos los productos de un negocio
    public function index(Request $request)
    {
        $request->validate([
            'busines_id' => 'required|integer|exists:business,busines_id'
        ]);

        // Cargar productos con relaciones
        $business = Business::with([
            'products.category',   // categoría
            'products.grocery',    // datos de grocery
            'products.pharmacy'    // datos de farmacia
        ])->findOrFail($request->busines_id);

        $products = $business->products->map(function ($product) use ($business) {
            $extraData = null;

            if ($business->type == 1 && $product->grocery) { // Grocery
                $extraData = [
                    'brand'           => $product->grocery->brand,
                    'size'            => $product->grocery->size,
                    'expiration_date' => $product->grocery->expiration_date,
                ];
            } elseif ($business->type == 2 && $product->pharmacy) { // Pharmacy
                $extraData = [
                    'active_ingredient' => $product->pharmacy->active_ingredient,
                    'dosage'            => $product->pharmacy->dosage,
                    'presentation'      => $product->pharmacy->presentation,
                    'expiration_date'   => $product->pharmacy->expiration_date,
                ];
            }

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

    // Crear producto
    public function store(Request $request)
    {
        $request->validate([
            'name'          => 'required|string',
            'description'   => 'nullable|string',
            'category_id'   => 'required|integer',
            'price'         => 'required|numeric',
            'amount'        => 'required|integer|min:0',
            'business_id'   => 'required|integer|exists:business,busines_id',

            // Opcionales según tipo
            'brand'             => 'nullable|string',
            'size'              => 'nullable|string',
            'expiration_date'   => 'nullable|date',

            'active_ingredient' => 'nullable|string',
            'dosage'            => 'nullable|string',
            'presentation'      => 'nullable|string',

            // Restaurant
            'food_type'      => 'nullable|string',
            'portion_size'   => 'nullable|string',
            'is_vegan'       => 'nullable|boolean',
            'is_gluten_free' => 'nullable|boolean',
            'allergens'      => 'nullable|string',

            // Car parts
            'model'         => 'nullable|string',
            'year'          => 'nullable|integer',
            'oem_code'      => 'nullable|string',
            'compatibility' => 'nullable|string'
        ]);

        DB::beginTransaction();
        try {
            $business = Business::findOrFail($request->business_id);

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

            // Crear datos adicionales según el tipo de negocio
            switch ($business->type) {

                case 1: // Grocery
                    GroceryProduct::create([
                        'products_id'     => $product->products_id,
                        'brand'           => $request->brand,
                        'size'            => $request->size,
                        'expiration_date' => $request->expiration_date
                    ]);
                    break;

                case 2: // Pharmacy
                    PharmacyProduct::create([
                        'products_id'       => $product->products_id,
                        'active_ingredient' => $request->active_ingredient,
                        'dosage'            => $request->dosage,
                        'presentation'      => $request->presentation,
                        'expiration_date'   => $request->expiration_date
                    ]);
                    break;

                case 3: // Restaurant
                    RestaurantProducts::create([
                        'products_id'     => $product->products_id,
                        'food_type'       => $request->food_type,
                        'portion_size'    => $request->portion_size,
                        'is_vegan'        => $request->is_vegan ?? false,
                        'is_gluten_free'  => $request->is_gluten_free ?? false,
                        'allergens'       => $request->allergens
                    ]);
                    break;

                case 4: // Car Parts
                    CarPartsProducts::create([
                        'products_id'   => $product->products_id,
                        'brand'         => $request->brand,
                        'model'         => $request->model,
                        'year'          => $request->year,
                        'oem_code'      => $request->oem_code,
                        'compatibility' => $request->compatibility
                    ]);
                    break;
            }

            DB::commit();

            return response()->json([
                'message' => 'Producto creado correctamente',
                'product' => $product->load('grocery', 'pharmacy', 'restaurant', 'carPart')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al crear producto',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // Mostrar producto individual
    public function show(Request $request)
    {
        $request->validate([
            'products_id' => 'required|integer|exists:products,products_id'
        ]);

        $product = Product::with(['businesses', 'category', 'grocery', 'pharmacy'])
            ->findOrFail($request->products_id);

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
            'extra' => $businessType == 1
                ? $product->grocery
                : ($businessType == 2 ? $product->pharmacy : null)
        ]);
    }

    // Actualizar producto
    public function update(Request $request)
    {
        $request->validate([
            'products_id'   => 'required|integer|exists:products,products_id',
            'name'          => 'nullable|string',
            'description'   => 'nullable|string',
            'category_id'   => 'nullable|integer',
            'state'         => 'nullable|boolean',
            'price'         => 'nullable|numeric',
            'amount'        => 'nullable|integer|min:0',
            'qualification' => 'nullable|numeric|min:0|max:5'
        ]);

        DB::beginTransaction();

        try {
            $product = Product::with(['grocery', 'pharmacy'])->findOrFail($request->products_id);

            $product->update($request->only([
                'name',
                'description',
                'category_id',
                'state'
            ]));

            $pb = ProductBusiness::where('products_id', $product->products_id)
                ->where('busines_id', $request->user()->business_id)
                ->first();

            if ($pb) {
                $pb->update($request->only([
                    'price',
                    'amount',
                    'qualification'
                ]));
            }

            // Actualizar datos adicionales según tipo de negocio
            $businessType = $pb ? $pb->business->type : null;

            if ($businessType == 1 && $product->grocery) {
                $product->grocery->update($request->only([
                    'brand',
                    'size',
                    'expiration_date'
                ]));
            } elseif ($businessType == 2 && $product->pharmacy) {
                $product->pharmacy->update($request->only([
                    'active_ingredient',
                    'dosage',
                    'presentation',
                    'expiration_date'
                ]));
            }

            DB::commit();

            return response()->json([
                'message' => 'Producto actualizado correctamente',
                'product' => $product->load('businesses', 'category', 'grocery', 'pharmacy')
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

        $formatted = $products->map(function ($item) {
            return [
                'product_id' => $item->product->products_id,
                'name' => $item->product->name,
                'description' => $item->product->description,
                'category_id' => $item->product->category_id,
                'image' => $item->product->image,
                'state' => $item->product->state,
                'total_ordered' => (int) $item->total_ordered, // cantidad total pedida
            ];
        });

        return response()->json([
            'message' => 'Productos más populares del negocio',
            'products' => $formatted
        ]);
    }
}
