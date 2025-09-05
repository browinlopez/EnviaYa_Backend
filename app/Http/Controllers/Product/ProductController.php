<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Models\Product\Product;
use App\Models\Product\ProductBusiness;
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

        $products = ProductBusiness::where('busines_id', $request->busines_id)
            ->with('product')
            ->get();

        $formattedProducts = $products->map(function ($item) {
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
            ];
        });

        return response()->json($formattedProducts);
    }


    // Crear producto
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
            'category_id' => 'required|integer',
            'price' => 'required|numeric',
            'amount' => 'required|integer|min:0', // 👈 validamos cantidad
            'business_id' => 'required|integer'
        ]);

        DB::beginTransaction();

        try {
            // Crear producto
            $product = Product::create([
                'name' => $request->name,
                'description' => $request->description,
                'category_id' => $request->category_id,
                'state' => true
            ]);

            // Asociar producto al negocio con precio y cantidad
            ProductBusiness::create([
                'busines_id' => $request->business_id,
                'products_id' => $product->products_id,
                'price' => $request->price,
                'amount' => $request->amount,
                'qualification' => 0 // 👈 opcional, si usas calificación por defecto
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Producto creado correctamente',
                'product' => $product
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al crear el producto',
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

        $product = Product::with('businesses', 'category')
            ->findOrFail($request->products_id);

        return response()->json([
            'product_id' => $product->products_id,
            'name' => $product->name,
            'description' => $product->description,
            'category' => $product->category ? [
                'category_id' => $product->category->category_id,
                'name' => $product->category->name,
            ] : null,
            'image' => $product->image,
            'state' => $product->state,
            'businesses' => $product->businesses->map(function ($business) {
                return [
                    'business_id' => $business->busines_id,
                    'name' => $business->name,
                    'phone' => $business->phone,
                    'address' => $business->address,
                    'qualification' => $business->qualification,
                    'razon_social' => $business->razonSocial_DCD,
                    'NIT' => $business->NIT,
                    'logo' => $business->logo,
                    'city' => $business->city,
                    'state' => $business->state,
                ];
            }),
        ]);
    }

    // Actualizar producto
    public function update(Request $request)
    {
        $request->validate([
            'products_id' => 'required|integer|exists:products,products_id',
            'name' => 'nullable|string',
            'description' => 'nullable|string',
            'category_id' => 'nullable|integer',
            'state' => 'nullable|boolean',
            'price' => 'nullable|numeric',
            'amount' => 'nullable|integer|min:0',
            'qualification' => 'nullable|numeric|min:0|max:5'
        ]);

        DB::beginTransaction();

        try {
            $product = Product::findOrFail($request->products_id);

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

            DB::commit();

            return response()->json([
                'message' => 'Producto actualizado correctamente',
                'product' => $product->load('businesses', 'category')
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
}
