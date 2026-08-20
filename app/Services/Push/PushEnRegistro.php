<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Log;

/**
 * TRANSPORTE DE DESARROLLO: escribe en el registro y no entrega nada.
 *
 * Es el que se usa mientras no haya credenciales de Firebase. Sirve para ver el
 * flujo completo —segmento, lotes, contadores, pantalla— sin depender de un
 * proyecto en la nube.
 *
 * DEVUELVE FALLO, no éxito, y eso es deliberado. Un transporte de mentira que
 * reporta "entregado" haría que la pantalla dijera "1.240 entregadas" cuando no
 * salió ni una: exactamente la mentira que este módulo tenía antes y que se
 * está arreglando. Reporta un fallo temporal con el motivo escrito, así que la
 * pantalla dice "0 entregadas — sin transporte configurado" y se entiende.
 */
class PushEnRegistro implements TransportePush
{
    public function nombre(): string
    {
        return 'Solo registro (sin entrega)';
    }

    public function configurado(): bool
    {
        return false;
    }

    public function enviar(array $tokens, string $titulo, string $cuerpo, array $datos = []): array
    {
        Log::info('Notificación NO enviada: no hay transporte de push configurado.', [
            'titulo'       => $titulo,
            'cuerpo'       => $cuerpo,
            'datos'        => $datos,
            'dispositivos' => count($tokens),
        ]);

        return array_fill_keys(
            $tokens,
            ResultadoPush::falloTemporal('Sin transporte de push configurado.'),
        );
    }
}
