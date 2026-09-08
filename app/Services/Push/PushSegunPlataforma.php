<?php

namespace App\Services\Push;

use App\Models\DeviceToken;

/**
 * CADA TELÉFONO POR SU CAMINO
 *
 * Android va por Firebase y iPhone va directo a Apple, porque sus tokens NO
 * son intercambiables: el de un iPhone es de APNs y Firebase lo rechaza con
 * `INVALID_ARGUMENT`. Ese era exactamente el fallo —los avisos no llegaban a
 * ningún iPhone y nada lo decía— y esta clase es lo que lo cierra.
 *
 * LA PLATAFORMA SE LEE DE `device_tokens`, no del token.
 *
 * Se podría intentar adivinar por la forma —los de APNs son 64 hexadecimales
 * y los de Firebase llevan dos puntos—, y funcionaría casi siempre. «Casi
 * siempre» acá significa que un día Apple cambia el formato, los avisos se van
 * por el camino equivocado y nadie se entera hasta que un cliente se queja de
 * que no le llegó el pedido. La columna ya existe y la escribe la app al
 * registrarse: se pregunta.
 *
 * UN TOKEN QUE NO ESTÁ EN LA TABLA VA POR FIREBASE. Es el comportamiento que
 * había antes de esto y cubre lo que se mande a mano desde una prueba o un
 * comando. Nunca se descarta un envío por no saber de dónde viene el token.
 */
class PushSegunPlataforma implements TransportePush
{
    public function __construct(
        private readonly PushFirebase $firebase,
        private readonly PushApns $apple,
    ) {
    }

    public function nombre(): string
    {
        return $this->apple->configurado()
            ? 'Firebase (Android) y APNs (iPhone)'
            : $this->firebase->nombre();
    }

    /**
     * Configurado si AL MENOS Firebase lo está.
     *
     * Sin Apple, los iPhone se quedan sin aviso pero los Android siguen
     * recibiendo; decir «sin configurar» apagaría también lo que sí funciona.
     */
    public function configurado(): bool
    {
        return $this->firebase->configurado();
    }

    /**
     * @param  list<string>  $tokens
     * @return array<string, ResultadoPush>
     */
    public function enviar(array $tokens, string $titulo, string $cuerpo, array $datos = []): array
    {
        if ($tokens === []) {
            return [];
        }

        $plataformas = DeviceToken::whereIn('token', $tokens)
            ->pluck('platform', 'token');

        $paraApple = [];
        $paraFirebase = [];

        foreach ($tokens as $token) {
            if (($plataformas[$token] ?? null) === 'ios') {
                $paraApple[] = $token;
                continue;
            }

            $paraFirebase[] = $token;
        }

        $salida = [];

        if ($paraFirebase !== []) {
            $salida += $this->firebase->enviar($paraFirebase, $titulo, $cuerpo, $datos);
        }

        /*
         * Sin Apple configurado los tokens de iPhone se marcan como fallo
         * temporal —no como token muerto—: la culpa es del servidor, no del
         * teléfono, y borrarlos obligaría a que la gente reinstalara la app
         * para volver a recibir avisos.
         */
        if ($paraApple !== []) {
            $salida += $this->apple->configurado()
                ? $this->apple->enviar($paraApple, $titulo, $cuerpo, $datos)
                : array_fill_keys(
                    $paraApple,
                    ResultadoPush::falloTemporal('APNs sin configurar'),
                );
        }

        return $salida;
    }
}
