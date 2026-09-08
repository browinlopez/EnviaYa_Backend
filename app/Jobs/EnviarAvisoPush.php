<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Services\Push\TransportePush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * UN AVISO PERSONAL, AL TELÉFONO DE ESA PERSONA
 *
 * POR QUÉ EXISTE. Los avisos de pedido —te lo aceptaron, va en camino, llegó—
 * se guardaban en la campana y se anunciaban por websocket, y nada más. El
 * websocket solo llega si la app está ABIERTA y conectada, así que con la app
 * cerrada el comprador no se enteraba de nada de su propio pedido. Era el
 * agujero más grande de una aplicación de domicilios: la tubería de push
 * llevaba semanas funcionando y no había nadie usándola para lo único que la
 * gente de verdad espera.
 *
 * VA EN COLA, y no dentro de la petición. Mandar a Apple es una conexión HTTP/2
 * por teléfono, y quien está esperando esa respuesta es el tendero tocando
 * «aceptar» o el domiciliario tocando «entregado». Nadie debería esperar a que
 * Apple conteste para que su pantalla avance.
 *
 * SE RESUELVEN LOS TOKENS AQUÍ DENTRO y no al encolar: entre una cosa y otra
 * la persona puede haber entrado desde otro teléfono, y ese también tiene que
 * recibirlo.
 *
 * Tres intentos con espera creciente, igual que las campañas: un 503 de Apple o
 * una cuota de Firebase se reintentan solos. Los tokens muertos se marcan en la
 * primera pasada, así que un reintento no vuelve a gastarse en ellos.
 */
class EnviarAvisoPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /**
     * @param  array<string, mixed>  $datos  A dónde lleva al tocarla.
     */
    public function __construct(
        public int|string $userId,
        public string $titulo,
        public string $cuerpo,
        public array $datos = [],
    ) {
    }

    public function handle(TransportePush $push): void
    {
        /*
         * Sin proveedor configurado no se encola trabajo inútil ni se llenan
         * los registros: en desarrollo esto es lo normal, no una avería.
         */
        if (!$push->configurado()) {
            return;
        }

        $tokens = DeviceToken::vivos()
            ->where('user_id', $this->userId)
            ->pluck('token')
            ->all();

        if ($tokens === []) {
            return;
        }

        $resultados = $push->enviar($tokens, $this->titulo, $this->cuerpo, $this->datos);

        $muertos = [];
        $entregados = [];

        foreach ($resultados as $token => $r) {
            if ($r->ok) {
                $entregados[] = $token;
                continue;
            }

            /*
             * Solo los PERMANENTES se marcan. Un fallo de red o un 503 no puede
             * costarle a alguien que sigue teniendo la app instalada el dejar
             * de recibir avisos de sus pedidos para siempre.
             */
            if ($r->permanente) {
                $muertos[$token] = $r->motivo;
            }
        }

        if ($muertos !== []) {
            DeviceToken::whereIn('token', array_keys($muertos))->update([
                'failed_at'   => now(),
                'fail_reason' => substr((string) reset($muertos), 0, 120),
            ]);
        }

        // Sirve para limpiar después los que llevan meses sin recibir nada.
        if ($entregados !== []) {
            DeviceToken::whereIn('token', $entregados)->update(['last_seen_at' => now()]);
        }
    }
}
