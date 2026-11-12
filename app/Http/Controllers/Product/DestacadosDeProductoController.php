<?php

declare(strict_types=1);

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Product\MasVendidosDelNegocioRequest;
use App\Http\Resources\Producto\ProductoMasVendidoResource;
use App\Models\Order\OrdersSalesDetail;
use App\Models\Product\ProductBusiness;

class DestacadosDeProductoController extends Controller
{
    use ComprobarPertenencia;

    public function mostPopularProducts(MasVendidosDelNegocioRequest $request)
    {

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

        $formatted = $products->map(fn ($item) => (new ProductoMasVendidoResource(
            $item, (float) ($prices[$item->product_id] ?? 0),
        ))->resolve());

        return response()->json([
            'message' => 'Productos más populares del negocio',
            'products' => $formatted,
        ]);
    }
}
