<?php

declare(strict_types=1);

namespace App\Http\Resources\Pedido;

use App\Models\Order\OrdersSales;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UN PEDIDO EN EL HISTORIAL DEL COMPRADOR. Treinta y una claves.
 *
 * Era el cierre `->map()` de `ConsultaDePedidosController::ordersUser()`, el
 * método que la app llama para «Mis pedidos».
 *
 * ES LA TERCERA FORMA DE PEDIDO, y las tres son casi la misma. Medido clave
 * por clave:
 *
 *   PedidoResource          23  lo que devuelven cancelar y pesar, en el
 *                               panel del tendero. SUBCONJUNTO ESTRICTO de
 *                               esta: no tiene ni una clave que esta no
 *                               tenga.
 *   PedidoDeLaAppResource   30  la lista del domiciliario. Comparte 29 con
 *                               esta; la diferencia son tres claves en total:
 *                               `buyer` solo allí, `has_review` y
 *                               `weighing_state` solo acá.
 *   esta                    31  el historial del comprador.
 *
 * Tres formas del pedido que difieren en tres claves no son un diseño: son
 * una deriva. Pero unificarlas cambia lo que reciben tres pantallas
 * publicadas, así que lo que se hace acá es dejarlas las tres con nombre y
 * con la diferencia medida en una prueba. La decisión es de producto.
 *
 * DOS COSAS QUE ESTA FORMA PUBLICA Y CONVIENE MIRAR, conservadas porque
 * quitarlas cambia lo que recibe la app ya instalada:
 *
 *   domiciliary_fee     lo que gana el domiciliario por ESE pedido, al
 *                       comprador. El comprador paga `domicilio`; lo que de
 *                       ahí se lleva el domiciliario es otra cifra, y
 *                       mandarle las dos le dice el margen de cada entrega.
 *                       Hay un trabajo hecho para que el domiciliario no vea
 *                       sus propias ganancias en su app —`conGanancia` en
 *                       `PedidoDeLaAppResource`—; el comprador sí las ve.
 *   domiciliary.email   el correo del domiciliario, al comprador. El teléfono
 *                       se entiende: hay que poder llamarlo cuando está en la
 *                       puerta. El correo no sirve para eso.
 *
 * EL BLOQUE `business` NO ES `NegocioDelPedidoResource` --que existe y se usa
 * en este mismo controlador, cien líneas más abajo--. Diferencia medida: una
 * clave, `qualification`, que esa tiene y esta no. Se conserva tal cual.
 *
 * Y UN DEFECTO QUE SE ARREGLA SIN CAMBIAR LA RESPUESTA: el bloque `business`
 * traía `'address'` DOS VECES. PHP se queda con la última, así que no hacía
 * nada —las dos leían lo mismo—, pero es un copiar y pegar que lleva ahí
 * desde que se escribió y que la próxima vez puede caer sobre dos valores
 * distintos.
 */
class PedidoDelCompradorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OrdersSales $order */
        $order = $this->resource;

        return [
            'order_id' => $order->orderSales_id,
            'buyer_id' => $order->buyer_id,
            'busines_id' => $order->busines_id,
            'total' => $order->total,
            // Desglose, para que cada rol sepa qué parte le corresponde:
            // el negocio cobra el subtotal y el domiciliario el domicilio.
            'subtotal' => $order->subtotal,
            'discount' => $order->discount,
            'domicilio' => $order->domicilio,
            /*
             * `domiciliary_fee` SE RETIRA DE AQUÍ, 13 de noviembre de 2026.
             *
             * El comprador paga `domicilio`; lo que de ahí se lleva el
             * domiciliario es otra cifra, y mandarle las dos le decía el
             * margen de cada entrega. No tenía para qué: ninguna pantalla de
             * la app lo pintaba.
             *
             * COMPROBADO QUIÉN LO LEE, en los cuatro clientes antes de
             * quitarlo: solo `EnviaYa_Admin` —pagos, pedidos y la ficha del
             * domiciliario—, y eso llega por los endpoints de `/admin`, no por
             * esta respuesta. La app no lo menciona en ningún sitio.
             *
             * Y había un trabajo hecho que esto contradecía: `conGanancia` en
             * `PedidoDeLaAppResource` existe para que el DOMICILIARIO no vea
             * sus propias ganancias en su app. Se le ocultaban a él y se le
             * mandaban al comprador.
             */
            /*
             * Con cuánto dijo el cliente que va a pagar, y cuánto cambio hay
             * que mandarle. La tienda es quien prepara el sencillo ANTES de
             * que el domiciliario salga: si el dato no llega acá, el cambio no
             * sale de la tienda y da igual que el domiciliario lo vea después.
             */
            'cash_tendered' => $order->cash_tendered,
            'change_due' => $order->change_due,
            // Si falta pesar: el tablero lo marca y el despacho se bloquea.
            'weighing_state' => (int) $order->weighing_state,
            // El domiciliario necesita saber si cobra en la puerta o si el
            // pedido ya viene pagado en línea.
            'methods_id' => $order->methods_id,
            'payment_state' => $order->payment_state,
            'sale_date' => $order->sale_date,
            'is_scheduled' => $order->is_scheduled,
            // Para recoger: cuándo la tienda dijo «ya está listo».
            'ready_at' => $order->ready_at,
            'delivery_date' => $order->delivery_date,
            'delivery_type' => $order->pickup ? 'pickup' : 'delivery',
            'pickup' => (bool) $order->pickup,
            'pickup_time' => $order->pickup_time,
            'has_review' => $order->has_review,
            'dispatched_at' => $order->dispatched_at,
            /* Cuando el domiciliario marcó «Llegué»: el seguimiento cambia a
               «Tu domiciliario llegó» con la recarga que dispara el aviso. */
            'llego_at' => $order->llego_at,
            // Compromiso y cumplimiento del plazo. Se mandan resueltos desde
            // acá para que la app no tenga que reimplementar la regla y
            // contarla distinto que el informe del panel.
            'promised_minutes' => $order->promised_minutes,
            'delivery_minutes' => $order->delivery_minutes,
            'on_time' => $order->on_time,
            'delay_minutes' => $order->delay_minutes,
            'state' => $order->state,
            'business' => [
                'business_id' => $order->business->busines_id,
                'name' => $order->business->name,
                'address' => $order->business->address,
                'latitude' => $order->business->latitude !== null ? (float) $order->business->latitude : null,
                'longitude' => $order->business->longitude !== null ? (float) $order->business->longitude : null,
                'phone' => $order->business->phone,
                // La ciudad sale de `municipality`: ver NegocioDelPedidoResource.
                'city' => $order->business->municipality?->name,
                'state' => $order->business->state,
                'logo' => $order->business->logo,
            ],
            'delivery_address' => ! $order->pickup && $order->address ? [
                'address_id' => $order->address->address_id,
                /*
                 * La calle va JUNTO a la dirección del mapa, no en vez de
                 * ella. La del mapa sitúa la cuadra; la que escribió la
                 * persona lleva el número de casa o apartamento. El
                 * domiciliario necesita las dos para llegar a la puerta, y
                 * hasta hace poco solo le llegaba la primera.
                 */
                'address' => trim(implode(', ', array_filter([
                    $order->address->street,
                    $order->address->address,
                ]))),
                'street' => $order->address->street,
                'alias' => $order->address->alias?->name,
                'municipality' => $order->address->municipality?->name,
                'department' => $order->address->department?->name,
                /*
                 * POR LA CADENA Y NO POR `UserAddress::country()`, 13 de
                 * noviembre de 2026. Esa relación está marcada `@deprecated
                 * Devuelve null siempre` en el propio modelo: es un
                 * `hasOneThrough` y hacen falta TRES saltos
                 * —`municipality → department → country`—, así que devolvía
                 * null. Medido sobre el historial de un comprador: nulo en
                 * 683 de 683 pedidos.
                 *
                 * NO CUESTA UNA CONSULTA MÁS: `ConsultaDePedidosController`
                 * ya precarga `address.municipality.department.country`
                 * entera. Los datos estaban cargados y el Resource los
                 * ignoraba para preguntarle a una relación rota. Es el mismo
                 * recorrido que `DireccionResource` hace en la lista de
                 * direcciones, donde el país SÍ salía.
                 */
                'country' => $order->address->municipality?->department?->country?->name,
                'latitude' => $order->address->latitude !== null ? (float) $order->address->latitude : null,
                'longitude' => $order->address->longitude !== null ? (float) $order->address->longitude : null,
            ] : null,
            'domiciliary' => $order->domiciliary ? [
                'name' => $order->domiciliary->user->name,
                /*
                 * `email` SE RETIRA, 13 de noviembre de 2026. El teléfono se
                 * entiende: hay que poder llamarlo cuando está en la puerta.
                 * El correo no sirve para eso, y es el dato de contacto
                 * personal de un trabajador puesto en manos de cada cliente
                 * al que le reparte.
                 *
                 * Ninguna pantalla de la app lo leía; comprobado en los cuatro
                 * clientes antes de quitarlo.
                 */
                'phone' => $order->domiciliary->user->phone,
                'domiciliary_id' => $order->domiciliary->domiciliary_id,
                'available' => $order->domiciliary->available,
                'qualification' => $order->domiciliary->qualification,
                'state' => $order->domiciliary->state,
                'user_id' => $order->domiciliary->user->user_id,
            ] : null,
            'details' => $order->details->map(
                fn ($detail) => (new RenglonDelPedidoResource($detail))->resolve()
            ),
            'promotions' => $order->promotions,
            'payments' => $order->payments,
        ];
    }
}
