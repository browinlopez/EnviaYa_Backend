<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class BoldService
{
    protected string $apiUrl;
    protected string $apiKey;

    public function __construct()
    {
        $this->apiUrl = config('services.bold.base_url');
        $this->apiKey = config('services.bold.api_key');
    }

    protected function headers(): array
    {
        return [
            'Authorization' => 'x-api-key ' . $this->apiKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    public function createIntent(array $body): array
    {
        $response = Http::withHeaders($this->headers())
            ->post("{$this->apiUrl}/v1/payment-intent", $body);

        if ($response->failed()) {
            throw new \Exception($response->body());
        }

        return $response->json();
    }

    public function makePayment(array $body): array
    {
        $response = Http::withHeaders($this->headers())
            ->post("{$this->apiUrl}/v1/payment", $body);

        if ($response->failed()) {
            throw new \Exception(json_encode($response->json()));
        }

        return $response->json()['payload'] ?? $response->json();
    }

    /**
     * ANULAR: se deshace el cobro del mismo día, antes de las 9 p. m.
     *
     * Es la devolución buena cuando se llega a tiempo: la plata no llega a
     * salir de la cuenta del cliente, así que no hay que esperar días a que
     * vuelva. Pasada esa hora Bold ya no la admite y toca el reembolso.
     *
     * SOLO TARJETA. Con QR o PSE responde que no, y por eso el servicio de
     * devolución ni lo intenta.
     */
    public function anular(string $transactionId): array
    {
        $response = Http::withHeaders($this->headers())
            ->post("{$this->apiUrl}/v1/payment/void", [
                'transaction_id' => $transactionId,
            ]);

        if ($response->failed()) {
            throw new \Exception($response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * REEMBOLSAR: devolver un cobro que ya no se puede anular.
     *
     * No es inmediato —Bold lo revisa y lo aprueba— así que lo que queda tras
     * llamar a esto es una SOLICITUD, no un hecho. El estado se consulta con
     * `estadoDeDevolucion`.
     *
     * SOLO TARJETA, igual que la anulación.
     */
    public function devolver(string $referenceId, string $transactionId, string $motivo): array
    {
        $response = Http::withHeaders($this->headers())
            ->post("{$this->apiUrl}/v1/payment/refund", [
                'reference_id'   => $referenceId,
                'transaction_id' => $transactionId,
                'reason'         => $motivo,
            ]);

        if ($response->failed()) {
            throw new \Exception($response->body());
        }

        return $response->json() ?? [];
    }

    /** En qué va el reembolso: PROCESSING, APPROVED o REJECTED. */
    public function estadoDeDevolucion(string $transactionId): array
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->apiUrl}/v1/payment/refund/{$transactionId}");

        if ($response->failed()) {
            throw new \Exception($response->body());
        }

        return $response->json()['payload'] ?? $response->json();
    }

    /**
     * Los bancos que admiten PSE, tal como los da Bold.
     *
     * La lista NO se escribe a mano ni se guarda: son 51 entidades y cambian
     * —entran billeteras, se fusionan bancos—. Un listado nuestro que se
     * quede viejo manda al cliente a un banco que ya no existe, y eso no
     * falla al enviarlo: falla cuando ya está en la pasarela.
     */
    public function bancosPse(): array
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->apiUrl}/v1/payment/pse/banks");

        if ($response->failed()) {
            throw new \Exception($response->body());
        }

        return $response->json()['payload'] ?? $response->json();
    }

    public function checkPayment(string $reference): array
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->apiUrl}/v1/payment/{$reference}");

        if ($response->failed()) {
            throw new \Exception($response->body());
        }

        return $response->json()['payload'] ?? $response->json();
    }
}