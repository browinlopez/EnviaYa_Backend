<?php

namespace App\Services;

use App\Services\PagoEnLinea;

use App\Models\Order\OrdersSales;
use App\Models\Order\OrdersSalesDetail;
use App\Support\UnidadDeVenta;

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
    public function __construct(
        private readonly CouponService $cupones,
        private readonly ExistenciasDelPedido $existencias,
        private readonly RedencionDePromociones $promociones,
        private readonly EconomiaDelPedido $economia,
    ) {
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
        ?float $conCuantoPaga = null,
        /* El surtido, para saber en que unidad se vende cada producto. */
        $surtido = null,
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
            /*
             * Con cuanto dice el cliente que va a pagar, para que la tienda
             * mande el cambio preparado. Solo tiene sentido en efectivo: en
             * los demas medios no hay cambio que devolver.
             */
            'cash_tendered' => in_array($metodoId, PagoEnLinea::CONTRA_ENTREGA, true)
                ? $conCuantoPaga
                : null,
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
                : ($metodoId === CreditoDeTienda::METODO ? OrdersSales::A_CREDITO : 'pending_cash')
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

            /*
             * LO QUE RESTÓ EL INTENTO ANTERIOR SE DEVUELVE ANTES DE BORRARLO.
             *
             * Las líneas de abajo se reescriben con el carrito de ahora, y se
             * vuelven a restar. Si no se devolviera primero lo del intento
             * anterior, cada clic en «pagar» restaría el carrito otra vez: tres
             * reintentos, tres veces el inventario.
             */
            $this->existencias->devolver($order);

            // El detalle se reescribe abajo con lo que hay ahora en el
            // carrito, que puede no ser lo mismo que en el primer intento.
            OrdersSalesDetail::where('orderSales_id', $order->orderSales_id)->delete();
        } else {
            $order = OrdersSales::create($datosDelPedido);
        }

        $hayQuePesar = false;

        foreach ($lineas as $p) {
            $unidad = $surtido[$p['product_id']]->sale_unit ?? UnidadDeVenta::UNIDAD;
            $alPeso = UnidadDeVenta::esPeso($unidad);

            if ($alPeso) {
                $hayQuePesar = true;
            }

            OrdersSalesDetail::create([
                'orderSales_id' => $order->orderSales_id,
                'product_id' => $p['product_id'],
                /*
                 * `amount` vale 1 en una linea por peso. No null --la columna
                 * es NOT NULL y hay veintidos lectores--, no el peso redondeado
                 * --truncaria-- y no cero --haria invisible la venta en todo
                 * `SUM`--. Con 1, el codigo que no se entero del cambio lee
                 * "una linea de este producto": un error acotado y legible en
                 * vez de "1,8 kg son 1 kg".
                 */
                'amount' => $alPeso ? 1 : $p['amount'],
                'unit_price' => (float) $precios[$p['product_id']],
                // Congelada, igual que el precio: si el tendero cambia manana
                // la unidad del producto, los pedidos de ayer no se releen.
                'sale_unit' => $unidad,
                'pedido_por' => $alPeso ? ($p['pedido_por'] ?? 'cantidad') : null,
                'qty_requested' => $alPeso ? $this->cantidadPedida($p, $precios) : null,
                /*
                 * El importe, CONGELADO y calculado por el mismo metodo que
                 * sumo el subtotal: si cada uno lo calculara por su cuenta,
                 * apareceria una diferencia de un peso entre el pedido, el
                 * comprobante y el panel.
                 */
                'line_total' => $alPeso
                    ? $this->economia->importeDeLinea($p, $precios, $surtido)
                    : null,
            ]);
        }

        /*
         * Si lleva algo al peso, el pedido nace "por pesar" y el tablero lo
         * marca desde que entra. Es una dimension aparte del estado del
         * pedido: un estado nuevo rompia el panel, los informes y las dos apps
         * ya publicadas.
         */
        if ($hayQuePesar) {
            $order->weighing_state = OrdersSales::POR_PESAR;
            $order->save();
        }

        /*
         * Las unidades salen del inventario con el pedido, en la misma
         * transacción: si algo falla más abajo, vuelven solas. Pueden quedar en
         * negativo — ver ExistenciasDelPedido.
         */
        $this->existencias->descontar($order);

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

        /*
         * Y las promociones de la tienda, por el mismo motivo y en el mismo
         * sitio. `EconomiaDelPedido` ya dejó en `$cifras` cuáles rebajaron
         * este carrito, así que acá no se vuelve a decidir nada: solo se deja
         * constancia y se gasta el uso.
         */
        $this->promociones->canjear($order, $cifras['promociones'] ?? []);

        return $order;
    }

    /**
     * Cuanto se pidio, en la unidad del producto.
     *
     * En "3.000 de queso" la cantidad no la dice el cliente: se deduce de la
     * plata y del precio, y es lo que el tendero usa de referencia al pesar y
     * contra lo que se mide el tope de desvio. En "500 g de tomate" viene
     * dada.
     */
    private function cantidadPedida(array $linea, $precios): ?float
    {
        if (($linea['pedido_por'] ?? null) === 'valor') {
            $precio = (float) ($precios[$linea['product_id']] ?? 0);

            return $precio > 0
                ? round(((float) ($linea['requested_value'] ?? 0)) / $precio, 3)
                : null;
        }

        $pedida = $linea['requested_qty'] ?? null;

        return $pedida === null ? null : (float) $pedida;
    }
}
