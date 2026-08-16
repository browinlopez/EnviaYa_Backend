<?php

namespace App\Services;

use App\Models\Marketing\Coupon;
use App\Models\Marketing\CouponRedemption;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Aplicación de cupones al confirmar un pedido.
 *
 * Está separado del controlador de anuncios porque son dos momentos distintos:
 * `AdsController::validateCoupon` solo dice cuánto valdría el cupón mientras el
 * usuario mira el carrito, y esto lo CONSUME. Mezclarlos haría que revisar el
 * carrito gastara usos, que es el error clásico de este tipo de promoción.
 */
class CouponService
{
    /**
     * Resuelve un código y devuelve el cupón aplicable, o null si no lo hay.
     *
     * Lanza RuntimeException con un mensaje para el usuario cuando el cupón
     * existe pero no procede: es información que quien pide merece ver, y
     * devolver null en ese caso haría que el pedido pasara en silencio sin el
     * descuento que la pantalla anterior le prometió.
     */
    public function resolver(
        ?string $codigo,
        float $subtotal,
        int $usuarioId,
        ?int $negocioId = null,
    ): ?Coupon {
        $codigo = strtoupper(trim((string) $codigo));

        if ($codigo === '') {
            return null;
        }

        $cupon = Coupon::vigente()->where('code', $codigo)->first();

        if (!$cupon) {
            throw new RuntimeException('El cupón no existe o ya no está vigente.');
        }

        if ($cupon->agotado()) {
            throw new RuntimeException('Este cupón ya alcanzó su número máximo de usos.');
        }

        if ($subtotal < (float) $cupon->min_order) {
            throw new RuntimeException(
                'Este cupón aplica desde $' . number_format((float) $cupon->min_order, 0, ',', '.') . '.'
            );
        }

        if ($cupon->business_id && (int) $cupon->business_id !== (int) $negocioId) {
            throw new RuntimeException('Este cupón solo aplica en otro negocio.');
        }

        if ($cupon->max_uses_per_user) {
            $usados = CouponRedemption::where('coupon_id', $cupon->id)
                ->where('user_id', $usuarioId)
                ->count();

            if ($usados >= $cupon->max_uses_per_user) {
                throw new RuntimeException('Ya usaste este cupón el número máximo de veces.');
            }
        }

        return $cupon;
    }

    /**
     * Consume un uso del cupón y deja constancia del canje.
     *
     * Debe llamarse DENTRO de la transacción del pedido: si la orden falla
     * después, el uso tiene que devolverse solo.
     *
     * El cupo se vuelve a comprobar acá con un UPDATE condicional en vez de
     * confiar en la validación previa. Entre que se valida y que se guarda pasa
     * tiempo, y con una promoción que funciona hay varias personas canjeando el
     * último uso a la vez: solo la fila que el motor deje pasar cuenta, y las
     * demás se enteran de que llegaron tarde.
     */
    public function canjear(Coupon $cupon, int $usuarioId, int $ordenId, float $descuento): void
    {
        $consumido = DB::table('coupons')
            ->where('id', $cupon->id)
            ->when(
                $cupon->max_uses !== null,
                fn ($q) => $q->whereRaw('uses_count < max_uses'),
            )
            ->increment('uses_count');

        if (!$consumido) {
            throw new RuntimeException('Este cupón ya alcanzó su número máximo de usos.');
        }

        CouponRedemption::create([
            'coupon_id' => $cupon->id,
            'user_id'   => $usuarioId,
            'order_id'  => $ordenId,
            'discount'  => $descuento,
        ]);
    }
}
