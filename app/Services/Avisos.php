<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\AvisoPersonal;
use App\Jobs\EnviarAvisoPush;
use App\Models\Notification;
use App\Models\Order\OrdersSales;
use App\Models\User;
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
        'pedido_aceptado' => 'Pedido aceptado',
        'pedido_en_camino' => 'Tu pedido va en camino',
        'pedido_entregado' => 'Pedido entregado',
        'pedido_cancelado' => 'Pedido cancelado',
        'entrega_asignada' => 'Tienes una entrega',
        'pedido_nuevo' => '¡Pedido nuevo!',
        'mensaje_nuevo' => 'Mensaje nuevo',
        'pedido_para_tomar' => 'Pedido para tomar',
        'pedido_soltado' => 'Un domiciliario soltó un pedido',
        'pedido_devolviendo' => 'No se pudo entregar',
        'pedido_no_entregado' => 'No pudimos entregar tu pedido',
        'domiciliario_llego' => 'Tu pedido está en la puerta',
        'pedido_listo_para_recoger' => 'Ya puedes pasar por tu pedido',
        'pedido_programado_pronto' => 'Pedido programado',
        'pedido_programado_15' => 'Pedido programado en 15 minutos',
        'recordatorio_programado' => 'Tu pedido programado',
        'recordatorio_programado_15' => 'Tu pedido es en 15 minutos',
        'promocion' => 'Promoción nueva',
        'visita_por_autorizar' => '¿Dejas entrar a alguien?',
        'domiciliario_en_porteria' => 'Tu pedido ya entró al conjunto',
    ];

    /*
     * LO QUE EN LA VIDA DE UN PEDIDO PASA UNA SOLA VEZ.
     *
     * Un pedido se acepta, sale, llega y se entrega una vez. Si por cualquier
     * camino --un reintento, un estado que se corrige y se vuelve a poner--
     * el mismo aviso se pide dos veces para el mismo pedido y la misma
     * persona, la campana enseñaba cinco «Tu pedido fue entregado» iguales.
     * Para estos tipos, si ya existe, no se crea otro.
     *
     * Los demás SÍ pueden repetirse con razón: un pedido lo pueden soltar dos
     * domiciliarios distintos, y un chat trae muchos mensajes.
     */
    private const UNA_VEZ_POR_PEDIDO = [
        'domiciliario_en_porteria',
        'pedido_nuevo',
        'pedido_aceptado',
        'pedido_en_camino',
        'domiciliario_llego',
        'pedido_entregado',
        'pedido_cancelado',
        'pedido_no_entregado',
        'pedido_listo_para_recoger',
        'pedido_programado_pronto',
        'pedido_programado_15',
        'recordatorio_programado',
        'recordatorio_programado_15',
    ];

    /**
     * @param  array<string, mixed>  $datos  Contexto para que tocar el aviso
     *                                       lleve a alguna parte.
     * @param  bool  $push  A falso cuando quien llama ya manda el push por su
     *                      cuenta. Lo usa el envío de promociones, que va en
     *                      bloque a todos los afiliados de una vez en lugar de
     *                      uno por persona; sin esto, cada cliente recibiría la
     *                      misma promoción DOS veces.
     * @param  string|null  $titulo  Para cuando el título no depende del tipo
     *                               sino del contenido: en un chat es el NOMBRE de quien
     *                               escribe, que es lo que hace que se reconozca en la
     *                               barra sin abrir nada.
     */
    public static function para(
        int|string $userId,
        string $tipo,
        string $mensaje,
        array $datos = [],
        bool $push = true,
        ?string $titulo = null,
    ): ?Notification {
        $pedido = $datos['order_id'] ?? null;

        /*
         * DE QUÉ LOCAL ES EL PEDIDO. Un dueño con dos negocios recibe los
         * avisos de los dos, y la app abre el tablero del que tenga activo:
         * sin esto, «¡Pedido nuevo!» del otro local llevaba a un tablero donde
         * el pedido no estaba. Con el negocio en el aviso, la app se pasa a ese
         * local antes de abrirlo. Se guarda también en la campana.
         */
        if ($pedido !== null && ! isset($datos['business_id'])) {
            $negocio = OrdersSales::whereKey($pedido)->value('busines_id');
            if ($negocio) {
                $datos['business_id'] = (int) $negocio;
            }
        }
        if ($pedido !== null && in_array($tipo, self::UNA_VEZ_POR_PEDIDO, true)) {
            $yaEsta = Notification::where('user_id', $userId)
                ->where('tipo', $tipo)
                ->where('datos->order_id', $pedido)
                ->first();
            if ($yaEsta) {
                return $yaEsta;
            }
        }

        try {
            $aviso = Notification::create([
                'user_id' => $userId,
                'tipo' => $tipo,
                'message' => $mensaje,
                'datos' => $datos,
                'read' => false,
                'date' => now(),
                'state' => true,
            ]);
        } catch (\Throwable $e) {
            // Si no se puede ni guardar, no hay aviso que dar. Se registra y se
            // sigue: ninguna operación debe caerse por no poder avisar.
            Log::warning('No se pudo guardar el aviso', [
                'user_id' => $userId,
                'tipo' => $tipo,
                'error' => $e->getMessage(),
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
                'error' => $e->getMessage(),
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
                    $titulo ?? self::TITULOS[$tipo] ?? "VeciPa'Ya",
                    $mensaje,
                    // El tipo viaja para que la app sepa a qué pantalla llevar
                    // al tocarla; sin él, el destino se perdería.
                    /*
                     * Y el ROL de quien lo recibe: «pedido cancelado» le llega
                     * al comprador, a la tienda y al domiciliario, y a cada
                     * uno lo lleva a una pantalla distinta. Con la app recién
                     * abierta desde la notificación, todavía no sabe quién
                     * entró.
                     */
                    ['tipo' => $tipo, 'rol' => (string) (User::whereKey($userId)->value('rol') ?? '')] + array_map(
                        fn ($v) => is_scalar($v) ? (string) $v : json_encode($v),
                        $datos,
                    ),
                );
            } catch (\Throwable $e) {
                Log::warning('No se pudo encolar el push del aviso', [
                    'notification_id' => $aviso->notification_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $aviso;
    }
}
