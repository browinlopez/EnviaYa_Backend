<?php

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use App\Models\OrderSale;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\Webhook;
use App\Models\PaymentAttempt;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use App\Traits\ValidateVerificationDigit;
use Log;

class BoldService implements PaymentGatewayInterface
{
    use ValidateVerificationDigit;
    protected string $apiUrl;
    protected string $apiKey;

    public function __construct()
    {
        $this->apiUrl = config('services.bold.base_url');
        $this->apiKey = config('services.bold.public_key');
    }

    protected function headers(): array
    {
        return [
            'Authorization' => 'x-api-key ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    public function createIntent(array $body): array
    {
        Log::info('--- BOLD REQUEST: createIntent ---', $body);

        $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Log formatted JSON precisely like console.log(JSON.stringify(payload, null, 2))
        Log::info("📤 ENVIANDO PAYLOAD BOLD (payment-intent):\n" . json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        file_put_contents(base_path('bold_createIntent_request.json'), json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $headers = $this->headers();
        $response = Http::withHeaders($headers)
            ->withBody($jsonBody, 'application/json')
            ->post("{$this->apiUrl}/v1/payment-intent");

        if ($response->failed()) {
            Log::error('--- BOLD ERROR: createIntent ---', ['response' => $response->body()]);
            $errResponse = json_decode($response->body(), true) ?? $response->body();
            file_put_contents(base_path('bold_createIntent_error.json'), json_encode($errResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $this->logTraceability('/v1/payment-intent', $body, $errResponse, $headers);
            throw new \Exception($response->body());
        }

        $resJson = $response->json();
        Log::info('--- BOLD SUCCESS: createIntent ---', $resJson);
        file_put_contents(base_path('bold_createIntent_success.json'), json_encode($resJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->logTraceability('/v1/payment-intent', $body, $resJson, $headers);
        return $resJson;
    }

    public function makePayment(array $body): array
    {
        Log::info('--- BOLD REQUEST: makePayment ---', $body);

        $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Log formatted JSON precisely like console.log(JSON.stringify(payload, null, 2))
        Log::info("📤 ENVIANDO PAYLOAD BOLD (payment):\n" . json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        file_put_contents(base_path('bold_makePayment_request.json'), json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $headers = $this->headers();
        $response = Http::withHeaders($headers)
            ->withBody($jsonBody, 'application/json')
            ->post("{$this->apiUrl}/v1/payment");

        if ($response->failed()) {
            Log::error('--- BOLD ERROR: makePayment ---', ['response' => $response->json()]);
            $errResponse = $response->json();
            file_put_contents(base_path('bold_makePayment_error.json'), json_encode($errResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $this->logTraceability('/v1/payment', $body, $errResponse, $headers);
            throw new \Exception(json_encode($errResponse));
        }

        $responseData = $response->json()['payload'] ?? $response->json();
        Log::info('--- BOLD SUCCESS: makePayment ---', $responseData);
        file_put_contents(base_path('bold_makePayment_success.json'), json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->logTraceability('/v1/payment', $body, $responseData, $headers);
        return $responseData;
    }

    public function checkPayment(string $reference): array
    {
        Log::info("--- BOLD REQUEST: checkPayment --- reference: {$reference}");

        $response = Http::withHeaders($this->headers())
            ->get("{$this->apiUrl}/v1/payment/{$reference}");

        if ($response->failed()) {
            Log::error('--- BOLD ERROR: checkPayment ---', ['response' => $response->body()]);
            file_put_contents(base_path('bold_checkPayment_error.json'), json_encode(json_decode($response->body()) ?? $response->body(), JSON_PRETTY_PRINT));
            throw new \Exception($response->body());
        }

        $responseData = $response->json()['payload'] ?? $response->json();
        Log::info('--- BOLD SUCCESS: checkPayment ---', $responseData);
        file_put_contents(base_path('bold_checkPayment_success.json'), json_encode($responseData, JSON_PRETTY_PRINT));
        return $responseData;
    }

    public function handleWebhook(array $payload, array $headers): array
    {
        // Extraer firma de headers
        // En Laravel las cabeceras pueden venir mapeadas como arreglos de un elemento
        $signature = $headers['x-bold-signature'][0] ?? ($headers['bold-signature'][0] ?? ($headers['x-bold-signature'] ?? ($headers['bold-signature'] ?? '')));
        $source = 'bold';

        if (isset($payload['bold-tx-status']) || isset($payload['bold-order-id'])) {
            $source = 'redirection_url';
        }

        Log::info("🔔 [BoldService Webhook] Recibida petición. Origen: {$source}");

        // Si es Bold oficial y trae firma, la verificamos
        if ($source === 'bold' && !empty($signature)) {
            $verified = $this->verifySignature($payload, $signature);
            if (!$verified) {
                Log::warning('⚠️ [BoldService Webhook] Firma inválida detectada.');
                throw new \Exception('Invalid Bold signature', 401);
            }
        }

        $boldOrderId = null;
        $status = 'pending';

        if ($source === 'redirection_url') {
            $boldOrderId = $payload['bold-order-id'] ?? ($payload['reference_id'] ?? null);
            $status = $payload['bold-tx-status'] ?? 'pending';
        } elseif ($source === 'bold') {
            $boldOrderId = $payload['data']['metadata']['reference']
                ?? ($payload['data']['metadata']['order_ref']
                    ?? ($payload['reference_id'] ?? null));
            $status = $payload['type'] ?? ($payload['status'] ?? 'pending');
        }

        Log::info("🔍 [BoldService Webhook] Buscando Bold ID / Reference: {$boldOrderId}");

        if (!$boldOrderId) {
            Log::error("❌ [BoldService Webhook] Error: No se encontró reference_id en el payload.");
            throw new \Exception("No se pudo extraer el reference_id del webhook payload.");
        }

        // Búsqueda en orders_sales
        $orderId = null;
        if (preg_match('/^(?:ORD|ORDER)[-_]?(\d+)/i', $boldOrderId, $matches)) {
            $orderId = (int) $matches[1];
        }

        $order = null;
        if ($orderId) {
            $order = OrderSale::with(['details', 'payments'])->find($orderId);
        }

        if (!$order) {
            $intent = PaymentIntent::where('bold_reference_id', $boldOrderId)->first();
            if ($intent) {
                // Intentamos buscar por order_sale_id u order_sales_id según corresponda
                $orderSalesId = $intent->order_sales_id ?? $intent->order_sale_id;
                $order = OrderSale::with(['details', 'payments'])->find($orderSalesId);
            }
        }

        // Crear registro en la tabla webhooks
        $webhook = Webhook::create([
            'source' => $source,
            'status' => $status,
            'payload' => $payload,
            'order_sale_id' => $order ? $order->id : null,
            'payment_id' => $order?->payments?->id ?? null,
        ]);

        Log::info("✅ [BoldService Webhook] Webhook registrado con ID: {$webhook->id}");

        if ($order) {
            return $this->handlePaymentResult($order, $boldOrderId, $status, $payload, $webhook);
        }

        Log::warning("⚠️ [BoldService Webhook] No se encontró orden coincidente.");
        return ['message' => 'No matching order found.'];
    }

    protected function verifySignature(array $payload, string $signature): bool
    {
        try {
            // Si el webhook es de pruebas de link o botón de pagos, la doc indica que la clave secreta es un string vacío
            $secretKey = env('BOLD_SIGNING_SECRET') ?? '';

            $strPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $base64Payload = base64_encode($strPayload);

            $calculatedSignature = hash_hmac('sha256', $base64Payload, $secretKey);

            return hash_equals($calculatedSignature, $signature);
        } catch (\Exception $e) {
            Log::error('❌ Error verifying Bold webhook signature: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Procesa y sincroniza los estados de la orden y el pago
     */
    protected function handlePaymentResult(OrderSale $order, string $boldOrderId, string $status, array $txData, Webhook $webhook): array
    {
        Log::info("🔍 [BoldService Webhook] handlePaymentResult para orden: {$order->id}");

        $statusUpper = strtoupper($status);
        $approvedStatuses = ['APPROVED', 'SALE_APPROVED', 'VOID_APPROVED', 'SUCCESS'];
        $rejectedStatuses = ['REJECTED', 'SALE_REJECTED', 'VOID_REJECTED', 'FAILED', 'CANCELLED', 'DECLINED', 'ERROR'];

        $finalPaymentState = 'pending_online';
        $paymentStatus = 0;
        $paymentStatusString = 'pending';

        if (in_array($statusUpper, $approvedStatuses) || str_contains($statusUpper, 'APPROV')) {
            $finalPaymentState = 'paid';
            $paymentStatus = 1;
            $paymentStatusString = 'approved';
        } elseif (in_array($statusUpper, $rejectedStatuses) || str_contains($statusUpper, 'REJECT') || str_contains($statusUpper, 'FAIL') || str_contains($statusUpper, 'CANCEL')) {
            $finalPaymentState = 'failed';
            $paymentStatus = 0;
            $paymentStatusString = 'rejected';
        }

        if ($order->payment_state === 'paid') {
            Log::info("ℹ️ [BoldService Webhook] Orden {$order->id} ya está marcada como PAID. Saltando.");
            return ['order_id' => $order->id, 'status' => 'paid'];
        }

        $order->payment_state = $finalPaymentState;
        $order->save();

        Log::info("📝 [BoldService Webhook] Estado de pago de la orden {$order->id} actualizado a: {$finalPaymentState}");

        $transactionId = $txData['data']['payment_id']
            ?? ($txData['subject']
                ?? ($txData['transaction_id']
                    ?? ($txData['data']['transaction_id']
                        ?? ($txData['id'] ?? 'N/A'))));

        $amountTotal = $txData['data']['amount']['total']
            ?? ($txData['amount']['total']
                ?? ($txData['amount']['total_amount']
                    ?? ($txData['data']['amount']['total_amount']
                        ?? $order->total)));

        $payment = Payment::where('order_sale_id', $order->id)->first();

        if (!$payment) {
            $payment = Payment::create([
                'order_sale_id' => $order->id,
                'methods_id' => $order->methods_id,
                'forms_id' => $order->forms_id,
                'provider' => 'bold',
                'provider_payment_id' => $transactionId,
                'amount' => 1,
                'subtotal' => $order->total,
                'total' => $amountTotal,
                'payment_status' => $paymentStatus,
                'status' => $paymentStatusString,
                'provider_snapshot' => $txData,
                'payment_date' => now(),
                'state' => 1
            ]);
            Log::info("💳 [BoldService Webhook] Pago creado para la orden {$order->id} con ID: {$payment->id}");
        } else {
            $payment->update([
                'provider_payment_id' => $transactionId,
                'total' => $amountTotal,
                'payment_status' => $paymentStatus,
                'status' => $paymentStatusString,
                'provider_snapshot' => $txData,
                'payment_date' => now(),
            ]);
            Log::info("💳 [BoldService Webhook] Pago existente actualizado para la orden {$order->id}");
        }

        $webhook->update([
            'payment_id' => $payment->id
        ]);

        // Actualizar el estado de la intención de pago
        $intent = PaymentIntent::where('bold_reference_id', $boldOrderId)->first();
        if ($intent) {
            $intent->update(['status' => $statusUpper]);
            Log::info("📝 [BoldService Webhook] PaymentIntent ({$boldOrderId}) actualizado a estado: {$statusUpper}");
        }

        // Avisar en tiempo real (websocket) al comprador que está esperando el resultado del pago
        \App\Events\PaymentStatusUpdated::dispatch($order->id, $finalPaymentState, $paymentStatusString);

        return [
            'order_id' => $order->id,
            'status' => $finalPaymentState,
            'payment_id' => $payment->id
        ];
    }

    /**
     * Inicia el flujo de pago específico de Bold (creando intención y procesando el intento de pago).
     */
    public function initiatePaymentFlow(OrderSale $order, array $data): array
    {
        // Aseguramos que estén cargadas las relaciones necesarias
        $order->loadMissing([
            'buyer.user',
            'buyer.TypeDocumentIdentification',
            'address.municipality.department.country',
            'address.alias'
        ]);

        $reference = 'ORD-' . $order->id;

        $addressStr = $order->address->address ?? "Calle 1";
        $city = $order->address->municipality->name ?? "Bogotá";
        $province = $order->address->department->name ?? "Cundinamarca";

        $countryCode = "CO";
        if ($order->address && $order->address->municipality && $order->address->municipality->department && $order->address->municipality->department->country) {
            $code = $order->address->municipality->department->country->code;
            if (strlen($code) === 2) {
                $countryCode = strtoupper($code);
            }
        }

        $phone = $order->buyer->user->phone ?? "3000000000";
        $email = $order->buyer->user->email ?? "correo@ejemplo.com";
        $buyerName = $order->buyer->user->name ?? "Cliente";

        $clientIp = request()->ip();
        if (!$clientIp || $clientIp === '127.0.0.1' || $clientIp === '::1' || str_starts_with($clientIp, '192.168.')) {
            $clientIp = '186.116.10.20'; // Standard public Colombian IP
        }

        // Normalizar el device fingerprint provisto por el cliente
        $normalizedFingerprint = $this->normalizeDeviceFingerprint($data['device_fingerprint'] ?? []);

        // 1️⃣ Construir primer body (Payment Intent)
        $intentBody = [
            "reference_id" => $reference,
            "amount" => [
                "currency" => "COP",
                "total_amount" => (int) $order->total,
                "tip_amount" => 0,
                "taxes" => []
            ],
            "description" => "Pago orden #{$order->id}",
            "metadata" => [
                "key" => "order_id",
                "value" => (string) $order->id
            ],
            "customer" => [
                "name" => $buyerName,
                "phone" => $phone,
                "email" => $email,
                "billing_address" => [
                    "street1" => $addressStr,
                    "street2" => "",
                    "city" => $city,
                    "province" => $province,
                    "phone" => $phone
                ],
                "shipping_address" => [
                    "street1" => $addressStr,
                    "street2" => "",
                    "city" => $city,
                    "province" => $province,
                    "phone" => $phone
                ]
            ],
            "device_fingerprint" => $normalizedFingerprint
        ];

        Log::info('📤 [BOLD SERVICE INTENT BODY]', $intentBody);

        // Crear intent en Bold
        $intentResponse = $this->createIntent($intentBody);

        // Guardar Intent en la BD
        $intentRecord = PaymentIntent::create([
            'order_sale_id' => $order->id,
            'provider' => 'bold',
            'bold_reference_id' => $reference,
            'amount' => $order->total,
            'currency' => 'COP',
            'status' => $intentResponse['payload']['status'] ?? $intentResponse['status'] ?? 'ACTIVE',
            'payload' => $intentBody,
            'response' => $intentResponse,
        ]);

        // Registrar/obtener el registro en payments como pending
        $payment = Payment::where('order_sale_id', $order->id)->first();
        if (!$payment) {
            $payment = Payment::create([
                'order_sale_id' => $order->id,
                'methods_id' => $order->methods_id,
                'forms_id' => $order->forms_id,
                'provider' => 'bold',
                'provider_payment_id' => $reference,
                'amount' => 1,
                'subtotal' => $order->total,
                'total' => $order->total,
                'payment_status' => 0, // Pending
                'status' => 'pending',
                'state' => 1,
                'payment_date' => now(),
            ]);
        } else {
            $payment->update([
                'provider_payment_id' => $reference,
                'status' => 'pending',
                'payment_status' => 0,
            ]);
        }

        // 2️⃣ Construir segundo body (Payment Attempt)
        $paymentMethodReq = $data['payment_method'] ?? [];
        if (empty($paymentMethodReq)) {
            $pmId = $order->methods_id;
            if ($pmId == 5) {
                $paymentMethodReq = ['name' => 'QR', 'qr_format' => 'BOLD_BASE64'];
            } elseif ($pmId == 2) {
                $paymentMethodReq = ['name' => 'CREDIT_CARD'];
            } elseif ($pmId == 6) {
                $paymentMethodReq = ['name' => 'NEQUI'];
            }
        }

        if (strtoupper($paymentMethodReq['name'] ?? '') === 'QR') {
            $paymentMethodReq['qr_format'] = $paymentMethodReq['qr_format'] ?? 'BOLD_BASE64';
        }

        $payerBillingAddress = [
            "street1" => $addressStr,
            "street2" => "",
            "city" => $city,
            "zip_code" => "110111",
            "province" => $province,
            "country" => $countryCode,
            "phone" => $phone
        ];

        $payer = [
            "person_type" => "NATURAL_PERSON",
            "name" => $buyerName,
            "phone" => $phone,
            "email" => $email,
            "document_type" => $order->buyer->TypeDocumentIdentification->bold_name ?? 'CEDULA',
            "document_number" => $order->buyer->identification_number ?? '1234567890',
            "billing_address" => $payerBillingAddress
        ];

        $paymentBody = [
            "reference_id" => $reference,
            "metadata" => [
                "key" => "order_id",
                "value" => (string) $order->id
            ],
            "payer" => $payer,
            "payment_method" => $paymentMethodReq,
            "device_fingerprint" => $normalizedFingerprint
        ];

        Log::info('📤 [BOLD SERVICE PAYMENT BODY]', $paymentBody);

        // Procesar pago en Bold
        $paymentResponse = $this->makePayment($paymentBody);

        $transactionId = $paymentResponse['transaction_id'] ?? null;
        $status = $paymentResponse['status'] ?? 'RUNNING';

        // Procesar expiración si está presente en next_actions
        $expiresAt = $paymentResponse['next_actions']['expires_at'] ?? null;
        $qrExpiresAt = null;
        if ($expiresAt) {
            if (strlen((string) $expiresAt) > 10) {
                $qrExpiresAt = Carbon::createFromTimestamp((int) ($expiresAt / 1000000000));
            } else {
                $qrExpiresAt = Carbon::createFromTimestamp((int) $expiresAt);
            }
        }

        // Actualizar registro en payments con el resultado del intento de pago
        $payment->update([
            'provider_payment_id' => $transactionId ?? $reference,
            'status' => strtolower($status),
            'provider_snapshot' => $paymentResponse,
            'redirect_url' => $paymentResponse['next_actions']['redirect_url'] ?? null,
            'qr_payload' => $paymentResponse['next_actions']['qr_payload'] ?? null,
            'qr_expires_at' => $qrExpiresAt,
        ]);

        return [
            'order' => $order->load('details.product.category', 'business', 'address', 'promotions', 'payments')->toApi(),
            'bold_response' => $paymentResponse,
            'internalOrderId' => $order->id,
            'reference_id' => $reference,
            'transaction_id' => $transactionId,
            'status' => $status,
            'action' => [
                'type' => isset($paymentResponse['next_actions']['redirect_url']) ? 'REDIRECT' : (isset($paymentResponse['next_actions']['qr_payload']) ? 'QR' : 'NONE'),
                'qr_image' => null,
                'qr_payload' => $paymentResponse['next_actions']['qr_payload'] ?? null,
                'expires_at' => $qrExpiresAt?->toIso8601String(),
                'redirect_url' => $paymentResponse['next_actions']['redirect_url'] ?? null,
                'redirect_method' => $paymentResponse['next_actions']['redirect_method'] ?? 'POST'
            ]
        ];
    }

    /**
     * Consulta el estado del pago y lo sincroniza en Bold.
     */
    public function checkPaymentStatusFlow(string $reference): array
    {
        $data = $this->checkPayment($reference);
        $status = strtoupper($data['status'] ?? '');

        $intent = PaymentIntent::where('bold_reference_id', $reference)->firstOrFail();
        $order = OrderSale::findOrFail($intent->order_sale_id);

        $payment = Payment::where('order_sale_id', $order->id)
            ->where('provider_payment_id', $data['transaction_id'] ?? null)
            ->first();

        if ($payment) {
            if ($payment->status === 'running') {
                $redirectUrl = $data['next_actions']['redirect_url'] ?? null;
                $qrPayload = $data['next_actions']['qr']['payload'] ?? null;
                $qrExpiresAt = isset($data['next_actions']['qr']['expires_in'])
                    ? now()->addSeconds((int) $data['next_actions']['qr']['expires_in'])
                    : null;

                $payment->update([
                    'payment_status' => $status === 'APPROVED' ? 1 : 0,
                    'status' => strtolower($status),
                    'provider_snapshot' => $data,
                    'redirect_url' => $redirectUrl,
                    'qr_payload' => $qrPayload,
                    'qr_expires_at' => $qrExpiresAt,
                    'payment_date' => now(),
                ]);

                $order->update([
                    'payment_state' => $status === 'APPROVED' ? 'paid' : 'pending_online'
                ]);
            }
        }

        return [
            'payment_status' => $status,
            'order_payment_state' => $order->payment_state,
            'data' => $data
        ];
    }

    /**
     * Obtener información de una intención de pago en Bold.
     */
    public function getPaymentIntent(string $referenceId): array
    {
        Log::info("--- BOLD REQUEST: getPaymentIntent --- reference: {$referenceId}");

        $response = Http::withHeaders($this->headers())
            ->get("{$this->apiUrl}/v1/payment-intent/{$referenceId}");

        if ($response->failed()) {
            Log::error('--- BOLD ERROR: getPaymentIntent ---', ['response' => $response->body()]);
            throw new \Exception($response->body(), $response->status());
        }

        return $response->json();
    }

    /**
     * Actualizar información de una intención de pago en Bold.
     */
    public function updatePaymentIntent(array $body): array
    {
        Log::info("--- BOLD REQUEST: updatePaymentIntent ---", $body);

        $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $response = Http::withHeaders($this->headers())
            ->withBody($jsonBody, 'application/json')
            ->put("{$this->apiUrl}/v1/payment-intent");

        if ($response->failed()) {
            Log::error('--- BOLD ERROR: updatePaymentIntent ---', ['response' => $response->body()]);
            throw new \Exception($response->body(), $response->status());
        }

        return $response->json();
    }

    /**
     * Obtener el estado de un intento de pago.
     */
    public function getPaymentAttempt(string $referenceId): array
    {
        return $this->checkPayment($referenceId);
    }

    /**
     * Obtener el listado de bancos disponibles para PSE en Bold.
     */
    public function getPseBanks(): array
    {
        Log::info("--- BOLD REQUEST: getPseBanks ---");

        $response = Http::withHeaders($this->headers())
            ->get("{$this->apiUrl}/v1/payment/pse/banks");

        if ($response->failed()) {
            Log::error('--- BOLD ERROR: getPseBanks ---', ['response' => $response->body()]);
            throw new \Exception($response->body(), $response->status());
        }

        return $response->json();
    }

    /**
     * Realizar la anulación de un pago en Bold.
     */
    public function voidPayment(array $body): array
    {
        Log::info("--- BOLD REQUEST: voidPayment ---", $body);

        $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $response = Http::withHeaders($this->headers())
            ->withBody($jsonBody, 'application/json')
            ->post("{$this->apiUrl}/v1/payment/void");

        if ($response->failed()) {
            Log::error('--- BOLD ERROR: voidPayment ---', ['response' => $response->body()]);
            throw new \Exception($response->body(), $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * Solicitar el reembolso de un pago en Bold.
     */
    public function refundPayment(array $body): array
    {
        Log::info("--- BOLD REQUEST: refundPayment ---", $body);

        $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $response = Http::withHeaders($this->headers())
            ->withBody($jsonBody, 'application/json')
            ->post("{$this->apiUrl}/v1/payment/refund");

        if ($response->failed()) {
            Log::error('--- BOLD ERROR: refundPayment ---', ['response' => $response->body()]);
            throw new \Exception($response->body(), $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * Consultar el estado de un reembolso en Bold.
     */
    public function getRefundStatus(string $transactionId): array
    {
        Log::info("--- BOLD REQUEST: getRefundStatus --- transaction_id: {$transactionId}");

        $response = Http::withHeaders($this->headers())
            ->get("{$this->apiUrl}/v1/payment/refund/{$transactionId}");

        if ($response->failed()) {
            Log::error('--- BOLD ERROR: getRefundStatus ---', ['response' => $response->body()]);
            throw new \Exception($response->body(), $response->status());
        }

        return $response->json();
    }

    /**
     * Normaliza el device fingerprint provisto por el cliente
     */
    private function normalizeDeviceFingerprint(array $clientFingerprint = []): array
    {
        $ip = $clientFingerprint['ip'] ?? request()->ip();
        if (!$ip || $ip === '127.0.0.1' || $ip === '::1' || str_starts_with($ip, '192.168.')) {
            $ip = '186.116.10.20';
        }

        $deviceType = $clientFingerprint['device_type'] ?? env('BOLD_DEFAULT_DEVICE_TYPE', 'DESKTOP');
        $os = $clientFingerprint['os'] ?? env('BOLD_DEFAULT_OS', 'Linux');
        $model = $clientFingerprint['model'] ?? '';

        $browser = $clientFingerprint['browser'] ?? null;
        if (empty($browser)) {
            $browser = env('BOLD_DEFAULT_BROWSER', 'Chrome');
        } elseif ($browser === 'Google Chrome or Chromium') {
            $browser = 'Chrome';
        }

        $javaEnabled = filter_var($clientFingerprint['java_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $language = $clientFingerprint['language'] ?? null;
        if (empty($language) || $language === 'en') {
            $language = env('BOLD_DEFAULT_LANGUAGE', 'es-CO');
        }

        $colorDepth = (int) ($clientFingerprint['color_depth'] ?? 24);
        $screenHeight = (int) ($clientFingerprint['screen_height'] ?? 1080);
        $screenWidth = (int) ($clientFingerprint['screen_width'] ?? 1920);

        $timezoneOffset = $clientFingerprint['time_zone_offset'] ?? null;
        if ($timezoneOffset === null || (int) $timezoneOffset === 300) {
            $timezoneOffset = (int) env('BOLD_DEFAULT_TIMEZONE_OFFSET', -300);
        } else {
            $timezoneOffset = (int) $timezoneOffset;
        }

        return [
            "ip" => $ip,
            "device_type" => $deviceType,
            "os" => $os,
            "model" => $model,
            "browser" => $browser,
            "java_enabled" => $javaEnabled,
            "language" => $language,
            "color_depth" => $colorDepth,
            "screen_height" => $screenHeight,
            "screen_width" => $screenWidth,
            "time_zone_offset" => $timezoneOffset
        ];
    }

    /**
     * Reintenta el pago de una orden existente SIN crear una intención de pago en Bold.
     * Construye y envía directamente el payload a POST /v1/payment.
     */
    public function retryPaymentFlow(OrderSale $order, array $data): array
    {
        // 1. Asegurar relaciones
        $order->loadMissing([
            'buyer.user',
            'buyer.TypeDocumentIdentification',
            'address.municipality.department.country',
            'address.alias',
            'details.product.category',
            'business',
            'promotions',
            'payments'
        ]);

        // 2. Establecer reference_id
        $reference = 'ORDER-' . $order->id;

        // 3. Reconstruir datos base
        $addressStr = $order->address->address ?? "Calle 1";
        $city = $order->address->municipality->name ?? "Bogotá";
        $province = $order->address->department->name ?? "Cundinamarca";

        $countryCode = "CO";
        if ($order->address && $order->address->municipality && $order->address->municipality->department && $order->address->municipality->department->country) {
            $code = $order->address->municipality->department->country->code;
            if (strlen($code) === 2) {
                $countryCode = strtoupper($code);
            }
        }

        $phone = $order->buyer->user->phone ?? "3000000000";
        $email = $order->buyer->user->email ?? "correo@ejemplo.com";
        $buyerName = $order->buyer->user->name ?? "Cliente";

        // Obtener el método de pago del request o del fallback de la orden
        $paymentMethodReq = $data['payment_method'] ?? [];
        if (empty($paymentMethodReq)) {
            $pmId = $data['payment_method_id'] ?? $order->methods_id;
            if ($pmId == 5) {
                $paymentMethodReq = ['name' => 'QR', 'qr_format' => 'BOLD_BASE64'];
            } elseif ($pmId == 2) {
                $paymentMethodReq = ['name' => 'CREDIT_CARD'];
            } elseif ($pmId == 6) {
                $paymentMethodReq = ['name' => 'NEQUI'];
            }
        }

        if (strtoupper($paymentMethodReq['name'] ?? '') === 'QR') {
            $paymentMethodReq['qr_format'] = $paymentMethodReq['qr_format'] ?? 'BOLD_BASE64';
        }

        // Obtener el device fingerprint exacto del request, sin alteraciones (tal cual)
        $deviceFingerprint = $data['device_fingerprint'] ?? [];

        // Reconstruir el Payer Billing Address
        $payerBillingAddress = [
            "street1" => $addressStr,
            "street2" => "",
            "city" => $city,
            "zip_code" => "110111",
            "province" => $province,
            "country" => $countryCode,
            "phone" => $phone
        ];

        $payer = [
            "person_type" => "NATURAL_PERSON",
            "name" => $buyerName,
            "phone" => $phone,
            "email" => $email,
            "document_type" => $order->buyer->TypeDocumentIdentification->bold_name ?? 'CEDULA',
            "document_number" => $order->buyer->identification_number ?? '1234567890',
            "billing_address" => $payerBillingAddress
        ];

        // 6. Construir el payload directo para Bold POST /v1/payment
        $paymentBody = [
            "reference_id" => $reference,
            "metadata" => [
                "key" => "order_id",
                "value" => (string) $order->id
            ],
            "payer" => $payer,
            "payment_method" => $paymentMethodReq,
            "device_fingerprint" => $deviceFingerprint
        ];

        // Actualizar el método y forma en la orden
        $order->update([
            'methods_id' => $data['payment_method_id'] ?? $order->methods_id,
            'forms_id' => $data['payment_form_id'] ?? $order->forms_id,
        ]);

        try {
            // Procesar el pago en Bold (Llama a POST /v1/payment)
            $paymentResponse = $this->makePayment($paymentBody);

            $transactionId = $paymentResponse['transaction_id'] ?? null;
            $status = $paymentResponse['status'] ?? 'RUNNING';

            $expiresAt = $paymentResponse['next_actions']['expires_at'] ?? null;
            $qrExpiresAt = null;
            if ($expiresAt) {
                if (strlen((string) $expiresAt) > 10) {
                    $qrExpiresAt = Carbon::createFromTimestamp((int) ($expiresAt / 1000000000));
                } else {
                    $qrExpiresAt = Carbon::createFromTimestamp((int) $expiresAt);
                }
            }

            // Registrar/actualizar la tabla payments
            $payment = Payment::where('order_sale_id', $order->id)->first();
            if (!$payment) {
                $payment = Payment::create([
                    'order_sale_id' => $order->id,
                    'methods_id' => $order->methods_id,
                    'forms_id' => $order->forms_id,
                    'provider' => 'bold',
                    'provider_payment_id' => $transactionId ?? $reference,
                    'amount' => 1,
                    'subtotal' => $order->total,
                    'total' => $order->total,
                    'payment_status' => 0,
                    'status' => strtolower($status),
                    'provider_snapshot' => $paymentResponse,
                    'redirect_url' => $paymentResponse['next_actions']['redirect_url'] ?? null,
                    'qr_payload' => $paymentResponse['next_actions']['qr_payload'] ?? null,
                    'qr_expires_at' => $qrExpiresAt,
                    'state' => 1,
                    'payment_date' => now(),
                ]);
            } else {
                $payment->update([
                    'methods_id' => $order->methods_id,
                    'forms_id' => $order->forms_id,
                    'provider_payment_id' => $transactionId ?? $reference,
                    'status' => strtolower($status),
                    'provider_snapshot' => $paymentResponse,
                    'redirect_url' => $paymentResponse['next_actions']['redirect_url'] ?? null,
                    'qr_payload' => $paymentResponse['next_actions']['qr_payload'] ?? null,
                    'qr_expires_at' => $qrExpiresAt,
                    'payment_date' => now(),
                ]);
            }

            // Guardar o actualizar la "Intención de Pago" local para mapear la nueva referencia de reintento
            PaymentIntent::updateOrCreate(
                ['bold_reference_id' => $reference],
                [
                    'order_sale_id' => $order->id,
                    'provider' => 'bold',
                    'amount' => $order->total,
                    'currency' => 'COP',
                    'status' => strtoupper($status),
                    'payload' => $paymentBody,
                    'response' => $paymentResponse,
                ]
            );

            // Guardar historial de intento exitoso en BD
            PaymentAttempt::create([
                'order_sale_id' => $order->id,
                'reference_id' => $reference,
                'transaction_id' => $transactionId,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'payment_form_id' => $data['payment_form_id'] ?? null,
                'status' => strtolower($status),
                'request_payload' => $paymentBody,
                'response_payload' => $paymentResponse,
                'error_payload' => null,
            ]);

            return [
                'order' => $order->load('details.product.category', 'business', 'address', 'promotions', 'payments')->toApi(),
                'bold_response' => $paymentResponse,
                'internalOrderId' => $order->id,
                'reference_id' => $reference,
                'transaction_id' => $transactionId,
                'status' => $status,
                'action' => [
                    'type' => isset($paymentResponse['next_actions']['redirect_url']) ? 'REDIRECT' : (isset($paymentResponse['next_actions']['qr_payload']) ? 'QR' : 'NONE'),
                    'qr_image' => null,
                    'qr_payload' => $paymentResponse['next_actions']['qr_payload'] ?? null,
                    'expires_at' => $qrExpiresAt?->toIso8601String(),
                    'redirect_url' => $paymentResponse['next_actions']['redirect_url'] ?? null,
                    'redirect_method' => $paymentResponse['next_actions']['redirect_method'] ?? 'POST'
                ]
            ];

        } catch (\Exception $e) {
            $errData = json_decode($e->getMessage(), true) ?? ['message' => $e->getMessage()];

            // Registrar intento fallido en PaymentIntent local para trazabilidad y consultas futuras
            PaymentIntent::updateOrCreate(
                ['bold_reference_id' => $reference],
                [
                    'order_sale_id' => $order->id,
                    'provider' => 'bold',
                    'amount' => $order->total,
                    'currency' => 'COP',
                    'status' => 'FAILED',
                    'payload' => $paymentBody,
                    'response' => $errData,
                ]
            );

            // Guardar historial de intento fallido en BD
            PaymentAttempt::create([
                'order_sale_id' => $order->id,
                'reference_id' => $reference,
                'transaction_id' => null,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'payment_form_id' => $data['payment_form_id'] ?? null,
                'status' => 'failed',
                'request_payload' => $paymentBody,
                'response_payload' => null,
                'error_payload' => $errData,
            ]);

            throw $e;
        }
    }

    /**
     * Realiza un proxy crudo de la respuesta de Bold para consultar el estado del pago.
     */
    public function getRawPaymentStatus(string $referenceId)
    {
        Log::info("CONSULTANDO ESTADO BOLD: " . $referenceId);

        $headers = $this->headers();
        $response = Http::withHeaders($headers)
            ->get("{$this->apiUrl}/v1/payment/{$referenceId}");

        if ($response->failed()) {
            Log::error("❌ ERROR CONSULTANDO ESTADO BOLD: " . ($response->body() ?: 'No response body'));
        } else {
            Log::info("📥 RESPUESTA DIRECTA BOLD:\n" . json_encode($response->json() ?? $response->body(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $response;
    }

    /**
     * Registra un registro de trazabilidad en bold_traceability_log.json
     */
    private function logTraceability(string $endpoint, array $requestBody, $response, array $headers): void
    {
        try {
            $traceFile = base_path('bold_traceability_log.json');

            $existing = [];
            if (file_exists($traceFile)) {
                $existing = json_decode(file_get_contents($traceFile), true) ?: [];
            }

            if (count($existing) >= 50) {
                array_shift($existing);
            }

            $referenceId = $requestBody['reference_id'] ?? null;
            $transactionId = null;
            if (is_array($response)) {
                $transactionId = $response['transaction_id'] ?? ($response['payload']['transaction_id'] ?? null);
            }

            $newTrace = [
                'timestamp' => now()->toIso8601String(),
                'endpoint' => $endpoint,
                'reference_id' => $referenceId,
                'transaction_id' => $transactionId,
                'headers_sent' => [
                    'Accept' => $headers['Accept'] ?? 'application/json',
                    'Content-Type' => $headers['Content-Type'] ?? 'application/json',
                    'Authorization' => isset($headers['Authorization']) ? substr($headers['Authorization'], 0, 15) . '...' : 'none'
                ],
                'request_payload' => $requestBody,
                'response_received' => $response
            ];

            $existing[] = $newTrace;

            file_put_contents(
                $traceFile,
                json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        } catch (\Exception $e) {
            Log::error('❌ Error writing BOLD traceability log: ' . $e->getMessage());
        }
    }
}