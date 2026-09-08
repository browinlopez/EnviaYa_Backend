<?php

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Services\Ajustes;
use App\Services\ArmadoDelPedido;
use App\Services\CobroContraEntrega;
use App\Services\EconomiaDelPedido;
use App\Services\PagoEnLinea;
use App\Services\ReglasDeDespacho;
use App\Services\TarifaPorDistancia;
use App\Services\PoliticaDeDomicilio;
use App\Services\CustodiaDeEfectivo;
use App\Services\ConfirmacionDePago;
use App\Services\Avisos;
use App\Events\DomiciliaryLocationUpdated;
use App\Events\OrderCreated;
use App\Events\OrderStatusUpdated;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Payment\PaymentController;
use App\Models\Business;
use App\Models\Buyer\Buyer;
use App\Models\Domiciliary;
use App\Models\Order\OrderGeolocation;
use App\Models\Order\OrdersSales;
use App\Models\Order\OrdersSalesDetail;
use App\Models\Payment\Payment;
use App\Services\FacturaService;
use Illuminate\Support\Facades\Log;
use App\Models\Payment\PaymentForms;
use App\Models\Payment\PaymentIntent;
use App\Models\Payment\PaymentMethods;
use App\Models\Product\ProductBusiness;
use App\Models\User;
use App\Models\User\UserAddress;
use App\Services\BoldService;
use App\Services\CouponService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    use ComprobarPertenencia;

    // Crear orden de venta
    public function store(
        Request $request,
        BoldService $bold,
        EconomiaDelPedido $economia,
        ArmadoDelPedido $armado,
        PagoEnLinea $pasarela,
        TarifaPorDistancia $distancias,
    )
    {
        /* ========= COMPATIBILIDAD DE NOMBRES =========
           La app migrada a la API nueva manda business_id / payment_method_id
           / quantity; el contrato original usa busines_id / methods_id /
           amount. Se aceptan ambos. */
        if (!$request->filled('busines_id') && $request->filled('business_id')) {
            $request->merge(['busines_id' => $request->input('business_id')]);
        }
        if (!$request->filled('methods_id') && $request->filled('payment_method_id')) {
            $request->merge(['methods_id' => $request->input('payment_method_id')]);
        }
        $normalizedProducts = collect($request->input('products', []))
            ->map(fn($p) => [
                'product_id' => $p['product_id'] ?? null,
                'amount' => $p['amount'] ?? $p['quantity'] ?? null,
            ])->all();
        $request->merge(['products' => $normalizedProducts]);

        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
            'busines_id' => 'required|integer|exists:business,busines_id',
            'address_id' => 'required_if:pickup,false|nullable|integer',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|integer',
            'products.*.amount' => 'required|integer|min:1|max:1000',
            'methods_id' => 'required|integer|exists:payment_methods,methods_id',
            'payer' => 'nullable|array',
            'payment_method' => 'required_if:methods_id,2|array',
            'pickup' => 'sometimes|boolean',
            'pickup_time' => 'nullable|date',
            'is_scheduled' => 'sometimes|boolean',
            'delivery_date' => 'nullable|required_if:is_scheduled,true|date|after:now',
            // Opcional: el código que el usuario escribió en el carrito. El
            // descuento NO llega desde el cliente, se recalcula acá.
            'coupon_code' => 'sometimes|nullable|string|max:40',
            /*
             * El total que la app le PROMETIÓ al usuario. No se usa para
             * cobrar —el importe lo calcula el servidor— sino para detectar
             * que ya no coinciden y no cobrar algo que nadie aceptó. Opcional
             * por compatibilidad: las versiones que no lo manden se comportan
             * como antes.
             */
            'expected_total' => 'sometimes|nullable|numeric|min:0',
        ]);

        /* ========= VALIDACIONES REALES ========= */

        $buyer = Buyer::where('user_id', $request->user_id)->first();
        if (!$buyer) {
            return response()->json(['message' => 'Usuario comprador no encontrado'], 404);
        }

        $business = Business::find($request->busines_id);
        if (!$business) {
            return response()->json(['message' => 'Negocio no encontrado'], 404);
        }

        /*
         * Y que siga abierto.
         *
         * Filtrar los listados no basta: basta con conservar el identificador
         * —de un favorito guardado, de un pedido anterior, de una pantalla que
         * quedó abierta— para pedirle a una tienda que la operación creía
         * apagada. Acá es donde de verdad se cierra.
         */
        if (!$business->state) {
            return response()->json([
                'message' => 'Este negocio no está recibiendo pedidos ahora mismo.',
            ], 422);
        }

        $isPickup = (bool) ($request->pickup ?? false);

        // La dirección solo aplica (y solo se exige) para domicilio, y debe
        // pertenecer al usuario que ordena.
        $address = null;
        if (!$isPickup) {
            $address = UserAddress::find($request->address_id);
            if (!$address || (int) $address->user_id !== (int) $request->user_id) {
                return response()->json(['message' => 'Dirección no encontrada'], 404);
            }

            /*
             * Y QUE LA TIENDA REPARTA HASTA AHÍ.
             *
             * `operacion.radio_maximo_km` existía, los listados devolvían
             * `in_range` por negocio… y no lo miraba NADIE: ni la app ni esta
             * creación. Se podía pedir a 12,5 km a una tienda que reparte
             * hasta 6, con la tarifa del escalón correspondiente cobrada
             * tan campante.
             *
             * Va acá por lo mismo que el `state` de arriba: filtrar los
             * listados no basta, porque basta con conservar el identificador
             * —un favorito, un pedido anterior, una pantalla abierta— para
             * saltárselo. Esta es la puerta de verdad; lo que haga la app es
             * cortesía para no hacer perder el tiempo.
             *
             * Sin coordenadas se deja pasar: es la misma decisión que en la
             * tarifa, y bloquear una venta por un dato que la tienda todavía
             * no ha cargado sería castigarla por algo que no decidió.
             */
            $km = $distancias->kilometros(
                $business->latitude !== null ? (float) $business->latitude : null,
                $business->longitude !== null ? (float) $business->longitude : null,
                $address->latitude !== null ? (float) $address->latitude : null,
                $address->longitude !== null ? (float) $address->longitude : null,
            );

            if (!$distancias->reparteHasta($km)) {
                return response()->json([
                    'message' => 'Esta tienda no reparte hasta tu dirección. Elige otra dirección o recoge en tienda.',
                    'reason' => 'out_of_range',
                    'distance_km' => round($km, 2),
                    'max_km' => (float) Ajustes::valor('operacion.radio_maximo_km'),
                ], 422);
            }
        }

        /* ========= PRECIOS REALES DEL SERVIDOR =========
           La regla —el precio lo pone el servidor, no el carrito— vive entera
           en EconomiaDelPedido, junto al resto de lo que decide dinero. */
        [$prices, $missing] = $economia->preciosDelServidor(
            $request->products,
            (int) $business->busines_id,
        );

        if ($missing->isNotEmpty()) {
            return response()->json([
                // El mensaje no distingue "no es de este negocio" de "ya no se
                // vende" a propósito: para quien pide son el mismo problema, y
                // la app tiene que reaccionar igual — quitarlo del carrito.
                'message' => 'Hay productos que ya no están disponibles en este negocio',
                'product_ids' => $missing->values(),
            ], 422);
        }

        /* ========= PROGRAMACIÓN ========= */
        $isScheduled = (bool) ($request->is_scheduled ?? false);
        $deliveryDate = null;

        if ($isScheduled) {
            $deliveryDate = \Carbon\Carbon::parse($request->delivery_date);
            $hour = (int) $deliveryDate->format('G');
            if ($hour < 7 || $hour > 20) {
                return response()->json([
                    'message' => 'Los pedidos programados solo se aceptan entre 7:00 AM y 9:00 PM',
                ], 422);
            }
        }

        DB::beginTransaction();

        try {
            /* ========= ORDEN ========= */

            /*
             * Las cifras que deciden dinero, todas de una vez.
             *
             * Vivian aca dentro, partidas en dos por la comprobacion del 409.
             * `EconomiaDelPedido` las calcula juntas porque juntas se
             * congelan: el subtotal a precio del servidor, el cupon, el
             * reparto del domicilio en tres, lo que gana el repartidor y la
             * comision de la plataforma.
             */
            $cifras = $economia->calcular(
                $request->products,
                $prices,
                $request->input('coupon_code'),
                (int) $request->user_id,
                (int) $business->busines_id,
                $isPickup,
                // Dónde está la tienda y dónde la puerta: de ahí sale la
                // tarifa por distancia. Sin coordenadas se cobra la base.
                $business->latitude !== null ? (float) $business->latitude : null,
                $business->longitude !== null ? (float) $business->longitude : null,
                $address?->latitude !== null ? (float) $address?->latitude : null,
                $address?->longitude !== null ? (float) $address?->longitude : null,
            );

            $subtotal        = $cifras['subtotal'];
            $descuento       = $cifras['descuento'];
            $domicilio       = $cifras['domicilio'];
            $total           = $cifras['total'];
            $cupon           = $cifras['cupon'];
            $rebajaDomicilio = $cifras['rebajaDomicilio'];
            $domiciliaryFee  = $cifras['domiciliaryFee'];
            $platformFee     = $cifras['platformFee'];

            /*
             * ¿Sigue valiendo lo que la app prometió?
             *
             * El servidor siempre calculó bien —ignora los precios que manda el
             * cliente y usa los suyos— pero nadie comparaba, así que un cambio
             * de precio o de tarifa entre armar el carrito y tocar "Pagar" se
             * cobraba en silencio: la pantalla decía $18.400 y el pedido salía
             * por $21.900.
             *
             * Se responde 409 con el desglose real y NO se crea el pedido. Un
             * pedido por un importe que el usuario no aceptó es peor que un
             * pedido no creado: con el 409 la app puede enseñar la diferencia y
             * dejar que decida.
             *
             * El margen de un peso es por el redondeo de la app al pintar.
             */
            $esperado = $request->input('expected_total');

            if ($esperado !== null && abs((float) $esperado - $total) > 1) {
                DB::rollBack();

                return response()->json([
                    'message'  => 'El precio cambió mientras armabas el pedido.',
                    'expected' => (float) $esperado,
                    'total'    => $total,
                    'breakdown' => [
                        'subtotal'  => $subtotal,
                        'delivery'  => $domicilio,
                        'discount'  => $descuento,
                    ],
                ], 409);
            }

            /*
             * El alta del pedido y su detalle. Dentro va la regla del
             * reintento: un segundo intento de pago reutiliza el pedido que
             * quedo a medias en vez de crear otro.
             */
            $order = $armado->guardar(
                $buyer,
                (int) $business->busines_id,
                $address?->address_id,
                (int) $request->methods_id,
                $request->products,
                $prices,
                $cifras,
                $isScheduled,
                $deliveryDate,
                $isPickup,
                $request->pickup_time,
                (int) $request->user_id,
            );

            /* ========= PAGO ONLINE ========= */

            $intent = null;

            if (in_array($request->methods_id, PagoEnLinea::CON_PASARELA)) {
                // Devuelve el intent porque su referencia viaja en la respuesta:
                // la app la necesita para consultar el estado del cobro.
                $intent = $pasarela->cobrar(
                    $order, $request->products, $prices, $request, $bold,
                );
            }

            DB::commit();

            /*
             * Avisar a la tienda de que le entró un pedido.
             *
             * Va DESPUÉS del commit a propósito: si se emitiera dentro de la
             * transacción, el tendero podría recibir el aviso de un pedido que
             * termina revirtiéndose y buscarlo en una lista donde no está.
             *
             * Y en un try, como los demás: si Reverb está caído, el pedido ya
             * está creado y cobrado. Perder el aviso significa que la tienda lo
             * verá al refrescar; hacer fallar la compra por no poder avisar
             * sería mucho peor.
             */
            try {
                /*
                 * Nada de avisar un pedido que aún no está pagado.
                 *
                 * El aviso hace sonar la tienda y le pinta el pedido en la
                 * pantalla al instante. Con un pago rechazado eso era llamar al
                 * tendero a preparar algo que nadie compró —y cada reintento
                 * volvía a llamarle—.
                 *
                 * Cuando el pago se confirme, `confirmarPagoDelPedido()` emite
                 * el aviso: llega unos segundos más tarde y ya es de verdad.
                 */
                if (!$order->esperandoPago()) {
                    broadcast(new OrderCreated($order->load('buyer.user')));
                }
            } catch (\Throwable $e) {
                Log::warning('No se pudo anunciar el pedido nuevo', [
                    'order_id' => $order->orderSales_id,
                    'error'    => $e->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Orden creada',
                'order' => $order->load('details.product.category', 'business', 'address', 'promotions', 'payments')->toApi(),
                'bold_reference_id' => $intent->bold_reference_id ?? null,
                // La app lee el QR / redirect de acá para el pago online
                'action' => isset($payment) ? [
                    'qr_payload' => $payment->qr_payload ?? null,
                    'redirect_url' => $payment->redirect_url ?? null,
                ] : null,
            ], 201);
        } catch (\RuntimeException $e) {
            DB::rollBack();

            /*
             * Rechazos de cupón (vencido, agotado, tope por persona). El mensaje
             * ya viene redactado para quien pide y va tal cual: dejarlo caer en
             * el catch de abajo lo convertiría en "Error al crear la orden" y el
             * usuario reintentaría el mismo código sin saber qué pasó.
             */
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            DB::rollBack();

            // Errores de configuración del comercio en Bold → mensaje claro
            $message = str_contains($e->getMessage(), 'MCFG_004')
                || str_contains($e->getMessage(), 'Not Available')
                ? 'Este método de pago no está disponible por el momento. Intenta con otro método.'
                : 'Error al crear la orden';

            return response()->json([
                'message' => $message,
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * CANCELAR UN PEDIDO
     *
     * La app pintaba un botón "Cancelar" con estilo destructivo y SIN
     * `onPress`: el comprador creía que había cancelado y el pedido seguía su
     * curso. Del lado del servidor tampoco había por dónde: `updateStatus`
     * solo admite los estados 2, 3 y 4, así que el 5 —Cancelado, que existe en
     * el modelo— era inalcanzable.
     *
     * Va aparte de `updateStatus` a propósito, por dos motivos:
     *
     *  · Aquel no comprueba pertenencia de ninguna clase, y cancelar el pedido
     *    de otra persona con solo saber su identificador no es aceptable.
     *  · La regla de cuándo se puede cancelar es propia de esta acción y no
     *    tiene sentido mezclarla con las transiciones de la operación.
     *
     * SOLO MIENTRAS NADIE LO HAYA TOCADO. Estado 1 es "en preparación": la
     * tienda todavía no lo aceptó. En cuanto lo acepta hay comida preparándose
     * o un domiciliario en camino, y eso ya no lo deshace el cliente solo.
     */
    public function cancel(Request $request, $id)
    {
        $usuario = $request->user();

        $order = OrdersSales::with('buyer')->find($id);

        if (!$order) {
            return response()->json(['message' => 'Pedido no encontrado'], 404);
        }

        if (!$order->buyer || (int) $order->buyer->user_id !== (int) $usuario->user_id) {
            return response()->json(['message' => 'Este pedido no es tuyo.'], 403);
        }

        if ((int) $order->state !== 1) {
            return response()->json([
                'message' => 'Ya no se puede cancelar: la tienda empezó a prepararlo. '
                    . 'Escríbele por el chat del pedido.',
            ], 422);
        }

        $order->state = 5;
        $order->save();

        // Si lo canceló el propio comprador ya lo sabe, pero el aviso deja
        // constancia en su campana: es un pedido que existió y desapareció, y
        // conviene que quede rastro de por qué.
        if ($order->buyer?->user_id) {
            Avisos::para(
                $order->buyer->user_id,
                'pedido_cancelado',
                "Tu pedido #{$order->orderSales_id} fue cancelado.",
                ['order_id' => $order->orderSales_id],
            );
        }

        // Se avisa por el canal del pedido, igual que cualquier otro cambio de
        // estado: la tienda tiene que enterarse de que ya no lo prepare.
        try {
            broadcast(new OrderStatusUpdated($order));
        } catch (\Throwable $e) {
            Log::warning('No se pudo anunciar la cancelación', [
                'order_id' => $order->orderSales_id,
                'error'    => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Pedido cancelado.',
            'order'   => $order->fresh()->toApi(),
        ]);
    }

    /**
     * ¿ESTE USUARIO ES DUEÑO DE ESTE NEGOCIO?
     *
     * La cadena es user → owner → owner_busines → business. Se resuelve con una
     * consulta directa en vez de cargar relaciones porque acá solo interesa el
     * sí o el no, y esto se llama en el camino caliente de la operación.
     */
    private function esDuenoDelNegocio(?int $userId, $businessId): bool
    {
        if (!$userId || !$businessId) {
            return false;
        }

        return DB::table('owner_busines as ob')
            ->join('owner as o', 'o.owner_id', '=', 'ob.owner_id')
            ->where('o.user_id', $userId)
            ->where('ob.busines_id', $businessId)
            ->exists();
    }

    /**
     * QUIÉN PUEDE MOVER EL PEDIDO, Y HASTA DÓNDE
     *
     * Hasta ahora `updateStatus` solo exigía estar autenticado: validaba el
     * formato y a partir de ahí operaba sobre el pedido que le dijeran. Como el
     * identificador es un entero correlativo, cualquier sesión válida —la de un
     * comprador recién registrado— podía aceptar, despachar y dar por entregado
     * el pedido de otra persona probando números.
     *
     * Por esta misma ruta pasan las tres transiciones de la operación, así que
     * no es un rincón oscuro: es el camino principal.
     *
     * Devuelve null si puede, o el mensaje del rechazo si no.
     */
    private function puedeMoverPedido($usuario, OrdersSales $order, int $destino, $userIdDomiciliario): ?string
    {
        $userId = (int) ($usuario->user_id ?? 0);

        return match ($destino) {
            // Aceptar el pedido es decisión de la tienda que lo va a preparar.
            2 => $this->esDuenoDelNegocio($userId, $order->busines_id)
                ? null
                : 'Solo la tienda del pedido puede aceptarlo.',

            /*
             * Despachar tiene dos caminos legítimos y los dos pasan por acá: el
             * tendero asignándole el pedido a alguien, y el domiciliario
             * tomándolo de la lista. En el segundo caso, quien pide tiene que
             * ser el mismo que se está asignando — si no, cualquiera podría
             * cargarle pedidos a otro y llenarle el cupo.
             */
            3 => ($this->esDuenoDelNegocio($userId, $order->busines_id)
                    || (int) $userIdDomiciliario === $userId)
                ? null
                : 'Solo la tienda o el domiciliario que lo toma pueden despacharlo.',

            /*
             * Entregar solo lo puede declarar quien lo lleva. Es la transición
             * que mueve dinero —registra el cobro en efectivo y la ganancia del
             * domiciliario—, así que acá no se admite ni el tendero.
             */
            4 => ($order->domiciliary && (int) $order->domiciliary->user_id === $userId)
                ? null
                : 'Solo el domiciliario asignado puede marcar la entrega.',

            default => 'Transición no reconocida.',
        };
    }

    // Actualizar estado de la orden (números)
    public function updateStatus(
        Request $request,
        CobroContraEntrega $cobros,
        ReglasDeDespacho $reglas,
    )
    {
        $request->validate([
            'order_id' => 'required|integer',
            'state' => 'required|integer|in:2,3,4',
            'user_id' => 'nullable|integer|exists:user,user_id'
        ]);

        /*
         * DOS PERSONAS TOCANDO EL MISMO PEDIDO A LA VEZ.
         *
         * Con veinte domiciliarios mirando la misma lista, dos tocan «aceptar»
         * en el mismo segundo el primer dia. Y por esta misma transicion pasa
         * tambien el tendero despachando, asi que la pareja puede ser un
         * domiciliario y la tienda.
         *
         * Leyendo con `find()` los dos veian estado 2, los dos pasaban la
         * comprobacion y los dos escribian: el ultimo ganaba y al otro se le
         * respondia «actualizado». Ese otro sale a repartir un pedido que no
         * lleva, y el cliente recibe dos motos.
         *
         * NO SE NOTA EN DESARROLLO: el servidor de PHP en Windows atiende una
         * peticion a la vez, asi que las dos van en fila y siempre sale bien.
         * En produccion, con php-fpm, van de verdad en paralelo.
         *
         * `lockForUpdate` hace que la segunda espere a que la primera termine
         * su transaccion y lea ya el estado nuevo, con lo que su comprobacion
         * de transicion falla como debe. Fuera de una transaccion el bloqueo se
         * suelta enseguida y no sirve de nada, de ahi el `DB::transaction`.
         */
        return DB::transaction(function () use ($request, $cobros, $reglas) {
            $order = OrdersSales::with('details', 'domiciliary')
                ->lockForUpdate()
                ->find($request->order_id);

            return $this->moverPedido($request, $order, $cobros, $reglas);
        });
    }

    /**
     * El cambio de estado en si, ya con el pedido bloqueado.
     *
     * Va aparte para que el bloqueo de arriba envuelva TODO el camino —leer,
     * comprobar y escribir— sin tener que indentar trescientas lineas.
     */
    private function moverPedido(
        Request $request,
        ?OrdersSales $order,
        CobroContraEntrega $cobros,
        ReglasDeDespacho $reglas,
    )
    {

        if (!$order) {
            return response()->json(['message' => 'Orden no encontrada'], 404);
        }

        // Quién es antes de qué hace: sin esto bastaba con saber el número del
        // pedido, que es correlativo.
        $motivo = $this->puedeMoverPedido(
            $request->user(),
            $order,
            (int) $request->state,
            $request->user_id,
        );

        if ($motivo !== null) {
            return response()->json(['message' => $motivo], 403);
        }

        // Acepta pedido
        if ($order->state == 1 && $request->state == 2) {
            $order->state = 2;

            // Acepta domiciliario
        } elseif ($order->state == 2 && $request->state == 3) {
            if (!$request->user_id) {
                return response()->json(['message' => 'Se requiere el user_id del domiciliario para esta transición.'], 422);
            }

            $domiciliary = Domiciliary::where('user_id', $request->user_id)->first();
            if (!$domiciliary) {
                return response()->json(['message' => 'Domiciliario no encontrado'], 404);
            }

            /*
             * Las reglas de asignacion viven en `ReglasDeDespacho`: por esta
             * transicion pasan el domiciliario aceptando y el tendero
             * despachando, y para los dos valen las mismas.
             */
            if ($impedimento = $reglas->impedimento($order, $domiciliary)) {
                return response()->json($impedimento, 422);
            }


            $order->state = 3;
            $order->domiciliary_id = $domiciliary->domiciliary_id;
            // Ancla del cronómetro de entrega en la app
            $order->dispatched_at = now();

            /*
             * El plazo prometido se CONGELA acá, no se lee del ajuste cuando
             * alguien mira el informe. Si se leyera al consultar, subir el
             * estándar en el panel convertiría en "a tiempo" entregas pasadas
             * que llegaron tarde, y nadie podría saber contra qué se midió a
             * cada domiciliario. Mismo criterio que `domicilio` y
             * `domiciliary_fee`.
             */
            $order->promised_minutes = (int) Ajustes::valor('operacion.tiempo_entrega_min');

            /*
             * Avisar al domiciliario, y solo a él.
             *
             * Por esta transición pasan dos caminos: el repartidor tomando el
             * pedido —que ya sabe que lo tomó— y el tendero asignándoselo, que
             * es el caso donde el aviso importa: alguien decide por él y hay que
             * decírselo. Se manda en ambos porque distinguirlos aquí complicaría
             * el código para ahorrar un aviso que, en el primer caso, confirma
             * lo que acaba de hacer.
             *
             * No puede ir por el canal del negocio: ahí lo verían los cinco
             * repartidores de la tienda como si fuera de cada uno.
             */
            Avisos::para(
                $domiciliary->user_id,
                'entrega_asignada',
                "Tienes una entrega nueva: pedido #{$order->orderSales_id}.",
                ['order_id' => $order->orderSales_id],
            );

            // Pedido entregado
        } elseif ($order->state == 3 && $request->state == 4) {
            $order->state = 4;
            $order->delivery_date = now();

            /*
             * Pago en efectivo: se registra al entregar, porque es cuando el
             * domiciliario recibe la plata.
             *
             * Los importes salen de la orden, no se recalculan acá. Antes el
             * domicilio estaba quemado en 2000 (ignorando la tarifa real y los
             * pedidos pickup), el total restaba el domicilio en vez de sumarlo
             * y `amount` estaba fijo en 1.
             */
            /*
             * El cobro en efectivo, el apunte de quien tiene esa plata y el
             * estado de pago del pedido: los tres o ninguno. Vive en
             * `CobroContraEntrega` porque es lo unico de esta transicion que
             * mueve dinero, y tiene que ser identico si algun dia la entrega
             * se declara desde otro sitio.
             */
            $cobros->registrar($order, $request->user()?->user_id);
        } else {
            return response()->json(['message' => 'Transición de estado no permitida.'], 400);
        }

        $order->save();

        /*
         * Y se avisa al teléfono del comprador.
         *
         * Hasta ahora este cambio no salía de la base: no había evento, y la
         * app tampoco sondeaba ni recargaba al volver a la pantalla, así que el
         * seguimiento se quedaba clavado en "pedido recibido" hasta que la
         * persona cerraba la app entera. El domiciliario llegaba a la puerta
         * mientras la pantalla decía que la tienda seguía preparando.
         *
         * Va después del save y no interrumpe: si el servidor de websockets
         * está caído, el pedido ya avanzó y la app tiene su propio respaldo
         * recargando la lista.
         */
        try {
            broadcast(new OrderStatusUpdated($order));
        } catch (\Throwable $e) {
            Log::warning('No se pudo anunciar el cambio de estado del pedido', [
                'order_id' => $order->orderSales_id,
                'error'    => $e->getMessage(),
            ]);
        }

        /*
         * Comprobante del pedido entregado.
         *
         * Va DESPUÉS de guardar y dentro de un try: la entrega ya ocurrió y no
         * puede deshacerse porque falle el comprobante. Si algo sale mal queda
         * en el registro y `facturas:emitir` lo recupera después — al revés, un
         * domiciliario se quedaría sin poder cerrar su entrega por un problema
         * de papeleo.
         *
         * `emitirPara` es idempotente, así que un reintento del cliente o un
         * doble toque no producen dos comprobantes del mismo pedido.
         */
        if ((int) $order->state === 4) {
            try {
                app(FacturaService::class)->emitirPara((int) $order->orderSales_id);
            } catch (\Throwable $e) {
                Log::warning('No se pudo emitir el comprobante del pedido ' . $order->orderSales_id, [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Estado de la orden actualizado',
            'order' => $order->load('details.product', 'buyer', 'business', 'address', 'payments')
        ]);
    }
}
