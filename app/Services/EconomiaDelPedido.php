<?php

namespace App\Services;

use App\Models\Product\ProductBusiness;
use App\Support\UnidadDeVenta;


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

        $surtido = ProductBusiness::where('busines_id', $negocioId)
            ->whereIn('products_id', $ids)
            ->whereHas('product', fn ($q) => $q->where('state', 1))
            /*
             * SIN PRECIO NO SE VENDE, TAMPOCO AL PAGAR.
             *
             * La vitrina ya escondía los productos en $0 —copiar el surtido de
             * otra tienda los deja así a propósito—, pero esta comprobación no:
             * un carrito guardado de antes de que el precio pasara a cero, o una
             * petición armada a mano, creaba el pedido con esas líneas GRATIS.
             * En producción había diez: cremas Colgate, toallas Nosotras…
             *
             * Filtrándolo acá, un producto en cero cae en «faltantes» y recibe
             * el mismo 422 que uno retirado. La app ya sabe qué hacer con eso:
             * quitarlo del carrito. Y la cotización usa este mismo método, así
             * que el total que se enseña y el que se cobra no pueden separarse.
             */
            ->where('price', '>', 0)
            ->get()
            ->keyBy('products_id');

        $precios = $surtido->map(fn ($p) => $p->price);

        $faltantes = collect($ids)->reject(fn ($id) => $precios->has($id));

        /*
         * El TERCER valor es el surtido entero, que es lo que trae la unidad
         * de venta de cada producto. Va al final a proposito: quien ya
         * destructuraba `[$precios, $faltantes]` sigue funcionando sin
         * tocarlo.
         */
        return [$precios, $faltantes, $surtido];
    }

    /**
     * CUÁNTO VALE UNA LÍNEA DEL CARRITO.
     *
     * Tres casos, y los tres fijan el precio AHORA —ninguno deja el importe
     * pendiente de lo que pese después el tendero—:
     *
     *   · por unidades  →  precio × cantidad, como siempre;
     *   · «3.000 de queso» → el importe ES lo que pidió, y el peso es la
     *     variable que resuelve el mostrador;
     *   · «500 g de tomate» → peso × precio de la unidad.
     *
     * Eso es lo que hace que el total no cambie al pesar, y con ello que no
     * haya que recalcular cobros, bloquear la pasarela ni devolver
     * diferencias. Lo que el cliente aceptó es lo que paga.
     */
    public function importeDeLinea(array $linea, $precios, $surtido = null): float
    {
        $id = (int) ($linea['product_id'] ?? 0);
        $precio = (float) ($precios[$id] ?? 0);

        $unidad = $surtido[$id]->sale_unit ?? UnidadDeVenta::UNIDAD;

        if (!UnidadDeVenta::esPeso($unidad)) {
            return round($precio * (float) ($linea['amount'] ?? 0), 2);
        }

        if (($linea['pedido_por'] ?? null) === 'valor') {
            return round((float) ($linea['requested_value'] ?? 0), 2);
        }

        return round($precio * (float) ($linea['requested_qty'] ?? 0), 2);
    }

    /**
     * LAS LINEAS AL PESO QUE NO DICEN CUANTO.
     *
     * Un producto que la tienda vende por kilo necesita que la peticion diga
     * o cuanta plata --«3.000 de queso»-- o cuanto peso --«500 g»--. Sin eso
     * no hay precio que calcular.
     *
     * Pasa con la app ya publicada en las tiendas, que no sabe que existen
     * esos campos. Devolverle 422 nombrando el producto es mejor que
     * inventarle un peso y cobrarle al cliente algo que no pidio.
     *
     * @return list<int> Los `product_id` incompletos.
     */
    public function lineasSinPeso(iterable $lineas, $surtido = null): array
    {
        $sinPeso = [];

        foreach ($lineas as $linea) {
            $id = (int) ($linea['product_id'] ?? 0);
            $unidad = $surtido[$id]->sale_unit ?? UnidadDeVenta::UNIDAD;

            if (!UnidadDeVenta::esPeso($unidad)) {
                continue;
            }

            $porValor = ($linea['pedido_por'] ?? null) === 'valor';
            $dato = $porValor ? ($linea['requested_value'] ?? null) : ($linea['requested_qty'] ?? null);

            if ($dato === null || (float) $dato <= 0) {
                $sinPeso[] = $id;
            }
        }

        return $sinPeso;
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
        /*
         * El surtido, para saber en qué unidad se vende cada producto. Va al
         * final y con valor por defecto: sin él, todo se comporta como se
         * comportaba —por unidades— y ninguna llamada antigua se rompe.
         */
        $surtido = null,
    ): array {
        $subtotal = collect($lineas)
            ->sum(fn ($p) => $this->importeDeLinea($p, $precios, $surtido));

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
