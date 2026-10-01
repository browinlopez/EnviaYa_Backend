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