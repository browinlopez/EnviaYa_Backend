<?php

namespace App\Services;

use App\Models\Product\ProductBusiness;


/**
 * Las cifras que se congelan en un pedido al crearlo.
 *
 * Estaban dentro de `OrderController::store`, entre la validacion del
 * carrito y el guardado, y eran la mitad de sus 496 lineas. Sacarlas no es
 * solo higiene: es lo unico que decide dinero, y aca se puede leer entero
 * de una vez, probar sin levantar una peticion HTTP y reutilizar el dia que
 * un pedido se cree desde otro sitio —el panel, una importacion, un
 * reintento—.
 *
 * NO decide si el pedido se crea: eso sigue siendo del controlador, que es
 * quien puede responder 409 si el total ya no es el que la app prometio.
 */
class EconomiaDelPedido
{
    public function __construct(
        private readonly CouponService $cupones,
        private readonly PoliticaDeDomicilio $domicilios,
        private readonly TarifaPorDistancia $distancias,
        private readonly DescuentoPorPromocion $promociones,
    ) {
    }

    /**
     * @param  iterable  $lineas   Lo pedido: [{product_id, amount}, ...]
     * @param  \Illuminate\Support\Collection  $precios  products_id => precio del servidor
     * @return array{subtotal:float,descuento:float,tarifaBase:float,rebajaDomicilio:float,domicilio:float,total:float,domiciliaryFee:float,platformFee:float,cupon:?object}
     */

    /**
     * Los precios reales, los del servidor.
     *
     * El `unit_price` NUNCA se toma del cliente: se busca el precio del
     * producto EN ESTE NEGOCIO. De paso valida que cada producto sea suyo y
     * siga activo — uno retirado desde el panel no se vende, aunque el
     * carrito del cliente todavia lo lleve dentro.
     *
     * Devuelve [precios, faltantes]. Quien llama decide que hacer con los
     * faltantes: el controlador responde 422 y no crea el pedido.
     */
    public function preciosDelServidor(iterable $lineas, int $negocioId): array
    {
        $ids = collect($lineas)->pluck('product_id')->all();

        $precios = ProductBusiness::where('busines_id', $negocioId)
            ->whereIn('products_id', $ids)
            ->whereHas('product', fn ($q) => $q->where('state', 1))
            ->pluck('price', 'products_id');

        $faltantes = collect($ids)->reject(fn ($id) => $precios->has($id));

        return [$precios, $faltantes];
    }

    public function calcular(
        iterable $lineas,
        $precios,
        ?string $codigoCupon,
        int $compradorId,
        int $negocioId,
        bool $esRecogida,
        ?float $latNegocio = null,
        ?float $lonNegocio = null,
        ?float $latEntrega = null,
        ?float $lonEntrega = null,
    ): array {
        $subtotal = collect($lineas)
            ->sum(fn($p) => $p['amount'] * (float) $precios[$p['product_id']]);

        /*
         * Tarifa de domicilio, del panel.
         *
         * Vivía en `config/services.php`, o sea en el `.env` del servidor,
         * mientras la app llevaba su propia copia escrita en el código con
         * un comentario que pedía "mantener ambas iguales". No lo estaban:
         * subir la tarifa exigía desplegar el servidor Y publicar una
         * versión nueva en las tiendas, y entre una cosa y otra todos los
         * pedidos mostraban un total y cobraban otro.
         *
         * Se congela en el pedido al crearlo, como el reparto: cambiarla no
         * reescribe lo ya entregado.
         */
        /*
         * LA TARIFA YA NO ES PLANA.
         *
         * Antes costaba lo mismo cruzar la calle que atravesar el barrio.
         * Ahora sale de la distancia en linea recta entre la tienda y la
         * puerta, por escalones configurables desde el panel.
         *
         * Sin coordenadas —negocios y direcciones cargados antes de que esto
         * existiera— se cae a la tarifa base, que es exactamente lo que se
         * cobraba hasta hoy. Nadie paga de mas por un dato que le falta al
         * sistema.
         */
        $distancia = $esRecogida
            ? ['tarifa' => 0.0, 'km' => null]
            : $this->distancias->entre($latNegocio, $lonNegocio, $latEntrega, $lonEntrega);

        $tarifaBase = $distancia['tarifa'];
        $kmDeEntrega = $distancia['km'];

        /*
         * Cupón. El descuento se recalcula en el servidor a partir del
         * código: aceptar el monto que mande el cliente sería dejar que
         * cualquiera se ponga el descuento que quiera.
         *
         * Se resuelve ANTES del domicilio porque ahora también puede
         * afectarlo: un cupón de envío gratis no toca el subtotal, rebaja
         * la tarifa.
         */
        $cupon = $this->cupones->resolver(
            $codigoCupon,
            $subtotal,
            $compradorId,
            $negocioId,
        );

        $descuentoCupon = $cupon ? $cupon->descuentoPara($subtotal) : 0.0;

        /*
         * LAS PROMOCIONES DE LA TIENDA, encima del cupón.
         *
         * Cupón y promoción SÍ se suman entre ellos, a diferencia de dos
         * promociones: son dos rebajas de bolsillos distintos —el cupón lo
         * paga la plataforma, la promoción el tendero— y quitarle una al
         * cliente por tener la otra sería castigarlo por acertar.
         *
         * El tope de `subtotal` no es decorativo: un 50 % más un cupón de
         * $10.000 sobre un carrito de $12.000 daría un descuento mayor que
         * la compra, y con él un total negativo que el negocio acabaría
         * pagando de su bolsillo.
         */
        $promo = $this->promociones->paraElCarrito($negocioId, $lineas, $precios);

        $descuento = min($subtotal, $descuentoCupon + $promo['descuento']);

        /*
         * EL DOMICILIO SE PARTE EN TRES.
         *
         * Antes era una sola cifra y por eso no había forma de regalarlo:
         * `domiciliary_fee` salía de lo que pagaba el cliente, así que un
         * domicilio gratis era un viaje gratis para quien lo hace.
         *
         *   tarifa base   lo que cuesta el servicio
         *   domicilio     lo que PAGA el cliente (puede ser 0)
         *   subsidio      lo que pone la plataforma para cubrir la rebaja
         *
         * La rebaja sale del bolsillo de la plataforma, nunca del
         * repartidor. El costo de la promoción queda anotado en su propia
         * columna para poder medir cuánto cuesta la campaña.
         */
        $rebajaDomicilio = $this->domicilios->rebajaPara($tarifaBase, $cupon);
        $domicilio       = $tarifaBase - $rebajaDomicilio;

        $total = $subtotal + $domicilio - $descuento;

        /*
         * Lo que gana el domiciliario sale de la TARIFA BASE, no de lo que
         * pagó el cliente. Es la línea que hace posible el domicilio
         * gratis: con `$domicilio` acá, una promoción le habría bajado el
         * pago a quien hace el viaje.
         *
         * Se congela: si el reparto cambia después, esta orden conserva lo
         * que se pactó al crearla.
         */
        $domiciliaryFee = round(
            $tarifaBase * (float) Ajustes::valor('operacion.reparto_domiciliario')
        );

        /*
         * Comisión de la plataforma sobre la venta del negocio.
         *
         * Sobre el subtotal MENOS el descuento, que es lo que el negocio
         * va a cobrar de verdad: cobrarle comisión sobre un dinero que no
         * recibió sería cobrarle dos veces la promoción. El domicilio no
         * entra: no es venta suya.
         */
        $platformFee = round(
            max(0.0, $subtotal - $descuento)
            * (float) Ajustes::valor('operacion.comision_plataforma'),
            2
        );

        return [
            'subtotal'        => $subtotal,
            'descuento'       => $descuento,
            // Desglosado para poder decir en pantalla de dónde sale la rebaja:
            // «Cupón −$2.000» y «Promoción −$2.500» no son lo mismo.
            'descuentoCupon'  => round($descuentoCupon, 2),
            'descuentoPromo'  => $promo['descuento'],
            'promociones'     => $promo['aplicadas'],
            // «Te falta una para que el tercero salga gratis».
            'promocionesCerca' => $promo['cerca'],
            'tarifaBase'      => $tarifaBase,
            'rebajaDomicilio' => $rebajaDomicilio,
            'domicilio'       => $domicilio,
            'total'           => $total,
            'domiciliaryFee'  => $domiciliaryFee,
            'platformFee'     => $platformFee,
            'cupon'           => $cupon,
            // Con que distancia se calculo, para poder decirlo en pantalla.
            'km'              => $kmDeEntrega,
        ];
    }
}
