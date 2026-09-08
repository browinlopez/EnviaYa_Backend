<?php

namespace App\Services;

use App\Events\AvisoPersonal;
use App\Jobs\EnviarAvisoPush;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Crear un aviso personal y anunciarlo.
 *
 * Vive aparte para que dar un aviso sea una línea y no seis repetidas en cada
 * controlador. Sobre todo, para que guardar y anunciar vayan siempre juntos: si
 * cada sitio lo hiciera por su cuenta, tarde o temprano alguno guardaría sin
 * emitir —y el usuario vería el aviso solo al recargar— o emitiría sin guardar
 * —y el aviso desaparecería al cerrar la app—.
 *
 * TRES CAMINOS PARA EL MISMO AVISO, y cada uno cubre lo que el otro no:
 *
 *   · La FILA en la base es el que sobrevive. Se lee en la campana cuando sea.
 *   · El WEBSOCKET da la inmediatez, y solo con la app abierta y conectada.
 *   · El PUSH es el único que llega con la app CERRADA.
 *
 * Los tres salen de acá para que no vuelva a pasar lo que pasaba: los avisos de
 * pedido tenían los dos primeros y ninguno tenía el tercero, así que quien no
 * estuviera mirando la app en ese momento no se enteraba de que le habían
 * aceptado el pedido ni de que el domiciliario iba en camino.
 */
class Avisos
{
    /**
     * El título del push según el tipo de aviso.
     *
     * En la barra de notificaciones se compite con veinte aplicaciones y lo
     * único que se lee entero es el título. «VeciPa'Ya» no dice nada que no
     * diga ya el icono; «Tu pedido va en camino» se entiende sin abrir.
     */
    private const TITULOS = [
        'pedido_aceptado'  => 'Pedido aceptado',
        'pedido_en_camino' => 'Tu pedido va en camino',
        'pedido_entregado' => 'Pedido entregado',
        'pedido_cancelado' => 'Pedido cancelado',
        'entrega_asignada' => 'Tienes una entrega',
    ];

    /**
     * @param  array<string, mixed>  $datos  Contexto para que tocar el aviso
     *                                       lleve a alguna parte.
     * @param  bool  $push  A falso cuando quien llama ya manda el push por su
     *                      cuenta. Lo usa el envío de promociones, que va en
     *                      bloque a todos los afiliados de una vez en lugar de
     *                      uno por persona; sin esto, cada cliente recibiría la
     *                      misma promoción DOS veces.
     */
    public static function para(
        int|string $userId,
        string $tipo,
        string $mensaje,
        array $datos = [],
        bool $push = true,
    ): ?Notification {
        try {
            $aviso = Notification::create([
                'user_id' => $userId,
                'tipo'    => $tipo,
                'message' => $mensaje,
                'datos'   => $datos,
                'read'    => false,
                'date'    => now(),
                'state'   => true,
            ]);
        } catch (\Throwable $e) {
            // Si no se puede ni guardar, no hay aviso que dar. Se registra y se
            // sigue: ninguna operación debe caerse por no poder avisar.
            Log::warning('No se pudo guardar el aviso', [
                'user_id' => $userId,
                'tipo'    => $tipo,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }

        /*
         * El anuncio va aparte y tolera el fallo: el aviso YA está guardado, así
         * que si Reverb está caído la persona lo verá igualmente al abrir su
         * campana. Perder la inmediatez es aceptable; perder el aviso no.
         */
        try {
            broadcast(new AvisoPersonal($aviso));
        } catch (\Throwable $e) {
            Log::warning('No se pudo anunciar el aviso', [
                'notification_id' => $aviso->notification_id,
                'error'           => $e->getMessage(),
            ]);
        }

        /*
         * Y al teléfono, aunque la app esté cerrada.
         *
         * Encolar tampoco puede tumbar la operación: si la cola no está
         * disponible, el pedido ya avanzó y el aviso sigue guardado. Se pierde
         * el timbre, no el aviso.
         */
        if ($push) {
            try {
                EnviarAvisoPush::dispatch(
                    $userId,
                    self::TITULOS[$tipo] ?? "VeciPa'Ya",
                    $mensaje,
                    // El tipo viaja para que la app sepa a qué pantalla llevar
                    // al tocarla; sin él, el destino se perdería.
                    ['tipo' => $tipo] + array_map(
                        fn ($v) => is_scalar($v) ? (string) $v : json_encode($v),
                        $datos,
                    ),
                );
            } catch (\Throwable $e) {
                Log::warning('No se pudo encolar el push del aviso', [
                    'notification_id' => $aviso->notification_id,
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        return $aviso;
    }
}
