<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Recibe y procesa los webhooks de Bold y otras pasarelas
     */
    public function handle(string $gateway, Request $request): JsonResponse
    {
        try {
            $payload = $request->all();
            $headers = $request->headers->all();

            Log::info("🔔 [WebhookController] Recibido webhook para la pasarela: {$gateway}");

            // Resolver dinámicamente el servicio de la pasarela
            $gatewayService = $this->resolveGatewayService($gateway);

            if (!$gatewayService) {
                Log::warning("⚠️ [WebhookController] Pasarela no soportada o no implementada: {$gateway}");
                return response()->json([
                    'success' => false,
                    'message' => "Gateway {$gateway} not supported"
                ], 400);
            }

            // Procesar y reconciliar usando el handleWebhook del propio Service de la pasarela
            $result = $gatewayService->handleWebhook($payload, $headers);

            return response()->json([
                'success' => true,
                'message' => 'Webhook processed successfully',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error("❌ [WebhookController] Error procesando webhook de {$gateway}: " . $e->getMessage());
            
            $code = $e->getCode();
            $httpCode = (is_numeric($code) && $code >= 400 && $code < 600) ? $code : 400;

            return response()->json([
                'success' => false,
                'message' => 'Error processing webhook',
                'error' => $e->getMessage()
            ], $httpCode);
        }
    }

    /**
     * Resuelve el servicio de la pasarela a partir del parámetro de la ruta
     */
    protected function resolveGatewayService(string $gateway)
    {
        // Mapa de pasarelas soportadas a sus clases de servicio correspondientes
        $map = [
            'bold' => \App\Services\BoldService::class,
            // 'wompi' => \App\Services\WompiService::class,
            // 'stripe' => \App\Services\StripeService::class,
        ];

        $gatewayKey = strtolower($gateway);

        if (!isset($map[$gatewayKey])) {
            return null;
        }

        // Resolviendo dinámicamente usando el inyector de dependencias de Laravel
        return app($map[$gatewayKey]);
    }
}
