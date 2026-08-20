<?php

namespace App\Services;

/**
 * CÓDIGOS DE UN SOLO USO BASADOS EN TIEMPO (TOTP, RFC 6238)
 *
 * Es lo que hace que Google Authenticator, Authy o 1Password muestren seis
 * dígitos que cambian cada treinta segundos. El algoritmo es corto y lleva una
 * década sin cambiar: un HMAC del contador de tiempo, del que se toman cuatro
 * bytes y se recortan a seis cifras.
 *
 * SE ESCRIBE A MANO en vez de traer un paquete, por la misma razón que el JWT de
 * Firebase: son sesenta líneas y el paquete arrastra dependencias para hacer
 * exactamente esto. La contrapartida sería quedarse sin la garantía de que está
 * bien implementado — y por eso las pruebas usan los VECTORES OFICIALES del RFC
 * 6238, que es la misma comprobación que pasa cualquier implementación seria.
 *
 * DOS DETALLES QUE NO SON OPCIONALES:
 *
 *  · La VENTANA. Los relojes del teléfono y del servidor no coinciden al
 *    segundo, y alguien puede tardar en teclear. Sin tolerancia, uno de cada
 *    varios códigos correctos se rechaza y la gente concluye que "esto no
 *    funciona". Con demasiada, el código vale minutos y deja de ser de un solo
 *    uso. Un paso a cada lado —hasta 30 s de desfase— es el equilibrio habitual.
 *
 *  · La comparación en TIEMPO CONSTANTE. Comparar con `===` deja escapar cuánto
 *    coincide por el tiempo que tarda; con seis dígitos es un ataque teórico,
 *    pero `hash_equals` no cuesta nada y no hay motivo para dejarlo abierto.
 */
class Totp
{
    /** Segundos que dura cada código. */
    private const PASO = 30;

    /** Cuántos pasos de tolerancia a cada lado. */
    private const VENTANA = 1;

    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Un secreto nuevo, en base32.
     *
     * 160 bits, que es lo que recomienda el RFC para HMAC-SHA1. Más corto
     * funciona y es más fácil de forzar; más largo no aporta nada porque la
     * salida sigue siendo de seis dígitos.
     */
    public static function secreto(): string
    {
        $bytes = random_bytes(20);
        $salida = '';

        foreach (str_split($bytes) as $b) {
            // Se toman cinco bits por carácter, que es lo que codifica base32.
            $salida .= self::ALFABETO[ord($b) & 0x1F];
            $salida .= self::ALFABETO[(ord($b) >> 3) & 0x1F];
        }

        return substr($salida, 0, 32);
    }

    /** El código que toca ahora mismo (o en el instante que se le pase). */
    public static function codigo(string $secreto, ?int $instante = null, int $digitos = 6): string
    {
        $contador = intdiv($instante ?? time(), self::PASO);

        return self::codigoDelContador($secreto, $contador, $digitos);
    }

    /**
     * ¿Es válido este código?
     *
     * Acepta el del momento y uno a cada lado: los relojes no coinciden al
     * segundo y teclear seis dígitos lleva unos cuantos.
     */
    public static function verificar(string $secreto, string $codigo, ?int $instante = null): bool
    {
        $codigo = preg_replace('/\D/', '', $codigo);

        if ($codigo === '' || strlen($codigo) !== 6) {
            return false;
        }

        $contador = intdiv($instante ?? time(), self::PASO);

        for ($i = -self::VENTANA; $i <= self::VENTANA; $i++) {
            $esperado = self::codigoDelContador($secreto, $contador + $i);

            // `hash_equals` y no `===`: comparar cadenas cortando al primer
            // carácter distinto filtra cuánto se acertó por el tiempo que tarda.
            if (hash_equals($esperado, $codigo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * La dirección `otpauth://` que se convierte en código QR.
     *
     * El nombre de la cuenta lleva el correo para que quien tenga varias —la de
     * pruebas y la de verdad— las distinga en la lista de la app.
     */
    public static function uri(string $secreto, string $correo, string $emisor): string
    {
        return 'otpauth://totp/'
            . rawurlencode($emisor) . ':' . rawurlencode($correo)
            . '?' . http_build_query([
                'secret' => $secreto,
                'issuer' => $emisor,
                'algorithm' => 'SHA1',
                'digits' => 6,
                'period' => self::PASO,
            ]);
    }

    /* ------------------------------------------------------------------ */

    private static function codigoDelContador(string $secreto, int $contador, int $digitos = 6): string
    {
        $clave = self::deBase32($secreto);

        // El contador va como entero de 64 bits en orden de red.
        $binario = pack('J', $contador);

        $hash = hash_hmac('sha1', $binario, $clave, true);

        /*
         * "Truncamiento dinámico": el último medio byte del hash dice desde qué
         * posición leer los cuatro bytes del código. Sirve para que el resultado
         * no dependa siempre de la misma parte del hash.
         */
        $desplazamiento = ord($hash[19]) & 0x0F;

        $valor = ((ord($hash[$desplazamiento]) & 0x7F) << 24)
            | ((ord($hash[$desplazamiento + 1]) & 0xFF) << 16)
            | ((ord($hash[$desplazamiento + 2]) & 0xFF) << 8)
            | (ord($hash[$desplazamiento + 3]) & 0xFF);

        return str_pad(
            (string) ($valor % (10 ** $digitos)),
            $digitos,
            '0',
            STR_PAD_LEFT,
        );
    }

    /** Base32 a binario. No usa la tabla de PHP porque PHP no trae base32. */
    private static function deBase32(string $texto): string
    {
        $texto = rtrim(strtoupper($texto), '=');
        $bits = '';

        foreach (str_split($texto) as $caracter) {
            $posicion = strpos(self::ALFABETO, $caracter);

            if ($posicion === false) {
                continue; // se ignoran espacios y guiones: la gente los escribe
            }

            $bits .= str_pad(decbin($posicion), 5, '0', STR_PAD_LEFT);
        }

        $salida = '';

        foreach (str_split($bits, 8) as $octeto) {
            if (strlen($octeto) === 8) {
                $salida .= chr(bindec($octeto));
            }
        }

        return $salida;
    }
}
