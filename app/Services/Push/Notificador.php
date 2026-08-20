<?php

namespace App\Services\Push;

use App\Models\DeviceToken;
use App\Models\Marketing\PushCampaign;
use App\Jobs\EnviarLotePush;
use Illuminate\Support\Facades\DB;

/**
 * MANDA UNA CAMPAÑA A LOS TELÉFONOS
 *
 * Entre "resolver el segmento" y "entregar" hay una traducción que no es obvia:
 * el segmento son PERSONAS y la entrega son DISPOSITIVOS. Una persona puede
 * tener el teléfono y la tableta, y otra puede no tener la app instalada. Por
 * eso la pantalla acaba mostrando dos números distintos, y por eso "llegó a
 * 3.000 personas" es falso si solo 800 tienen sesión abierta.
 *
 * EL ENVÍO VA EN COLA, por lotes. Firebase HTTP v1 manda un mensaje por
 * petición, así que mil teléfonos son mil llamadas: hacerlo dentro de la
 * petición del panel es un tiempo de espera agotado garantizado, y encima
 * dejaría la campaña marcada como enviada a medias.
 */
class Notificador
{
    /**
     * Cuántos dispositivos por trabajo encolado.
     *
     * Ni uno por trabajo —serían miles de trabajos para una campaña— ni todos en
     * uno —un fallo al final perdería el lote entero al reintentar—. Cien es del
     * orden de un minuto de trabajo, que es lo que conviene que dure algo que se
     * puede reintentar completo.
     */
    public const POR_LOTE = 100;

    public function __construct(private TransportePush $transporte)
    {
    }

    /**
     * Encola el envío de una campaña ya resuelta.
     *
     * @param  list<int>  $usuarios  destinatarios del segmento
     * @return int  dispositivos a los que se va a intentar
     */
    public function encolar(PushCampaign $campana, array $usuarios): int
    {
        $tokens = DeviceToken::vivos()
            ->whereIn('user_id', $usuarios)
            ->pluck('token')
            ->all();

        if ($tokens === []) {
            return 0;
        }

        foreach (array_chunk($tokens, self::POR_LOTE) as $lote) {
            EnviarLotePush::dispatch($campana->id, $lote);
        }

        return count($tokens);
    }

    /**
     * Envía un lote y anota el resultado.
     *
     * Los contadores se suman con `increment` y no leyendo-sumando-guardando:
     * varios lotes de la misma campaña corren a la vez y dos trabajos que leen
     * el mismo valor antes de escribirlo perderían uno de los dos incrementos.
     */
    public function enviarLote(PushCampaign $campana, array $tokens): void
    {
        $resultados = $this->transporte->enviar(
            $tokens,
            $campana->title,
            $campana->body,
            $this->datosDelEnlace($campana),
        );

        $entregados = 0;
        $fallidos   = 0;
        $muertos    = [];

        foreach ($resultados as $token => $r) {
            if ($r->ok) {
                $entregados++;
                continue;
            }

            $fallidos++;

            // Solo los permanentes: un fallo de red no puede costar la
            // suscripción de alguien que sigue teniendo la app instalada.
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

        DB::table('push_campaigns')->where('id', $campana->id)->update([
            'delivered_count' => DB::raw('COALESCE(delivered_count, 0) + ' . $entregados),
            'failed_count'    => DB::raw('COALESCE(failed_count, 0) + ' . $fallidos),
        ]);

        // Los tokens que sí funcionaron se marcan vistos: es lo que permite
        // limpiar después los que llevan meses sin recibir nada.
        if ($entregados > 0) {
            DeviceToken::whereIn('token', array_keys(array_filter(
                $resultados,
                fn ($r) => $r->ok,
            )))->update(['last_seen_at' => now()]);
        }
    }

    /**
     * A dónde lleva la notificación al tocarla.
     *
     * Misma convención que los banners: la app ya sabe interpretar este par.
     */
    private function datosDelEnlace(PushCampaign $campana): array
    {
        if (($campana->link_type ?? 'none') === 'none' || !$campana->link_value) {
            return ['campaign_id' => (string) $campana->id];
        }

        return [
            'campaign_id' => (string) $campana->id,
            'link_type'   => (string) $campana->link_type,
            'link_value'  => (string) $campana->link_value,
        ];
    }

    public function transporte(): TransportePush
    {
        return $this->transporte;
    }
}
