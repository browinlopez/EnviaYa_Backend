<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HASTA DÓNDE LEYÓ CADA UNO EN CADA CHAT.
 *
 * La app pintaba un número de mensajes sin leer por conversación y el
 * servidor nunca lo mandaba: no guardaba nada que permitiera contarlo.
 *
 * Se guarda EL ÚLTIMO MENSAJE LEÍDO y no una hora. La hora del mensaje la
 * pone la base (en UTC) y una hora de lectura la pondría la aplicación (en la
 * de Colombia): compararlas daba cinco horas de error. El número del mensaje
 * solo crece, así que «sin leer» son los de los demás con número mayor.
 *
 * Los que ya participan arrancan con todo leído: si no, el día de la
 * actualización todos verían como nuevos los mensajes de meses atrás.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_participants', function (Blueprint $t) {
            $t->unsignedBigInteger('last_read_message_id')->nullable()->after('joined_at');
        });

        $ultimos = DB::table('messages')
            ->selectRaw('chat_id, MAX(message_id) as ultimo')
            ->groupBy('chat_id')
            ->pluck('ultimo', 'chat_id');

        foreach ($ultimos as $chatId => $ultimo) {
            DB::table('chat_participants')
                ->where('chat_id', $chatId)
                ->update(['last_read_message_id' => $ultimo]);
        }
    }

    public function down(): void
    {
        Schema::table('chat_participants', fn (Blueprint $t) => $t->dropColumn('last_read_message_id'));
    }
};
