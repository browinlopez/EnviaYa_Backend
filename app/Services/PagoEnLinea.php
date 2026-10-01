<?php

namespace App\Services;

use App\Http\Controllers\Payment\PaymentController;
use App\Models\Order\OrdersSales;
use Illuminate\Http\Request;

/**
 * El cobro con pasarela de un pedido recien creado.
 *
 * Los seis pasos —intent, pagador, medio, productos, cobro y estado— eran
 * el ultimo tercio de `OrderController::store`. Salen juntos porque juntos
 * tienen sentido: si el cobro se aprueba en el momento el pedido pasa a
 * existir, y si no se queda esperando al webhook. Esa decision es del
 * cobro, no del controlador.
 *
 * Los precios que se le mandan a la pasarela son los del SERVIDOR, nunca
 * los del carrito.
 */
class PagoEnLinea
{
    /** `orderssales.methods_id` que se cobran con pasarela. */
    public const CON_PASARELA = [2, 5];

    /**
     * El unico metodo que se cobra en la puerta.
     *
     * Todo lo que no sea esto ni pasarela NO TIENE COBRO: el pedido se crearia
     * y nadie pediria el dinero. Por eso `MediosDePagoController` solo ofrece
     * los de estas dos listas, y no lo que haya en la tabla.
     */
    public const CONTRA_ENTREGA = [1];

    /** El crédito que da la propia tienda. Ver `CreditoDeTienda`. */
    public const CREDITO_DE_TIENDA = [CreditoDeTienda::METODO];

    /** Los que el sistema sabe cobrar de verdad. */
    public static function metodosQueSeCobran(): array
    {
        return array_merge(self::CONTRA_ENTREGA, self::CON_PASARELA, self::CREDITO_DE_TIENDA);
    }

    public function __construct(private readonly PaymentController $pagos)
    {
    }

    public function cobrar(
        OrdersSales $order,
        iterable $lineas,
        $precios,
        Request $request,
        $bold,
    ): ?object {
        
                // 1️⃣ Crear intent
                $intent = $this->pagos->createIntent($order, $bold);

                // 2️⃣ Payer: si la app no lo manda, se construye desde el
                //    perfil del comprador con el formato EXACTO que exige
                //    Bold (person_type, document y billing_address son
                //    obligatorios). Mismos fallbacks que usa dev97.
                $payer = $request->payer;

                if (!$payer) {
                    /*
                     * `$buyer` Y `$address` NO EXISTÍAN EN ESTE MÉTODO.
                     *
                     * No son parámetros ni se declaran en ninguna parte: eran
                     * dos variables sueltas que quedaron al extraer este bloque
                     * de `OrderController::store`, donde sí vivían. En PHP 8
                     * eso no es un aviso, es una excepción, y como el `catch`
                     * de arriba la convertía en «Error al crear la orden» sin
                     * escribir NADA en el registro, el fallo era invisible.
                     *
                     * Y es un fallo total: la app NUNCA manda `payer` —mira el
                     * cuerpo que arma PaymentScreen—, así que esta rama se
                     * ejecuta SIEMPRE. Ningún cobro con pasarela, ni QR ni
                     * tarjeta, podía completarse.
                     *
                     * Salen del pedido, que es quien los tiene y quien manda:
                     * el comprador y la dirección del pedido son los del cobro,
                     * no los que venga a decir la petición.
                     */
                    $payerUser = $order->buyer?->user;
                    $address   = $order->address;

                    $phone = $payerUser?->phone ?? '3000000000';
                    $addressStr = $address?->address ?? 'Calle 1';
                    $city = $address?->municipality?->name ?? 'Barranquilla';
                    $province = $address?->municipality?->department?->name ?? 'Atlántico';

                    $payer = [
                        'person_type' => 'NATURAL_PERSON',
                        'name' => $payerUser?->name ?? 'Cliente',
                        'phone' => $phone,
                        'email' => $payerUser?->email ?? 'correo@ejemplo.com',
                        'document_type' => 'CEDULA',
                        'document_number' => '1234567890',
                        'billing_address' => [
                            'street1' => $addressStr,
                            'street2' => '',
                            'city' => $city,
                            'zip_code' => '110111',
                            'province' => $province,
                            'country' => 'CO',
                            'phone' => $phone,
                        ],
                    ];
                }

                // 3️⃣ Método de pago
                $paymentMethod = $request->methods_id == 2
                    ? array_merge(['name' => 'CREDIT_CARD'], $request->payment_method)
                    : [
                        'name' => 'QR',
                        'qr_format' => 'BOLD_BASE64' //CLAVE puede ser ese o TEXT o BASE64
                    ];

                // 4️⃣ Productos (con los precios reales del servidor)
                $products = collect($lineas)->map(fn($p) => [
                    'product_id' => $p['product_id'],
                    'amount' => (int) $p['amount'],
                    'unit_price' => (float) $precios[$p['product_id']],
                ])->toArray();

                // 5️⃣ Ejecutar pago
                $payment = $this->pagos->createPayment(
                    $order,
                    $intent,
                    $payer,
                    $paymentMethod,
                    $products,
                    $request,
                    $bold
                );

                /*
                 * 6️⃣ Estado del pago.
                 *
                 * Si la pasarela aprobó en el momento, el pedido pasa a existir
                 * ya —y ahí se anuncia a la tienda—. Si no, se queda esperando:
                 * puede que el cobro siga procesándose y lo confirme el webhook
                 * más tarde, o puede que lo rechace.
                 */
                if ($payment->payment_status) {
                    ConfirmacionDePago::confirmar($order);
                } else {
                    $order->payment_state = OrdersSales::ESPERANDO_PAGO;
                    $order->save();
                }

        /*
         * SE DEVUELVEN LOS DOS, Y EL PAGO ES EL QUE IMPORTABA.
         *
         * Esto devolvía solo el intent, así que el `$payment` —con el QR, el
         * enlace y el estado del cobro— moría acá dentro. En el controlador,
         * `isset($payment)` miraba una variable que NUNCA se asignaba: siempre
         * era false, y `action` viajaba en null en todas las respuestas.
         *
         * Resultado: aunque Bold devolviera un QR perfecto, la app no lo veía
         * jamás y enseñaba «la pasarela no devolvió forma de pagar». El pago
         * en línea no ha podido completarse nunca por esta línea.
         */
        return (object) ['intent' => $intent, 'payment' => $payment];
    }
}
