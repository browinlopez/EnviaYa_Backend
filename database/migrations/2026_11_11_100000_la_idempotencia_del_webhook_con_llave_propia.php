<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UNA LLAVE PROPIA PARA NO PROCESAR DOS VECES EL MISMO AVISO DE BOLD.
 *
 * `BoldWebhookController` ya evitaba el duplicado, pero preguntando
 * `PaymentEvent::where('payload->id', $id)->exists()`. Medido con `EXPLAIN`:
 *
 *     type=ALL   key=NINGUNA
 *
 * Un **escaneo completo de `payment_events` en cada aviso de Bold**, y esa
 * tabla crece con cada notificación para siempre: cada cobro recorre todos los
 * cobros anteriores. No hay índice posible sobre `payload->id` porque es una
 * ruta dentro de un JSON.
 *
 * Con una columna propia la pregunta es una búsqueda por índice, y además
 * puede ser ÚNICA — que es la diferencia entre comprobar y garantizar:
 *
 *  · el guardia de antes es comprobar-luego-insertar. Dos entregas
 *    simultáneas de la misma notificación —Bold reintenta cuando una tarda—
 *    pasan las dos la comprobación antes de que ninguna haya insertado;
 *  · y si el aviso llega SIN `id`, el guardia se saltaba entero
 *    (`if ($notificationId && ...)`).
 *
 * LO QUE HOY SALVA ESO NO ES EL GUARDIA, y conviene decirlo: es que
 * `ConfirmacionDePago::confirmar()` es idempotente a propósito —mira
 * `esperandoPago()` ANTES de marcar pagado, así que una segunda pasada no
 * vuelve a anunciar el pedido—. O sea que un duplicado hoy no cobra dos veces
 * ni avisa dos veces. Esto es defensa en profundidad, no la reparación de una
 * avería en curso: lo que se gana es que la corrección deje de depender de un
 * `if` en otro archivo, que es donde se cae cuando alguien lo refactoriza.
 *
 * LOS NULOS NO CHOCAN entre sí en un índice único de MySQL, así que los avisos
 * que no traigan llave siguen entrando como hasta hoy; el controlador les
 * calcula una a partir del cuerpo, pero si alguna vez no la hubiera, esto no
 * los rechaza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_events', function (Blueprint $table) {
            $table->string('notification_id', 120)->nullable()->after('provider');
        });

        /*
         * Lo que ya está en la tabla se rellena desde el JSON, para que el
         * índice sirva desde el primer día y no solo para lo que llegue
         * después.
         *
         * SOLO EN MySQL, y no es pereza: `json_unquote` no existe en SQLite, y
         * la suite corre las migraciones sobre SQLite en memoria. Sin esta
         * guarda la migración revienta y se lleva las 1211 pruebas por delante
         * —medido, así salió—. Y ahí no hace falta: una base recién migrada no
         * tiene filas que rellenar.
         */
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "update payment_events
                 set notification_id = json_unquote(json_extract(payload, '$.id'))
                 where notification_id is null
                   and json_extract(payload, '$.id') is not null"
            );
        }

        /*
         * Y SI YA HAY DUPLICADOS, se quedan el más viejo.
         *
         * Sin esto el índice único no se puede crear y la migración revienta en
         * producción —que es precisamente donde puede haber duplicados, porque
         * el guardia de antes tenía la carrera que esto viene a cerrar—. Dos
         * filas con la misma llave de notificación SON la misma notificación:
         * la llave es de Bold y la pone Bold.
         */
        $sobrantes = DB::table('payment_events as e')
            ->whereNotNull('e.notification_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('payment_events as v')
                ->whereColumn('v.notification_id', 'e.notification_id')
                ->whereColumn('v.id', '<', 'e.id'))
            ->pluck('e.id');

        if ($sobrantes->isNotEmpty()) {
            DB::table('payment_events')->whereIn('id', $sobrantes)->delete();
        }

        Schema::table('payment_events', function (Blueprint $table) {
            $table->unique('notification_id', 'ux_pagos_eventos_notificacion');
        });
    }

    public function down(): void
    {
        Schema::table('payment_events', function (Blueprint $table) {
            $table->dropUnique('ux_pagos_eventos_notificacion');
            $table->dropColumn('notification_id');
        });
    }
};
