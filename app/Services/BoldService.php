<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FalloDeLaPasarela;
use Illuminate\Http\Client\PendingRequest;
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
            'Authorization' => 'x-api-key '.$this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * La peticion a Bold, con tiempo de espera.
     *
     * SIN TOPE, UNA PASARELA LENTA TUMBA EL API. Las siete llamadas de esta
     * clase se hacen dentro de la peticion del comprador —`OrderController` y
     * `PaymentController` reciben este servicio inyectado— y el cliente HTTP
     * de Laravel no pone limite por defecto. Con Octane los trabajadores son
     * un grupo fijo: basta que Bold deje de contestar para que se agoten
     * esperando y caiga todo el backend, no solo los pagos.
     *
     * Quince segundos para la respuesta y cinco para conectar. Fallar con un
     * error que el comprador ve es peor que no fallar, pero infinitamente
     * mejor que colgar al resto de la tienda.
     */
    protected function peticion(): PendingRequest
    {
        /*
         * `Http::withHeaders($this->headers())`, y no `$this->peticion()`.
         *
         * Aqui decia `$this->peticion()`: el metodo se llamaba a si mismo. El
         * commit que puso los topes sustituyo cada
         * `Http::withHeaders($this->headers())` de la clase por
         * `$this->peticion()` —siete sitios, correcto— y tambien el de DENTRO
         * de este metodo, que era el unico que tenia que quedarse. Un
         * reemplazo a ciegas.
         *
         * Lo que dejaba no era una llamada lenta: era recursion infinita.
         * Medido: `Allowed memory size of 134217728 bytes exhausted in
         * BoldService.php on line 45`. Un error FATAL, no una excepcion, asi
         * que ni el `try/catch` de los controladores lo atrapa y bajo Octane se
         * lleva al trabajador por delante. Las siete llamadas —crear intencion,
         * pagar, anular, devolver, estado de devolucion, bancos PSE y consultar
         * pago— mataban el proceso.
         *
         * Ninguna prueba lo vio porque ninguna ejercitaba estos metodos: la
         * primera que entra en `LlamadasABoldTest` se cae con el codigo de
         * antes. Y lo encontro el control del detector de llamadas HTTP, al
         * exigir que `BoldService.php` apareciera entre los ficheros con
         * `Http::` — no aparecia, porque ya no quedaba ninguno.
         */
        return Http::withHeaders($this->headers())
            ->connectTimeout((int) config('services.bold.connect_timeout', 5))
            ->timeout((int) config('services.bold.timeout', 15));
    }

    public function createIntent(array $body): array
    {
        $response = $this->peticion()
            ->post("{$this->apiUrl}/v1/payment-intent", $body);

        if ($response->failed()) {
            throw FalloDeLaPasarela::desde($response);
        }

        return $response->json();
    }

    public function makePayment(array $body): array
    {
        $response = $this->peticion()
            ->post("{$this->apiUrl}/v1/payment", $body);

        if ($response->failed()) {
            // `desdeJson` y no `desde`: este sitio construia su mensaje con
            // `json_encode($response->json())` y no con `body()`. Se conserva
            // la diferencia, ver `FalloDeLaPasarela`.
            throw FalloDeLaPasarela::desdeJson($response);
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
        $response = $this->peticion()
            ->post("{$this->apiUrl}/v1/payment/void", [
                'transaction_id' => $transactionId,
            ]);

        if ($response->failed()) {
            throw FalloDeLaPasarela::desde($response);
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
        $response = $this->peticion()
            ->post("{$this->apiUrl}/v1/payment/refund", [
                'reference_id' => $referenceId,
                'transaction_id' => $transactionId,
                'reason' => $motivo,
            ]);

        if ($response->failed()) {
            throw FalloDeLaPasarela::desde($response);
        }

        return $response->json() ?? [];
    }

    /** En qué va el reembolso: PROCESSING, APPROVED o REJECTED. */
    public function estadoDeDevolucion(string $transactionId): array
    {
        $response = $this->peticion()
            ->get("{$this->apiUrl}/v1/payment/refund/{$transactionId}");

        if ($response->failed()) {
            throw FalloDeLaPasarela::desde($response);
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
        $response = $this->peticion()
            ->get("{$this->apiUrl}/v1/payment/pse/banks");

        if ($response->failed()) {
            throw FalloDeLaPasarela::desde($response);
        }

        return $response->json()['payload'] ?? $response->json();
    }

    public function checkPayment(string $reference): array
    {
        $response = $this->peticion()
            ->get("{$this->apiUrl}/v1/payment/{$reference}");

        if ($response->failed()) {
            throw FalloDeLaPasarela::desde($response);
        }

        return $response->json()['payload'] ?? $response->json();
    }
}
