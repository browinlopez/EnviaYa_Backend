<?php

namespace App\Jobs;

use App\Models\Marketing\PushCampaign;
use App\Services\Push\Notificador;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * UN LOTE DE CIEN TELÉFONOS
 *
 * Va en cola porque Firebase manda un mensaje por petición: mil dispositivos son
 * mil llamadas HTTP, y hacerlas dentro de la petición del panel es un tiempo de
 * espera agotado seguro — con la campaña marcada como enviada a medias.
 *
 * SE PASAN LOS TOKENS Y NO LOS USUARIOS. Si el trabajo volviera a resolver el
 * segmento, un usuario que se registra entre el encolado y la ejecución
 * recibiría una notificación que la campaña no contó, y el número de la pantalla
 * dejaría de cuadrar con lo que salió.
 *
 * Tres intentos con espera creciente: los fallos de Firebase suelen ser cuota o
 * un 503 momentáneo. Los tokens muertos ya no se reintentan —se marcan en la
 * primera pasada—, así que un reintento no gasta envíos en teléfonos que no
 * existen.
 */
class EnviarLotePush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /**
     * @param  list<string>  $tokens
     */
    public function __construct(
        public int $campanaId,
        public array $tokens,
    ) {
    }

    public function handle(Notificador $notificador): void
    {
        $campana = PushCampaign::find($this->campanaId);

        // La campaña pudo borrarse entre el encolado y la ejecución. Mandar una
        // notificación de algo que ya no existe es peor que no mandarla.
        if (!$campana) {
            return;
        }

        $notificador->enviarLote($campana, $this->tokens);
    }
}
