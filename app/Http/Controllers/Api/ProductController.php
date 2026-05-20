<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\OrderSaleDetail;
use App\Models\CarPartsProducts;
use App\Models\GroceryProduct;
use App\Models\PharmacyProduct;
use App\Models\Product;
use App\Models\ProductBusiness;
use App\Models\RestaurantProducts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    // Listar todos los productos de un negocio
    public function index(Request $request)
    {
        $request->validate([
            'business_id' => 'required|integer|exists:business,id'
        ]);

        // Cargar productos con relaciones
        $business = Business::with([
            'products.category',   // categoría
            'products.grocery',    // datos de grocery
            'products.pharmacy'    // datos de farmacia
        ])->findOrFail($request->business_id);


        $products = $business->products->map(function ($product) use ($business) {
            $extraData = null;

            if ($business->type == 1 && $product->grocery) { // Grocery
                $extraData = [
                    'brand' => $product->grocery->brand,
                    'size' => $product->grocery->size,
                    'expiration_date' => $product->grocery->expiration_date,
                ];
            } elseif ($business->type == 2 && $product->pharmacy) { // Pharmacy
                $extraData = [
                    'active_ingredient' => $product->pharmacy->active_ingredient,
                    'dosage' => $product->pharmacy->dosage,
                    'presentation' => $product->pharmacy->presentation,
                    'expiration_date' => $product->pharmacy->expiration_date,
                ];
            }

            return [
                'product_id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'category' => $product->category ? [
                    'category_id' => $product->category->id,
                    'name' => $product->category->name,
                ] : null,
                'image' => $product->image,
                'state' => $product->state,
                'price' => $product->pivot->price,
                'quantity' => $product->pivot->quantity,
                'qualification' => $product->pivot->qualification,
                'business_type' => $business->type,
                'extra' => $extraData, // datos extra según tipo
            ];
        });

        return response()->json($products);
    }

    // Crear producto
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
            'category_id' => 'required|integer',
            'price' => 'required|numeric',
            'quantity' => 'required|integer|min:0',
            'business_id' => 'required|integer|exists:business,id',

            // Opcionales según tipo
            'brand' => 'nullable|string',
            'size' => 'nullable|string',
            'expiration_date' => 'nullable|date',

            'active_ingredient' => 'nullable|string',
            'dosage' => 'nullable|string',
            'presentation' => 'nullable|string',

            // Restaurant
            'food_type' => 'nullable|string',
            'portion_size' => 'nullable|string',
            'is_vegan' => 'nullable|boolean',
            'is_gluten_free' => 'nullable|boolean',
            'allergens' => 'nullable|string',

            // Car parts
            'model' => 'nullable|string',
            'year' => 'nullable|integer',
            'oem_code' => 'nullable|string',
            'compatibility' => 'nullable|string'
        ]);

        DB::beginTransaction();
        try {
            $business = Business::findOrFail($request->business_id);

            // Crear producto base
            $product = Product::create([
                'name' => $request->name,
                'description' => $request->description,
                'category_id' => $request->category_id,
                'state' => true
            ]);

            // Relación con el negocio (tabla pivote product_businesses)
            ProductBusiness::create([
                'busines_id' => $business->id,
                'products_id' => $product->id,
                'price' => $request->price,
                'quantity' => $request->quantity,
                'qualification' => 0
            ]);

            // Crear datos adicionales según el tipo de negocio
            switch ($business->type) {

                case 1: // Grocery
                    GroceryProduct::create([
                        'products_id' => $product->id,
                        'brand' => $request->brand,
                        'size' => $request->size,
                        'expiration_date' => $request->expiration_date
                    ]);
                    break;

                case 2: // Pharmacy
                    PharmacyProduct::create([
                        'products_id' => $product->id,
                        'active_ingredient' => $request->active_ingredient,
                        'dosage' => $request->dosage,
                        'presentation' => $request->presentation,
                        'expiration_date' => $request->expiration_date
                    ]);
                    break;

                case 3: // Restaurant
                    RestaurantProducts::create([
                        'products_id' => $product->id,
                        'food_type' => $request->food_type,
                        'portion_size' => $request->portion_size,
                        'is_vegan' => $request->is_vegan ?? false,
                        'is_gluten_free' => $request->is_gluten_free ?? false,
                        'allergens' => $request->allergens
                    ]);
                    break;

                case 4: // Car Parts
                    CarPartsProducts::create([
                        'products_id' => $product->id,
                        'brand' => $request->brand,
                        'model' => $request->model,
                        'year' => $request->year,
                        'oem_code' => $request->oem_code,
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
            'product_id' => 'required|integer|exists:products,id'
        ]);

        $product = Product::with(['businesses', 'category', 'grocery', 'pharmacy'])
            ->findOrFail($request->product_id);

        $businessType = $product->businesses->first()->category_business_id ?? null;

        return response()->json([
            'product_id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'category' => $product->category ? [
                'category_id' => $product->category->id,
                'name' => $product->category->name,
            ] : null,
            'image' => $product->image,
            'state' => $product->state,
            'businesses' => $product->businesses->map(function ($business) {
                return [
                    'business_id' => $business->id,
                    'name' => $business->name,
                    'phone' => $business->phone,
                    'address' => $business->address,
                    'qualification' => $business->qualification,
                    'razon_social' => $business->legal_name,
                    'identification_number' => $business->identification_number,
                    'logo' => $business->logo,
                    'state' => $business->state,
                    'category_business_id' => $business->category_business_id,
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
            'product_id' => 'required|integer|exists:products,id',
            'business_id' => 'required|integer|exists:business,id',
            'name' => 'nullable|string',
            'description' => 'nullable|string',
            'category_id' => 'nullable|integer',
            'state' => 'nullable|boolean',
            'price' => 'nullable|numeric',
            'quantity' => 'nullable|integer|min:0',
            'qualification' => 'nullable|numeric|min:0|max:5'
        ]);

        DB::beginTransaction();

        try {
            $product = Product::with(['grocery', 'pharmacy'])->findOrFail($request->product_id);

            $product->update($request->only([
                'name',
                'description',
                'category_id',
                'state'
            ]));

            $pb = ProductBusiness::where('products_id', $product->id)
                ->where('busines_id', $request->business_id)
                ->first();

            if ($pb) {
                $pb->update($request->only([
                    'price',
                    'quantity',
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
                'error' => $e->getMessage()
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
                'product_id' => $item->product->id,
                'name' => $item->product->name,
                'description' => $item->product->description,
                'category_id' => $item->product->category_id,
                'image' => $item->product->image,
                'state' => $item->product->state,
                'price' => $item->price,
                'quantity' => $item->quantity,
                'qualification' => $item->qualification,
                'business_id' => $item->busines_id,
            ];
        });

        return response()->json($formatted);
    }

    public function mostPopularProducts(Request $request)
    {
        $request->validate([
            'business_id' => 'required|integer|exists:business,id',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $limit = $request->get('limit', 10);

        $products = OrderSaleDetail::selectRaw('product_id, SUM(quantity) as total_ordered')
            ->whereHas('order', function ($query) use ($request) {
                $query->where('busines_id', $request->business_id);
            })
            ->with('product')
            ->groupBy('product_id')
            ->orderByDesc('total_ordered')
            ->take($limit)
            ->get();

        $formatted = $products->map(function ($item) {
            return [
                'product_id' => $item->product->id,
                'name' => $item->product->name,
                'description' => $item->product->description,
                'category_id' => $item->product->category_id,
                'image' => $item->product->image,
                'state' => $item->product->state,
                'total_ordered' => (int) $item->total_ordered,
            ];
        });

        return response()->json([
            'message' => 'Productos más populares del negocio',
            'products' => $formatted
        ]);
    }
}
