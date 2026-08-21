<?php

namespace App\Services;

use App\Events\AvisoPersonal;
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
 */
class Avisos
{
    /**
     * @param  array<string, mixed>  $datos  Contexto para que tocar el aviso
     *                                       lleve a alguna parte.
     */
    public static function para(
        int|string $userId,
        string $tipo,
        string $mensaje,
        array $datos = [],
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

        return $aviso;
    }
}
