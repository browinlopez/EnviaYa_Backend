<?php

namespace App\Services;

use App\Models\Marketing\Coupon;

/**
 * CUÁNTO SE LE REBAJA AL CLIENTE DE LA TARIFA DE DOMICILIO.
 *
 * Este servicio existe para tener UN SOLO SITIO donde se decida eso. Hoy
 * responde por una sola razón —un cupón de envío gratis— pero es el punto
 * donde entran, sin volver a tocar `OrderController::store`:
 *
 *   · La escala de los primeros pedidos (5 gratis, 5 al 50 %, luego completo).
 *     El único dato que le falta es cuántos pedidos entregados lleva el
 *     comprador, que ya se puede contar sobre `orderssales`.
 *   · El canje de puntos por domicilio.
 *   · Promociones por conjunto, por zona o por franja horaria.
 *
 * LO QUE NO DECIDE, Y ES LA PARTE IMPORTANTE: lo que gana el domiciliario.
 *
 * Antes `domiciliary_fee` salía de lo que pagaba el cliente, así que un
 * domicilio gratis habría significado un viaje gratis para quien lo hace. La
 * rebaja se descuenta de la parte de la plataforma, no del repartidor: él
 * cobra sobre la tarifa base pase lo que pase. Ver `OrderController::store`.
 */
class PoliticaDeDomicilio
{
    /**
     * La rebaja sobre la tarifa base, en pesos. Nunca mayor que la tarifa
     * —un domicilio no puede costar menos que gratis— ni negativa.
     *
     * @param  Coupon|null  $cupon  El ya resuelto por CouponService, si lo hay.
     */
    public function rebajaPara(float $tarifaBase, ?Coupon $cupon = null): float
    {
        if ($tarifaBase <= 0) {
            // Pedido para recoger en tienda: no hay domicilio que rebajar.
            return 0.0;
        }

        $rebaja = 0.0;

        if ($cupon && $cupon->type === Coupon::TIPO_ENVIO_GRATIS) {
            $rebaja = $tarifaBase;
        }

        return round(max(0.0, min($rebaja, $tarifaBase)), 2);
    }
}
