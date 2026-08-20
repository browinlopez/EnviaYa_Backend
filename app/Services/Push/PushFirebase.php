<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FIREBASE CLOUD MESSAGING (HTTP v1)
 *
 * Es el transporte de verdad. Sin credenciales configuradas queda inerte y el
 * módulo lo dice en pantalla; con ellas, entrega.
 *
 * POR QUÉ HTTP v1 Y NO LA API DE SIEMPRE: la antigua —la de la "server key" en
 * una cabecera— la retiró Google en 2024. La v1 pide un token OAuth firmado con
 * la cuenta de servicio, que es lo que hace `accessToken()` acá abajo.
 *
 * SE FIRMA A MANO, sin el SDK de Google. El SDK arrastra una docena de paquetes
 * para hacer exactamente estas veinte líneas: un JWT RS256 con `openssl_sign`,
 * que PHP trae de fábrica. La contrapartida es que si Google cambia el formato
 * del token hay que tocarlo acá; el formato del JWT lleva una década sin
 * cambiar.
 *
 * UN MENSAJE POR PETICIÓN. La v1 quitó el envío múltiple, así que mil teléfonos
 * son mil llamadas. Por eso el envío va en cola y por lotes: dentro de una
 * petición web, mil llamadas HTTP serían un tiempo de espera agotado seguro.
 */
class PushFirebase implements TransportePush
{
    /** Los tokens de Google duran una hora; se renuevan cinco minutos antes. */
    private const VIDA_TOKEN = 3300;

    public function nombre(): string
    {
        return 'Firebase Cloud Messaging';
    }

    public function configurado(): bool
    {
        $ruta = (string) config('services.fcm.credentials');

        return $ruta !== '' && is_readable($ruta) && config('services.fcm.project_id');
    }

    public function enviar(array $tokens, string $titulo, string $cuerpo, array $datos = []): array
    {
        if (!$this->configurado()) {
            return array_fill_keys(
                $tokens,
                ResultadoPush::falloTemporal('Firebase no está configurado en el servidor.'),
            );
        }

        $acceso = $this->accessToken();

        if (!$acceso) {
            return array_fill_keys(
                $tokens,
                ResultadoPush::falloTemporal('No se pudo autenticar contra Firebase.'),
            );
        }

        $proyecto = config('services.fcm.project_id');
        $url = "https://fcm.googleapis.com/v1/projects/{$proyecto}/messages:send";

        $salida = [];

        foreach ($tokens as $token) {
            $salida[$token] = $this->enviarUno($url, $acceso, $token, $titulo, $cuerpo, $datos);
        }

        return $salida;
    }

    private function enviarUno(
        string $url,
        string $acceso,
        string $token,
        string $titulo,
        string $cuerpo,
        array $datos,
    ): ResultadoPush {
        try {
            $r = Http::withToken($acceso)
                ->timeout(15)
                ->post($url, [
                    'message' => [
                        'token'        => $token,
                        'notification' => ['title' => $titulo, 'body' => $cuerpo],
                        /*
                         * Todo a texto: FCM rechaza el mensaje entero si un valor
                         * de `data` no es una cadena, y un entero suelto tumbaría
                         * el envío completo por un detalle de tipos.
                         */
                        'data' => array_map(fn ($v) => (string) $v, $datos),
                    ],
                ]);

            if ($r->successful()) {
                return ResultadoPush::entregado();
            }

            $codigo = (string) $r->json('error.status', '');

            /*
             * UNREGISTERED e INVALID_ARGUMENT sobre el token significan que ese
             * teléfono ya no existe para nosotros: se marca y no se reintenta.
             * Un 429 o un 503 son del proveedor y el token sigue bueno.
             */
            $muerto = in_array($codigo, ['UNREGISTERED', 'NOT_FOUND'], true)
                || ($r->status() === 404);

            $motivo = $codigo !== '' ? $codigo : "HTTP {$r->status()}";

            return $muerto
                ? ResultadoPush::tokenMuerto($motivo)
                : ResultadoPush::falloTemporal($motivo);
        } catch (\Throwable $e) {
            Log::warning('Fallo al enviar una notificación por Firebase', [
                'error' => $e->getMessage(),
            ]);

            return ResultadoPush::falloTemporal('Error de red hacia Firebase.');
        }
    }

    /**
     * Token OAuth de la cuenta de servicio.
     *
     * En caché porque vale una hora y pedir uno por lote sería una llamada extra
     * por cada cien mensajes, contra el mismo servidor que ya está atendiendo el
     * envío.
     */
    private function accessToken(): ?string
    {
        return Cache::remember('fcm.access_token', self::VIDA_TOKEN, function () {
            $cuenta = json_decode((string) file_get_contents(config('services.fcm.credentials')), true);

            if (!isset($cuenta['client_email'], $cuenta['private_key'])) {
                Log::error('El archivo de credenciales de Firebase no tiene el formato esperado.');

                return null;
            }

            $ahora = time();

            $jwt = $this->firmar([
                'iss'   => $cuenta['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud'   => 'https://oauth2.googleapis.com/token',
                'iat'   => $ahora,
                'exp'   => $ahora + 3600,
            ], $cuenta['private_key']);

            if (!$jwt) {
                return null;
            }

            $r = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]);

            return $r->successful() ? $r->json('access_token') : null;
        });
    }

    /** JWT RS256 con `openssl`, sin dependencias. */
    private function firmar(array $carga, string $clavePrivada): ?string
    {
        $base64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        $cabecera = $base64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $cuerpo   = $base64(json_encode($carga));

        $firma = '';

        if (!openssl_sign("{$cabecera}.{$cuerpo}", $firma, $clavePrivada, 'sha256')) {
            Log::error('No se pudo firmar el token de Firebase con la clave privada.');

            return null;
        }

        return "{$cabecera}.{$cuerpo}." . $base64($firma);
    }
}
