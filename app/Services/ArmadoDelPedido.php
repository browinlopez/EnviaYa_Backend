<?php

namespace App\Services;

use App\Services\PagoEnLinea;

use App\Models\Order\OrdersSales;
use App\Models\Order\OrdersSalesDetail;

/**
 * Escribe el pedido y sus lineas.
 *
 * Es la parte de `OrderController::store` que toca la base. Sale aparte
 * porque encierra una regla que no se ve leyendo el controlador: un
 * reintento de pago NO crea otro pedido, reutiliza el que quedo a medias.
 * Perder eso al tocar el metodo significa tres pedidos por cada cliente que
 * le da dos veces a pagar.
 *
 * Va SIEMPRE dentro de la transaccion de quien llama: el alta, el detalle y
 * el canje del cupon son una sola cosa.
 */
class ArmadoDelPedido
{
    public function __construct(private readonly CouponService $cupones)
    {
    }

    public function guardar(
        $comprador,
        int $negocioId,
        ?int $direccionId,
        int $metodoId,
        iterable $lineas,
        $precios,
        array $cifras,
        bool $esProgramado,
        $fechaDeEntrega,
        bool $esRecogida,
        $horaDeRecogida,
        int $compradorUserId,
    ): OrdersSales {

        /*
         * REINTENTAR NO CREA OTRO PEDIDO.
         *
         * Cuando el cobro se rechazaba, la app avisaba y la persona volvía
         * a darle a pagar: eso repetía esta petición entera y dejaba OTRO
         * pedido. Tres intentos, tres pedidos —y con la lista de la tienda
         * sin filtrar, tres veces el mismo encargo esperando a que alguien
         * lo preparara—.
         *
         * Se reutiliza el que quedó a medias si es del mismo comprador, la
         * misma tienda y el mismo importe, y es reciente. Fuera de esa
         * ventana se asume que es una compra nueva que casualmente cuesta
         * lo mismo, y se crea aparte.
         */
        $aMedias = OrdersSales::where('buyer_id', $comprador->buyer_id)
            ->where('busines_id', $negocioId)
            ->whereIn('payment_state', OrdersSales::SIN_PAGO)
            ->where('total', $cifras['total'])
            ->where('created_at', '>=', now()->subMinutes(30))
            ->latest('orderSales_id')
            ->first();

        $datosDelPedido = [
            'buyer_id' => $comprador->buyer_id,
            'busines_id' => $negocioId,
            'address_id' => $direccionId,
            'methods_id' => $metodoId,
            'total' => $cifras['total'],
            'subtotal' => $cifras['subtotal'],
            'domicilio' => $cifras['domicilio'],
            'discount' => $cifras['descuento'],
            'coupon_id' => $cifras['cupon']?->id,
            'domiciliary_fee' => $cifras['domiciliaryFee'],
            // Congeladas al crear, como todo lo que decide dinero.
            'platform_fee'     => $cifras['platformFee'],
            'delivery_subsidy' => $cifras['rebajaDomicilio'],
            'sale_date' => now(),
            /*
             * Solo los programados nacen con fecha: ahí `delivery_date` es
             * la hora PEDIDA por el cliente. En el resto es la hora REAL de
             * entrega y se sella al pasar a estado 4, así que ponerla en
             * `now()` al crear daba por entregado todo pedido nuevo. Los
             * informes que filtran por `delivery_date IS NOT NULL` contaban
             * esos pedidos con un tiempo de entrega de cero minutos y se
             * llevaban el promedio al suelo.
             */
            'delivery_date' => $esProgramado ? $fechaDeEntrega : null,
            'is_scheduled' => $esProgramado,
            'pickup' => $esRecogida,
            'pickup_time' => $esRecogida && $horaDeRecogida
                ? \Carbon\Carbon::parse($horaDeRecogida)->format('Y-m-d H:i:s')
                : null,
            'state' => 1,
            /*
             * La misma lista que decide si se abre el cobro, no una copia.
             * Estaba escrito `[2, 5]` a mano en dos archivos distintos: añadir
             * un medio en uno y olvidarlo en el otro deja pedidos esperando un
             * pago que nadie abrio, o cobrando dos veces.
             */
            'payment_state' => in_array($metodoId, PagoEnLinea::CON_PASARELA)
                ? OrdersSales::ESPERANDO_PAGO
                : 'pending_cash'
        ];

        /*
         * Se REUTILIZA la fila, no se borra.
         *
         * Ese pedido a medias puede tener un cobro todavía en curso apuntando
         * a él. Si se borrara y ese cobro acabara aprobándose, el webhook no
         * encontraría dónde apuntarlo: la persona habría pagado y no habría
         * pedido. Reutilizando la fila, ese aviso tardío sigue cayendo en el
         * sitio correcto.
         */
        if ($aMedias) {
            $aMedias->update($datosDelPedido);
            $order = $aMedias;

            // El detalle se reescribe abajo con lo que hay ahora en el
            // carrito, que puede no ser lo mismo que en el primer intento.
            OrdersSalesDetail::where('orderSales_id', $order->orderSales_id)->delete();
        } else {
            $order = OrdersSales::create($datosDelPedido);
        }

        foreach ($lineas as $p) {
            OrdersSalesDetail::create([
                'orderSales_id' => $order->orderSales_id,
                'product_id' => $p['product_id'],
                'amount' => $p['amount'],
                'unit_price' => (float) $precios[$p['product_id']],
            ]);
        }

        // El uso se consume ya con la orden creada, dentro de la misma
        // transacción: si algo falla más abajo, el cupón se libera solo.
        if ($cifras['cupon']) {
            $this->cupones->canjear(
                $cifras['cupon'],
                (int) $compradorUserId,
                (int) $order->orderSales_id,
                $cifras['descuento'],
            );
        }

        return $order;
    }
}
