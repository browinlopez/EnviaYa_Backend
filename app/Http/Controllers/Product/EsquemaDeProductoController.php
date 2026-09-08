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

class EsquemaDeProductoController extends Controller
{
    use ComprobarPertenencia;

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
}
