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

class DestacadosDeProductoController extends Controller
{
    use ComprobarPertenencia;

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
