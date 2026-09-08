<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NOTIFICACIONES A IPHONE, HABLANDO DIRECTO CON APPLE
 *
 * POR QUÉ EXISTE, cuando Firebase ya entrega a Android.
 *
 * La app pide su token con `getDevicePushTokenAsync()`, que en iOS devuelve el
 * token NATIVO DE APNs. El servidor lo metía en `message.token` de Firebase,
 * que solo acepta tokens de registro DE FIREBASE. Los dos son cadenas de
 * hexadecimal y ninguno de los dos lados se quejaba al guardarlo: el envío se
 * aceptaba, Firebase respondía `INVALID_ARGUMENT` y el aviso no llegaba nunca.
 * En Android no pasaba porque ahí el token nativo ES el de Firebase.
 *
 * Había dos salidas:
 *
 *   (a) Meter `@react-native-firebase/messaging` en la app para que iOS pida
 *       un token de Firebase. Una dependencia nativa más, y tocar el camino de
 *       Android que ya funciona.
 *   (b) Esto: hablar con Apple directamente, con la misma clave `.p8` que ya
 *       está subida a Firebase.
 *
 * Se eligió (b): no añade nada al paquete de la app, no toca Android, y
 * `TransportePush` ya estaba pensado para varios transportes.
 *
 * CÓMO SE AUTENTICA. Un JWT firmado con ES256 usando la clave `.p8`, con el
 * ID de la clave en la cabecera y el del equipo como emisor. Vale una hora;
 * Apple rechaza pedir uno nuevo más de una vez cada veinte minutos, así que se
 * guarda en caché — igual que el de Firebase.
 *
 * SE FIRMA A MANO, sin librería de JWT. Son treinta líneas y PHP trae
 * `openssl_sign` de fábrica; la única parte no obvia es que OpenSSL devuelve
 * la firma en DER y APNs la quiere en crudo (R‖S, 64 bytes), que es lo que
 * hace `derACrudo()` acá abajo.
 *
 * DOS SERVIDORES DISTINTOS. `api.push.apple.com` para las compilaciones de
 * producción y `api.sandbox.push.apple.com` para las de desarrollo, cada uno
 * con SUS PROPIOS tokens: uno de sandbox no sirve en producción y al revés
 * tampoco. Lo decide `APNS_PRODUCTION`, y tiene que coincidir con el
 * `aps-environment` con el que se compiló la app.
 */
class PushApns implements TransportePush
{
    /** Los JWT de Apple duran una hora; se renuevan cinco minutos antes. */
    private const VIDA_TOKEN = 3300;

    public function nombre(): string
    {
        return 'Apple Push Notification service';
    }

    public function configurado(): bool
    {
        return $this->clave() !== null
            && (string) config('services.apns.key_id') !== ''
            && (string) config('services.apns.team_id') !== ''
            && (string) config('services.apns.bundle_id') !== '';
    }

    /**
     * La clave privada, venga de donde venga.
     *
     * Mismo criterio que Firebase y por el mismo motivo: este repositorio es
     * PÚBLICO y un `.p8` da permiso para mandar notificaciones a todos los
     * iPhone con la app instalada. En base64 va en una línea, que es lo que
     * evita el problema real de pegarla: lleva saltos de línea.
     */
    private function clave(): ?string
    {
        $enVariable = (string) config('services.apns.key');

        if ($enVariable !== '') {
            $quizas = base64_decode($enVariable, true);

            // Se acepta tal cual o en base64: si al decodificar sale algo que
            // parece una clave, era base64; si no, ya venía en crudo.
            $texto = ($quizas !== false && str_contains($quizas, 'PRIVATE KEY'))
                ? $quizas
                : $enVariable;

            return str_contains($texto, 'PRIVATE KEY') ? $texto : null;
        }

        $ruta = (string) config('services.apns.key_path');

        if ($ruta !== '' && is_readable($ruta)) {
            return file_get_contents($ruta) ?: null;
        }

        return null;
    }

    private function host(): string
    {
        return config('services.apns.production')
            ? 'https://api.push.apple.com'
            : 'https://api.sandbox.push.apple.com';
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $datos
     * @return array<string, ResultadoPush>
     */
    public function enviar(array $tokens, string $titulo, string $cuerpo, array $datos = []): array
    {
        if (!$this->configurado()) {
            return $this->todosFallan($tokens, 'APNs sin configurar');
        }

        $jwt = $this->jwt();

        if ($jwt === null) {
            return $this->todosFallan($tokens, 'No se pudo firmar el token de Apple');
        }

        $salida = [];

        foreach ($tokens as $token) {
            $salida[$token] = $this->enviarUno($jwt, $token, $titulo, $cuerpo, $datos);
        }

        return $salida;
    }

    private function enviarUno(
        string $jwt,
        string $token,
        string $titulo,
        string $cuerpo,
        array $datos,
    ): ResultadoPush {
        try {
            $r = Http::withHeaders([
                'authorization'    => 'bearer ' . $jwt,
                'apns-topic'       => (string) config('services.apns.bundle_id'),
                // `alert` es lo que hace que el sistema la MUESTRE con la app
                // cerrada. Con `background` llegaría callada.
                'apns-push-type'   => 'alert',
                'apns-priority'    => '10',
            ])
                ->timeout(15)
                /*
                 * APNs SOLO habla HTTP/2 y rechaza la conexión sin decir por
                 * qué si se intenta con 1.1. `Http` usa cURL por debajo, así
                 * que se le pide explícitamente.
                 */
                ->withOptions(['version' => 2.0])
                ->post($this->host() . '/3/device/' . $token, [
                    'aps' => [
                        'alert' => ['title' => $titulo, 'body' => $cuerpo],
                        'sound' => 'default',
                    ],
                    // Todo a texto, igual que en Firebase: así el mismo aviso
                    // se lee igual venga por donde venga.
                    ...array_map(fn ($v) => (string) $v, $datos),
                ]);

            if ($r->successful()) {
                return ResultadoPush::entregado();
            }

            $motivo = (string) $r->json('reason', 'HTTP ' . $r->status());

            /*
             * `BadDeviceToken` y `Unregistered` significan que ESE teléfono ya
             * no existe para nosotros —se desinstaló, o el token es de otro
             * entorno— y hay que dejar de intentarlo. Lo demás es del
             * proveedor y el token sigue bueno.
             */
            return in_array($motivo, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true)
                ? ResultadoPush::tokenMuerto($motivo)
                : ResultadoPush::falloTemporal($motivo);
        } catch (\Throwable $e) {
            Log::warning('APNs: fallo al enviar', ['error' => $e->getMessage()]);

            return ResultadoPush::falloTemporal($e->getMessage());
        }
    }

    /** El JWT de autorización, en caché mientras dure. */
    private function jwt(): ?string
    {
        return Cache::remember('apns.jwt', self::VIDA_TOKEN, function () {
            $clave = $this->clave();

            if ($clave === null) {
                return null;
            }

            $cabecera = $this->base64Url(json_encode([
                'alg' => 'ES256',
                'kid' => (string) config('services.apns.key_id'),
            ]));

            $cuerpo = $this->base64Url(json_encode([
                'iss' => (string) config('services.apns.team_id'),
                'iat' => time(),
            ]));

            $firma = '';
            $pk = openssl_pkey_get_private($clave);

            if ($pk === false || !openssl_sign("{$cabecera}.{$cuerpo}", $firma, $pk, OPENSSL_ALGO_SHA256)) {
                Log::error('APNs: no se pudo firmar con la clave .p8.');

                return null;
            }

            return "{$cabecera}.{$cuerpo}." . $this->base64Url($this->derACrudo($firma));
        });
    }

    /**
     * La firma de OpenSSL viene en DER; APNs la quiere en crudo.
     *
     * ES256 firma con dos enteros, R y S, de 32 bytes cada uno. OpenSSL los
     * devuelve envueltos en una secuencia DER —con etiquetas de tipo, largos y
     * a veces un cero delante para que no se lea como negativo—, y el JWT los
     * quiere pelados y pegados: R‖S, 64 bytes exactos.
     *
     * Sin esta conversión Apple responde `InvalidProviderToken` y no hay
     * manera de adivinar por qué: la clave es correcta y la firma también, solo
     * que en otro formato.
     */
    private function derACrudo(string $der): string
    {
        $pos = 0;

        // 0x30 = secuencia. Se salta su etiqueta y su largo.
        if (($der[$pos++] ?? '') !== "\x30") {
            return $der;
        }

        $largo = ord($der[$pos++]);

        // Largo en formato extendido: el byte alto dice cuántos bytes ocupa.
        if ($largo > 0x80) {
            $pos += $largo - 0x80;
        }

        $leerEntero = function () use ($der, &$pos): string {
            // 0x02 = entero.
            if (($der[$pos++] ?? '') !== "\x02") {
                return '';
            }

            $n = ord($der[$pos++]);
            $valor = substr($der, $pos, $n);
            $pos += $n;

            // DER mete un 0x00 delante cuando el primer bit está encendido,
            // para que no se interprete como número negativo. Sobra acá.
            $valor = ltrim($valor, "\x00");

            return str_pad($valor, 32, "\x00", STR_PAD_LEFT);
        };

        $r = $leerEntero();
        $s = $leerEntero();

        return ($r === '' || $s === '') ? $der : $r . $s;
    }

    private function base64Url(string $dato): string
    {
        return rtrim(strtr(base64_encode($dato), '+/', '-_'), '=');
    }

    /** @return array<string, ResultadoPush> */
    private function todosFallan(array $tokens, string $motivo): array
    {
        $salida = [];

        foreach ($tokens as $t) {
            $salida[$t] = ResultadoPush::falloTemporal($motivo);
        }

        return $salida;
    }
}
