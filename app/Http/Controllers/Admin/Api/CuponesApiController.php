<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CuponRequest;
use App\Models\Marketing\Coupon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CuponesApiController extends Controller
{
    public function coupons(Request $request)
    {
        $q = Coupon::query()
            ->leftJoin('business as b', 'b.busines_id', '=', 'coupons.business_id')
            ->leftJoin('category as c', 'c.category_id', '=', 'coupons.category_id')
            ->orderByDesc('coupons.id');

        if ($buscar = trim((string) $request->query('search', ''))) {
            $q->where('coupons.code', 'like', '%'.strtoupper($buscar).'%');
        }

        return response()->json(
            $q->get([
                'coupons.*',
                'b.name as business_name',
                'c.name as category_name',
            ])
        );
    }

    public function storeCoupon(CuponRequest $request)
    {
        $c = Coupon::create($request->validated());

        return response()->json(['message' => 'Cupón creado.', 'id' => $c->id, 'code' => $c->code], 201);
    }

    public function updateCoupon(Request $request, $id)
    {
        $c = Coupon::findOr($id, fn () => abort(404, 'El cupón no existe.'));

        /*
         * EN LINEA Y NO EN UN FormRequest, a proposito: la busqueda de arriba
         * contesta 404 cuando el registro no existe, y eso tiene que ir ANTES
         * que un error de validacion. Las reglas no se repiten: se piden a
         * `CuponRequest` en modo parcial.
         */
        $c->update($request->validate(
            CuponRequest::reglas(parcial: true, ignorar: $c->id)
        ));

        return response()->json(['message' => 'Cupón actualizado.']);
    }

    public function deleteCoupon($id)
    {
        $c = Coupon::findOr($id, fn () => abort(404, 'El cupón no existe.'));

        // Un cupón ya canjeado es parte del historial de descuentos aplicados:
        // borrarlo dejaría pedidos con un descuento que no se puede explicar.
        if ($c->uses_count > 0) {
            return response()->json([
                'message' => "No se puede eliminar: ya se canjeó {$c->uses_count} vez(ces). Desactívalo en su lugar.",
            ], 422);
        }

        $c->delete();

        return response()->json(['message' => 'Cupón eliminado.']);
    }

    /** Canjes de un cupón, para ver quién lo usó. */
    public function couponRedemptions($id)
    {
        Coupon::findOr($id, fn () => abort(404, 'El cupón no existe.'));

        return response()->json(
            DB::table('coupon_redemptions as r')
                ->leftJoin('user as u', 'u.user_id', '=', 'r.user_id')
                ->where('r.coupon_id', $id)
                ->orderByDesc('r.id')
                ->limit(500)
                ->get([
                    'r.id', 'r.order_id', 'r.discount', 'r.created_at',
                    'u.user_id', 'u.name', 'u.email',
                ])
        );
    }

    /* ==================================================================
       NEGOCIOS DESTACADOS
       ================================================================== */
}
