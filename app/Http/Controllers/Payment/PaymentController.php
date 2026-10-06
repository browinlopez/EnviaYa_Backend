<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Order\OrdersSales;
use App\Models\Payment\Payment;
use App\Models\Payment\PaymentIntent;
use App\Services\BoldService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    /**
     * Crear intención de pago
     */
    public function createIntent(OrdersSales $order, BoldService $bold): PaymentIntent
    {
        $reference = 'ORD-' . $order->orderSales_id . '-' . Str::upper(Str::random(8));

        $body = [
            "reference_id" => $reference,
            "amount" => [
                "currency" => "COP",
                "total_amount" => (int) $order->total
            ],
            "description" => "Pago orden #{$order->orderSales_id}",

            /*
             * POR DÓNDE VUELVE LA PERSONA.
             *
             * Iba en null, y la documentación de Bold dice que es OBLIGATORIO
             * para PSE y para el botón de Bancolombia: son los que sacan al
             * cliente de la app y lo meten en la página del banco. Sin esta
             * URL, al aprobar el pago se queda ahí plantado, mirando una
             * pantalla de Bold sin forma de volver.
             *
             * Apunta a una página del sitio y no al esquema de la app
             * (`vecipaya://`) a propósito: Bold espera https, y los bancos
             * filtran lo que no lo sea. Esa página rebota al teléfono y, si no
             * puede, al menos le dice a la persona que su pago siguió su curso.
             */
            "callback_url" => rtrim((string) config('services.sitio.url'), '/')
                . '/pago/volver?ref=' . $reference,

            "customer" => [
                "name" => $order->buyer->user->name ?? "Cliente",
                "phone" => $order->buyer->user->phone ?? "3000000000",
                "email" => $order->buyer->user->email ?? "test@test.com",

                /*
                 * `->name`, no la relación entera.
                 *
                 * `municipality` es un belongsTo y `department` un
                 * hasOneThrough: sin `->name` acá iba el MODELO COMPLETO
                 * serializado —id, department_id, created_at y todo—, y Bold
                 * responde `PI_001: customer: str type expected` y no crea la
                 * orden de pago.
                 *
                 * Estuvo así desde que se añadió este bloque y parecía
                 * funcionar porque las direcciones de prueba no tenían
                 * municipio: la relación resolvía a null, y null sí lo acepta.
                 * En cuanto una dirección está bien rellenada —que es el caso
                 * de cualquier cliente real— el pago falla.
                 *
                 * Se deja pasar el null cuando no hay municipio, que es lo que
                 * la pasarela ya venía admitiendo.
                 */
                "billing_address" => [
                    "street1" => $order->address->address,
                    "street2" => "",
                    "city" => $order->address->municipality?->name,
                    "postal_code" => "130001", // ⚠️ IMPORTANTE
                    "province" => $order->address->department?->name,
                    "country_code" => "CO",
                    "phone" => $order->buyer->user->phone ?? "3000000000"
                ]
            ]
        ];

        $response = $bold->createIntent($body);

        return PaymentIntent::create([
            'orderSales_id'     => $order->orderSales_id,
            'provider'          => 'bold',
            'bold_reference_id' => $reference,
            'amount'            => $order->total,
            'currency'          => 'COP',
            'status'            => $response['payload']['status'] ?? null,
            'response'          => $response,
        ]);
    }
    /**
     * Crear pago (TARJETA / QR)
     */
    public function createPayment(
        OrdersSales $order,
        PaymentIntent $intent,
        array $payer,
        array $paymentMethod,
        array $products,
        Request $request,
        BoldService $bold
    ): Payment {

        $body = [
            "reference_id" => $intent->bold_reference_id,
            "payer" => $payer,
            "payment_method" => $paymentMethod,
            "amount" => [
                "currency" => "COP",
                "total_amount" => (int) $order->total
            ],
            /* "products" => $products, */
            "metadata" => [
                "key" => "order_id",
                "value" => (string) $order->orderSales_id
            ],
            "device_fingerprint" => [
                "device_type" => "MOBILE",
                "os" => "Android",
                "model" => "ReactNative",
                "browser" => "MobileApp",
                "java_enabled" => false,
                "language" => "es",
                "color_depth" => 24,
                "screen_height" => 800,
                "screen_width" => 400,
                "time_zone_offset" => -300,
            ]
        ];

        $boldResponse = $bold->makePayment($body);

        // Manejo del QR
        $qrPayload = null;
        $qrExpiresAt = null;
        $next = $boldResponse['next_actions'] ?? [];

        $redirectUrl = $next['redirect_url'] ?? null;

        // 🔥 Soporta ambos formatos (nuevo y viejo de Bold)
        $qrPayload = $next['qr_payload']
            ?? ($next['qr']['payload'] ?? null);

        $qrExpiresAt = null;

        // formato nuevo (expires_at en timestamp)
        $qrExpiresAt = null;

        // 🔥 FIX correcto
        if (isset($next['expires_at'])) {
            $qrExpiresAt = now()->addMinutes(10);
        }

        // fallback viejo
        elseif (isset($next['qr']['expires_in'])) {
            $qrExpiresAt = now()->addSeconds(
                (int) $next['qr']['expires_in']
            );
        }

        // Guardamos el intento de pago como "running" / pending
        return Payment::create([
            'orderSales_id' => $order->orderSales_id,
            'methods_id' => $order->methods_id,
            'provider' => 'bold',
            'provider_payment_id' => $boldResponse['transaction_id'] ?? null,
            'amount' => $order->total,
            // Desglose real de la orden. `subtotal` traía el total completo y
            // `domicilio` ni se enviaba, así que quedaba en 0: por eso los
            // pedidos pagados en línea no le generaban ingreso al domiciliario.
            'subtotal' => $order->subtotal,
            'domicilio' => $order->domicilio,
            'domiciliary_fee' => $order->domiciliary_fee,
            'total' => $order->total,
            'status' => 'running', // estado inicial
            'payment_status' => 0,  // aún no aprobado
            'provider_snapshot' => $boldResponse,
            'redirect_url' => $redirectUrl,
            'qr_payload' => $qrPayload,
            'qr_expires_at' => $qrExpiresAt,
            'payment_date' => now(),
            'state' => 1,
        ]);
    }

    /**
     * Consultar estado del pago por query string (GET /payment/status).
     *
     * La app móvil (migrada a la API de dev97) manda el order_id numérico
     * como referenceId; internamente el estado se consulta con la referencia
     * Bold del intent más reciente de esa orden. Devuelve además `status` en
     * la raíz porque la app lo lee ahí.
     */
    public function checkStatusByReference(\Illuminate\Http\Request $request, BoldService $bold)
    {
        $reference = (string) $request->query('referenceId', '');

        if ($reference === '') {
            return response()->json(['message' => 'referenceId es requerido'], 422);
        }

        if (ctype_digit($reference)) {
            $intent = PaymentIntent::where('orderSales_id', $reference)
                ->orderByDesc('id')
                ->first();

            if (!$intent) {
                return response()->json(['message' => 'No hay intento de pago para esa orden'], 404);
            }

            $reference = $intent->bold_reference_id;
        }

        $payload = $this->checkStatus($reference, $bold)->getData(true);
        $payload['status'] = $payload['payment_status'] ?? null;

        return response()->json($payload);
    }

    /**
     * Consultar estado del pago
     */
    public function checkStatus(string $reference, BoldService $bold)
    {
        $data = $bold->checkPayment($reference);
        $status = strtoupper($data['status'] ?? '');

        $intent = PaymentIntent::where('bold_reference_id', $reference)->firstOrFail();
        $order  = OrdersSales::findOrFail($intent->orderSales_id);

        // Buscamos el pago existente
        $payment = Payment::where('orderSales_id', $order->orderSales_id)
            ->where('provider_payment_id', $data['transaction_id'] ?? null)
            ->first();

        if ($payment) {
            // Solo actualizamos si estaba en 'running'
            if ($payment->status === 'running') {
                /*
                 * CONSULTAR EL ESTADO NO PUEDE BORRAR EL QR.
                 *
                 * Acá había dos fallos que se tapaban entre sí:
                 *
                 *  1. Leía `next_actions.qr.payload`, la forma VIEJA. Bold
                 *     manda `next_actions.qr_payload` —es la que usa el alta,
                 *     dos métodos más arriba—, así que esto salía null SIEMPRE.
                 *  2. Y ese null se guardaba encima del QR bueno.
                 *
                 * Resultado: el primer sondeo —a los 4 segundos— borraba el
                 * código que el cliente tenía delante. Si además la respuesta
                 * venía sin `next_actions`, se perdía la única forma de pagar
                 * que había.
                 *
                 * Ahora solo se pisa lo que de verdad llega: sin noticia del
                 * QR, se queda el que ya estaba.
                 */
                $siguiente = $data['next_actions'] ?? [];

                $redirectUrl = $siguiente['redirect_url'] ?? null;
                $qrPayload = $siguiente['qr_payload']
                    ?? ($siguiente['qr']['payload'] ?? null);

                $segundos = $siguiente['expires_in']
                    ?? ($siguiente['qr']['expires_in'] ?? null);
                $qrExpiresAt = $segundos ? now()->addSeconds((int) $segundos) : null;

                $cambios = [
                    'payment_status' => $status === 'APPROVED' ? 1 : 0,
                    'status' => strtolower($status),
                    'provider_snapshot' => $data,
                    'payment_date' => now(),
                ];

                if ($redirectUrl) $cambios['redirect_url'] = $redirectUrl;
                if ($qrPayload) $cambios['qr_payload'] = $qrPayload;
                if ($qrExpiresAt) $cambios['qr_expires_at'] = $qrExpiresAt;

                $payment->update($cambios);

                // Actualizamos el estado de la orden según resultado
                $order->update([
                    'payment_state' => $status === 'APPROVED' ? 'paid' : 'pending_online'
                ]);
            }
        }

        return response()->json([
            'payment_status' => $status,
            'order_payment_state' => $order->payment_state,
            'data' => $data
        ]);
    }
}
