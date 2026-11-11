<?php

declare(strict_types=1);

namespace App\Http\Resources\Pedido;

use App\Models\Order\OrdersSales;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UN PEDIDO TAL COMO LO ESPERA LA APLICACION MOVIL.
 *
 * Es la forma que estaba en `PedidoParaLaApp::formatear()`, movida aqui sin
 * tocar un solo campo. El estandar pide que la forma de la respuesta viva en
 * un `JsonResource` y no en un servicio ni en un modelo, y el motivo no es
 * ceremonia: mientras vive en el modelo, cualquiera que serialice ese modelo
 * arrastra la forma sin querer, y mientras vive en un servicio no hay un
 * sitio obvio donde buscarla.
 *
 * ESTA FORMA NO ES LA MISMA QUE `PedidoResource`, y ahora que estan la una al
 * lado de la otra se puede decir en que se diferencian. Las dos sirven «un
 * pedido» y difieren en tres cosas a proposito y en una por descuido:
 *
 *   · a proposito: esta lleva el desglose (`subtotal`, `domicilio`), el
 *     metodo de pago y el estado del cobro, porque el domiciliario necesita
 *     saber si cobra en la puerta; y junta la calle con la direccion del mapa
 *     para poder llegar al numero de casa;
 *   · a proposito: esta tiene los dos interruptores de abajo y la otra no;
 *   · POR DESCUIDO: la latitud del negocio. Aqui es
 *     `latitude !== null ? (float) ... : null` y en `PedidoResource` es
 *     `latitude ? (float) ... : null`, o sea que una latitud 0 sale 0.0 en
 *     una respuesta y null en la otra. **No se unifica**: cambiarlo cambia
 *     una respuesta que las apps publicadas ya reciben, y cual de las dos es
 *     la buena es una decision de producto. Queda escrito, que es lo que no
 *     se podia hacer cuando estaban en dos ficheros distintos.
 *
 * LOS DOS INTERRUPTORES, que son la razon de que la clase exista:
 *
 *   `conDatosDelComprador` — la tienda dueña del pedido y el domiciliario YA
 *   ASIGNADO llegan a la puerta, asi que reciben nombre, telefono y
 *   direccion. Un domiciliario mirando la lista de pedidos POR TOMAR todavia
 *   no tiene nada que ver con esa persona: ve el pedido y nada de quien lo
 *   pidio. Sin esto, dar de comer a su pantalla significaba abrirle la lista
 *   entera de la tienda con los datos de cada comprador dentro.
 *
 *   `conGanancia` — lo que gana el domiciliario no va en su propia pantalla,
 *   a peticion del dueño del producto. Se quita del campo Y de `payments`,
 *   que lo llevaba dentro: quitar uno sin el otro no quita nada.
 *
 * QUE RELACIONES HAY QUE PRECARGAR lo dice esta misma clase, en
 * `relaciones()` y `relacionesSinComprador()`, y es el sitio por el mismo
 * argumento que daba `PedidoParaLaApp` cuando las tenia: quien decide que
 * relaciones hacen falta es quien las toca, y quien las toca es esto. Dos
 * copias de esa lista es tenerla mal en una de las dos, y ya paso una vez —a
 * una le faltaba `domiciliary.user` y `GET /orders/business` cargaba el
 * repartidor de uno en uno, dos consultas por pedido—.
 */
class PedidoDeLaAppResource extends JsonResource
{
    /**
     * LAS RELACIONES QUE HAY QUE PRECARGAR PARA SERVIR ESTA FORMA.
     *
     * Vive aquí y no en cada controlador porque es esta clase la que las LEE:
     * quien decide qué relaciones hacen falta es el que las toca, no el que
     * hace la consulta.
     *
     * Y porque ya se separaron. `PedidosDelDomiciliarioController` tenía la
     * lista completa en un método privado; `ConsultaDePedidosController`
     * tenía su propia copia y **le faltaba `domiciliary.user`**. Resultado:
     * `GET /orders/business` cargaba el domiciliario y su usuario de UNO EN
     * UNO —dos consultas por pedido con repartidor—, y a cincuenta pedidos
     * en pantalla eso son cien consultas que nadie veía, porque la respuesta
     * era correcta.
     *
     * Lo encontró `Model::preventLazyLoading()`, no una revisión: de las
     * 1127 pruebas de la suite, exactamente dos fallaron al encenderlo, y
     * las dos señalaban aquí.
     *
     * AL AÑADIR UN CAMPO A `toArray()` que venga de una relación, se añade
     * la relación aquí. La prueba de N+1 no deja que se olvide.
     */
    public static function relaciones(): array
    {
        return [
            'business',
            /*
             * `.category` Y NO SOLO `.product`: `toArray()` lee
             * `$detail->product->category?->name`, y sin esto la categoría se
             * cargaba por LÍNEA de pedido, no por pedido. Un pedido de diez
             * productos costaba diez consultas de más.
             */
            'details.product.category',
            'buyer.user',
            'promotions',
            'payments',
            'domiciliary.user',
            /*
             * LAS CUATRO DE LA DIRECCIÓN SON HERMANAS, NO UNA CADENA.
             *
             * Estaba escrito `address.municipality.department.country`, que
             * carga el municipio, el departamento DEL MUNICIPIO y el país DEL
             * DEPARTAMENTO. Pero `toArray()` lee las cuatro directamente de
             * la dirección —`$order->address->department?->name`—, y
             * `department` y `country` son `hasOneThrough` propias de
             * `UserAddress`. O sea que la cadena cargaba dos niveles que
             * nadie lee y dejaba sin cargar los dos que sí.
             *
             * Resultado: dos consultas más por pedido con dirección. Lo
             * encontró la prueba de N+1 de abajo, no una revisión.
             */
            'address.municipality',
            'address.department',
            /*
             * `address.country` NO va: la relacion esta rota —su clave local
             * es `department_id` y `user_address` no tiene esa columna— asi
             * que devuelve null siempre, y precargarla era una consulta por
             * peticion que no podia devolver nada. El campo se rellena con
             * null escrito a mano, que es lo mismo que devolvia. El porque
             * completo esta en `UserAddress::country()`.
             */
            'address.alias',
        ];
    }

    /**
     * Las mismas, sin lo que solo se enseña a quien ya tiene el pedido.
     *
     * POR QUÉ EXISTE. `toArray()` con `conDatosDelComprador = false` devuelve `buyer => null`
     * y `delivery_address => null` —así se le muestra al domiciliario un
     * pedido que todavía no ha tomado: ve de qué tienda es y cuánto suma, no
     * a quién ni dónde—. Pero la precarga era la misma lista en los dos casos,
     * así que esas relaciones se traían de la base para tirarlas al
     * serializar.
     *
     * Medido contra la base de desarrollo: `GET /v1/domiciliaries/pedidos`
     * hacía 34 consultas, y seis eran estas sobre la mitad «por tomar»
     * —`buyer`, `user`, `address` y sus tres hermanas—. No cambia la
     * respuesta: esos campos ya salían nulos.
     *
     * SE DERIVA DE `relaciones()`, no se escribe a mano: así una relación
     * nueva entra en las dos listas sola. Una copia literal se queda vieja el
     * día que alguien añada algo arriba, y el síntoma sería una carga perezosa
     * en producción —donde `preventLazyLoading` está apagado— o media pantalla
     * vacía.
     *
     * @return list<string>
     */
    public static function relacionesSinComprador(): array
    {
        return array_values(array_filter(
            self::relaciones(),
            fn (string $r) => ! str_starts_with($r, 'buyer')
                && ! str_starts_with($r, 'address'),
        ));
    }

    public function __construct(
        OrdersSales $pedido,
        private readonly bool $conDatosDelComprador = true,
        private readonly bool $conGanancia = true,
    ) {
        parent::__construct($pedido);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /*
         * Los nombres de dentro se conservan —`$order`, `$conDatosDelComprador`,
         * `$conGanancia`— para que el cuerpo sea el mismo texto que habia en
         * `PedidoParaLaApp::formatear()`, caracter por caracter. Lo que no
         * cambia no hay que volver a revisarlo.
         */
        $order = $this->resource;
        $conDatosDelComprador = $this->conDatosDelComprador;
        $conGanancia = $this->conGanancia;

        return [
            'order_id' => $order->orderSales_id,
            'buyer_id' => $order->buyer_id,
            'busines_id' => $order->busines_id,
            'total' => $order->total,
            // Desglose, para que cada rol sepa qué parte le corresponde:
            // el negocio cobra el subtotal y el domiciliario el domicilio.
            'subtotal' => $order->subtotal,
            'domicilio' => $order->domicilio,
            /*
                     * LO QUE GANA EL DOMICILIARIO NO VA EN SU PROPIA PANTALLA.
                     *
                     * Se sigue calculando y se sigue guardando —la tienda lo necesita
                     * para pagarle y la liquidación para cuadrar— pero deja de
                     * viajarle a él, a peticion del dueño del producto: ver sus
                     * ganancias mientras reparte y manejar efectivo ajeno al mismo
                     * tiempo es de donde salen los errores de pago.
                     *
                     * Se quita acá y no solo en la pantalla: un campo que la API
                     * sigue devolviendo no está oculto, está a un vistazo de
                     * distancia.
                     */
            'domiciliary_fee' => $conGanancia ? $order->domiciliary_fee : null,
            /*
                     * Con cuánto dijo el cliente que paga, y cuánto cambio hay que
                     * llevarle. Es la diferencia entre salir con el sencillo en el
                     * bolsillo y llegar a la puerta sin con qué devolver.
                     *
                     * Es una ESTIMACIÓN: el cliente paga al final con lo que lleve
                     * encima, así que la pantalla lo dice en ese tono y el pedido se
                     * puede cerrar igual si no coincide.
                     */
            'cash_tendered' => $order->cash_tendered,
            'change_due' => $order->change_due,
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
                ],
            ] : null,
            'business' => [
                'business_id' => $order->business->busines_id,
                'name' => $order->business->name,
                'address' => $order->business->address,
                'latitude' => $order->business->latitude !== null ? (float) $order->business->latitude : null,
                'longitude' => $order->business->longitude !== null ? (float) $order->business->longitude : null,
                'phone' => $order->business->phone,
                'city' => $order->business->city,
                'state' => $order->business->state,
                'logo' => $order->business->logo,
            ],
            'delivery_address' => ($conDatosDelComprador && ! $order->pickup && $order->address) ? [
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
                // Siempre null, y a proposito: ver `UserAddress::country()`.
                // Sigue viajando porque las apps publicadas leen la clave.
                'country' => null,
                'latitude' => $order->address->latitude !== null ? (float) $order->address->latitude : null,
                'longitude' => $order->address->longitude !== null ? (float) $order->address->longitude : null,
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
                    // Por donde se pesa: un pedido puede traer la misma
                    // referencia en dos lineas.
                    'orderDet_id' => $detail->orderDet_id,
                    'product_id' => $detail->product->products_id,
                    'name' => $detail->product->name,
                    'description' => $detail->product->description,
                    'category' => $detail->product->category?->name,
                    'image' => $detail->product->image,
                    'amount' => $detail->amount,
                    'unit_price' => $detail->unit_price,
                    /*
                             * LO QUE SE VENDE AL PESO. `amount` vale 1 en esas lineas
                             * --migrarlo a decimal habria cambiado en silencio el
                             * significado de veintidos sitios-- asi que la cantidad de
                             * verdad viaja aca, junto con la unidad y el importe ya
                             * congelado.
                             */
                    'sale_unit' => $detail->sale_unit,
                    'pedido_por' => $detail->pedido_por,
                    'qty_requested' => $detail->qty_requested,
                    'qty_final' => $detail->qty_final,
                    'line_total' => $detail->line_total,
                ];
            }),
            'promotions' => $order->promotions,
            /*
                     * `payments` iba crudo, y lleva su `domiciliary_fee` dentro: era
                     * la segunda puerta por la que salía la comisión, y justo de la
                     * que tiran los respaldos de la app cuando el campo de arriba no
                     * viene. Quitar uno sin el otro no quita nada.
                     *
                     * Y es un `hasOne` pese al nombre en plural, así que acá hay un
                     * pago o no hay ninguno —no una lista—. Por eso el modelo escribe
                     * `$this->payments ?? null` en su propia serialización.
                     */
            'payments' => $conGanancia || ! $order->payments
                ? $order->payments
                : collect($order->payments->toArray())->except('domiciliary_fee')->all(),
        ];
    }
}
