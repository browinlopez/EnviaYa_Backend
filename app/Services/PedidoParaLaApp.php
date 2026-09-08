<?php

namespace App\Services;

use App\Models\Order\OrdersSales;

/**
 * Un pedido, tal como lo espera la aplicacion movil.
 *
 * Estaba escrito dos veces dentro de `ConsultaDePedidosController` y hacia
 * falta una tercera para la lista del domiciliario. Son cien lineas de
 * nombres de campo: la tercera copia habria empezado a divergir el primer
 * dia que alguien anadiera algo a una sola.
 *
 * `conDatosDelComprador` es lo unico que cambia entre quien pregunta:
 *
 *   - La tienda dueña del pedido y el domiciliario ASIGNADO llegan a la
 *     puerta, asi que reciben nombre, telefono y direccion.
 *   - Un domiciliario mirando la lista de pedidos POR TOMAR todavia no
 *     tiene nada que ver con esa persona. Ve el pedido —negocio, importe,
 *     que gana— y nada de quien lo pidio. Cuando lo toma, ya le llega.
 *
 * Esa distincion es la razon de que exista esta clase: sin ella, dar de
 * comer a la pantalla del domiciliario significaba abrirle la lista entera
 * de la tienda, con los datos de cada comprador dentro.
 */
class PedidoParaLaApp
{
    public function formatear(OrdersSales $order, bool $conDatosDelComprador = true): array
    {
        return [
            'order_id' => $order->orderSales_id,
            'buyer_id' => $order->buyer_id,
            'busines_id' => $order->busines_id,
            'total' => $order->total,
            // Desglose, para que cada rol sepa qué parte le corresponde:
            // el negocio cobra el subtotal y el domiciliario el domicilio.
            'subtotal' => $order->subtotal,
            'domicilio' => $order->domicilio,
            'domiciliary_fee' => $order->domiciliary_fee,
            // El domiciliario necesita saber si cobra en la puerta o
            // si el pedido ya viene pagado en línea.
            'methods_id' => $order->methods_id,
            'payment_state' => $order->payment_state,
            'sale_date' => $order->sale_date,
            'is_scheduled' => $order->is_scheduled,
            'delivery_date' => $order->delivery_date,
            'delivery_type' => $order->pickup ? 'pickup' : 'delivery',
            'pickup' => (bool) $order->pickup,
            'pickup_time' => $order->pickup_time,
            'dispatched_at' => $order->dispatched_at,
            'promised_minutes' => $order->promised_minutes,
            'delivery_minutes' => $order->delivery_minutes,
            'on_time' => $order->on_time,
            'delay_minutes' => $order->delay_minutes,
            'state' => $order->state,
            // Los datos de la persona solo viajan cuando quien pregunta ya
        // tiene que llegar a su puerta. Ver la cabecera de la clase.
        'buyer' => ($conDatosDelComprador && $order->buyer) ? [
                'buyer_id' => $order->buyer->buyer_id,
                'qualification' => $order->buyer->qualification,
                'state' => (bool) $order->buyer->state,
                'user' => [
                    'user_id' => $order->buyer->user->user_id,
                    'name' => $order->buyer->user->name,
                    'email' => $order->buyer->user->email,
                    'phone' => $order->buyer->user->phone,
                    'address' => $order->buyer->user->address,
                    'rol' => $order->buyer->user->rol,
                    'qualification' => $order->buyer->user->qualification,
                    'state' => (bool) $order->buyer->user->state,
                ]
            ] : null,
            'business' => [
                'business_id' => $order->business->busines_id,
                'name' => $order->business->name,
                'address' => $order->business->address,
                'latitude' => $order->business->latitude !== null ? (float)$order->business->latitude : null,
                'longitude' => $order->business->longitude !== null ? (float)$order->business->longitude : null,
                'phone' => $order->business->phone,
                'city' => $order->business->city,
                'state' => $order->business->state,
                'logo' => $order->business->logo,
            ],
            'delivery_address' => ($conDatosDelComprador && !$order->pickup && $order->address) ? [
                'address_id' => $order->address->address_id,
                /*
                 * La calle va JUNTO a la dirección del mapa, no en vez de
                 * ella.
                 *
                 * La del mapa sitúa la cuadra; la que escribió la persona
                 * lleva el número de casa o apartamento. El domiciliario
                 * necesita las dos para llegar a la puerta, y hasta ahora
                 * solo le llegaba la primera.
                 */
                'address' => trim(implode(', ', array_filter([
                    $order->address->street,
                    $order->address->address,
                ]))),
                'street' => $order->address->street,
                'alias' => $order->address->alias?->name,
                'municipality' => $order->address->municipality?->name,
                'department' => $order->address->department?->name,
                'country' => $order->address->country?->name,
                'latitude' => $order->address->latitude !== null ? (float)$order->address->latitude : null,
                'longitude' => $order->address->longitude !== null ? (float)$order->address->longitude : null,
            ] : null,
            'domiciliary' => $order->domiciliary ? [
                'name' => $order->domiciliary->user->name,
                'email' => $order->domiciliary->user->email,
                'phone' => $order->domiciliary->user->phone,
                'domiciliary_id' => $order->domiciliary->domiciliary_id,
                'available' => $order->domiciliary->available,
                'qualification' => $order->domiciliary->qualification,
                'state' => $order->domiciliary->state,
                'user_id' => $order->domiciliary->user->user_id,
            ] : null,
            'details' => $order->details->map(function ($detail) {
                return [
                    'product_id' => $detail->product->products_id,
                    'name' => $detail->product->name,
                    'description' => $detail->product->description,
                    'category' => $detail->product->category?->name,
                    'image' => $detail->product->image,
                    'amount' => $detail->amount,
                    'unit_price' => $detail->unit_price,
                ];
            }),
            'promotions' => $order->promotions,
            'payments' => $order->payments,
        ];
    }

    /** El negocio, que en la respuesta va una sola vez. */
    public function negocio($business): array
    {
        return [
            'business_id'   => $business->busines_id,
            'name'          => $business->name,
            'address'       => $business->address,
            'latitude'      => $business->latitude !== null ? (float) $business->latitude : null,
            'longitude'     => $business->longitude !== null ? (float) $business->longitude : null,
            'phone'         => $business->phone,
            'city'          => $business->city,
            'qualification' => $business->qualification,
            'state'         => $business->state,
            'logo'          => $business->logo,
        ];
    }
}
