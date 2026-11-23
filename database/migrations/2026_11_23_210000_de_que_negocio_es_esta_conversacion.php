<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ==========================================================================
 * UNA CONVERSACIÓN ERA ENTRE DOS PERSONAS Y NO CON UN NEGOCIO.
 *
 * `chats` no tenía por quién preguntar: `type`, `created_at` y nada más. El
 * chat se buscaba por la PAREJA de personas:
 *
 *     Chat::where('type', 'private')
 *         ->whereHas('participants', fn ($q) => $q->where('user_id', $emisor))
 *         ->whereHas('participants', fn ($q) => $q->where('user_id', $destino))
 *
 * Con un dueño de un solo local eso funciona y nadie lo nota. Con dos, falla
 * de dos maneras y la segunda es seria:
 *
 *  1. El dueño ve las MISMAS conversaciones esté mirando el local que esté,
 *     porque no hay por qué filtrar.
 *
 *  2. Peor: si el mismo vecino escribe a los DOS locales del mismo dueño,
 *     los dos hilos se funden en UNO. La pregunta sobre un medicamento y la
 *     del almuerzo quedan en la misma conversación, mezcladas por fecha, y
 *     no hay forma de separarlas después.
 *
 * --------------------------------------------------------------------------
 * NULO A PROPÓSITO, Y NO ES DEJADEZ.
 *
 * Hay conversaciones que NO son de ningún negocio y nunca lo serán:
 *
 *  · domiciliario ↔ comprador, que habla de una entrega;
 *  · soporte ↔ dueño, que es el equipo respondiendo un reclamo.
 *
 * Forzar un negocio ahí sería inventarlo. Nulo quiere decir «esta
 * conversación no es de un mostrador», que es una respuesta, no un hueco.
 *
 * --------------------------------------------------------------------------
 * EL RELLENO SOLO TOCA LO QUE NO TIENE DUDA.
 *
 * Se rellenan los chats donde hablan exactamente un DUEÑO (rol 2) y un
 * COMPRADOR (rol 1), y el dueño tiene UN solo negocio. Si tiene dos, no hay
 * manera de saber a cuál le escribieron —justo el caso que motivó todo
 * esto—, así que se quedan nulos y el panel los sigue enseñando en los dos
 * locales, como hasta ahora. Adivinar aquí sería mover una conversación a un
 * mostrador al azar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $tabla) {
            $tabla->unsignedInteger('busines_id')->nullable()->after('type');

            /*
             * Índice por (negocio, chat): la consulta del panel es «los chats
             * de ESTE negocio», y sin él sería recorrer la tabla entera.
             */
            $tabla->index(['busines_id', 'chat_id'], 'chats_negocio_idx');

            $tabla->foreign('busines_id')
                ->references('busines_id')->on('business')
                ->nullOnDelete();
        });

        $this->rellenarLosQueNoTienenDuda();
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $tabla) {
            $tabla->dropForeign(['busines_id']);
            $tabla->dropIndex('chats_negocio_idx');
            $tabla->dropColumn('busines_id');
        });
    }

    private function rellenarLosQueNoTienenDuda(): void
    {
        /* Cuántos negocios tiene cada dueño, por su user_id. */
        $negociosDe = DB::table('owner_busines as ob')
            ->join('owner as o', 'o.owner_id', '=', 'ob.owner_id')
            ->select('o.user_id', 'ob.busines_id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($filas) => $filas->pluck('busines_id')->unique()->values());

        $participantes = DB::table('chat_participants as cp')
            ->join('user as u', 'u.user_id', '=', 'cp.user_id')
            ->get(['cp.chat_id', 'cp.user_id', 'u.rol'])
            ->groupBy('chat_id');

        foreach ($participantes as $chatId => $gente) {
            $duenos = $gente->where('rol', 2);
            $compradores = $gente->where('rol', 1);

            /* Exactamente un dueño y un comprador: lo demás no es un chat de
               mostrador —soporte, o una entrega— y se deja en paz. */
            if ($duenos->count() !== 1 || $compradores->count() !== 1 || $gente->count() !== 2) {
                continue;
            }

            $suyos = $negociosDe[$duenos->first()->user_id] ?? collect();

            if ($suyos->count() !== 1) {
                continue; // dos negocios: no se adivina
            }

            DB::table('chats')
                ->where('chat_id', $chatId)
                ->update(['busines_id' => (int) $suyos->first()]);
        }
    }
};
