<?php

namespace App\Http\Controllers\Admin\Api;

use App\Services\Push\Notificador;
use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\Marketing\AdCampaign;
use App\Models\Marketing\Advertiser;
use App\Models\Marketing\Banner;
use App\Models\Marketing\Coupon;
use App\Models\Marketing\FeaturedBusiness;
use App\Models\Marketing\PushCampaign;
use App\Services\MediaService;
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
            $q->where('coupons.code', 'like', '%' . strtoupper($buscar) . '%');
        }

        return response()->json(
            $q->get([
                'coupons.*',
                'b.name as business_name',
                'c.name as category_name',
            ])
        );
    }

    public function storeCoupon(Request $request)
    {
        $c = Coupon::create($this->validarCupon($request));

        return response()->json(['message' => 'Cupón creado.', 'id' => $c->id, 'code' => $c->code], 201);
    }

    public function updateCoupon(Request $request, $id)
    {
        $c = Coupon::findOr($id, fn () => abort(404, 'El cupón no existe.'));

        $c->update($this->validarCupon($request, true, $c->id));

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

    private function validarCupon(Request $request, bool $parcial = false, ?int $ignorar = null): array
    {
        $regla = $parcial ? 'sometimes' : 'required';

        $unico = 'unique:coupons,code' . ($ignorar ? ",{$ignorar}" : '');

        return $request->validate([
            'code'              => "{$regla}|string|max:40|{$unico}",
            'description'       => 'sometimes|nullable|string|max:255',
            'type'              => 'sometimes|in:percent,fixed,free_shipping',
            'value'             => "{$regla}|numeric|min:0",
            'max_discount'      => 'sometimes|nullable|numeric|min:0',
            'min_order'         => 'sometimes|numeric|min:0',
            'max_uses'          => 'sometimes|nullable|integer|min:1',
            'max_uses_per_user' => 'sometimes|nullable|integer|min:1',
            'business_id'       => 'sometimes|nullable|integer|exists:business,busines_id',
            'category_id'       => 'sometimes|nullable|integer|exists:category,category_id',
            'advertiser_id'     => 'sometimes|nullable|integer|exists:advertisers,id',
            'starts_at'         => "{$regla}|date",
            'ends_at'           => "{$regla}|date|after_or_equal:starts_at",
            'state'             => 'sometimes|boolean',
        ]);
    }

    /* ==================================================================
       NEGOCIOS DESTACADOS
       ================================================================== */
}
