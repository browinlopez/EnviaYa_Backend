<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El botón de SOS aterriza en la tabla que ya existe para esto.
 *
 * `safety_incidents` nació para que «lo que hoy se cuenta por WhatsApp» quede
 * en alguna parte —lo dice su propia migración— y ya tiene su página en el
 * panel. Lo que le falta es poder recibir un aviso desde el teléfono, en
 * caliente, en vez de que alguien lo teclee días después.
 *
 * TRES COSAS FALTAN, y ninguna es decorativa:
 *
 * · COORDENADAS. Hoy el sitio es `location`, texto de 255 caracteres, escrito
 *   por quien registra el incidente en el panel. Para un parte a posteriori
 *   sirve; para ir a buscar a alguien, no. Un SOS sin coordenadas es un SOS
 *   que no dice dónde.
 *
 * · `business_id`. La tabla sólo sabe de domiciliarios, porque se pensó para
 *   la operación de reparto. El tendero también está solo en un local y
 *   también lo extorsionan, y sin esta columna su aviso llegaría sin decir de
 *   qué tienda salió.
 *
 * · `extorsion` EN EL VOCABULARIO. No estaba. No es «otro»: es el motivo que
 *   más veces se cuenta por el grupo de WhatsApp y el que más necesita
 *   contarse aparte para poder medirlo.
 *
 * Se usan `latitude`/`longitude` y `business_id` —y no `lat`/`lng`— porque es
 * como se llaman en `business`, `user_address` y `pqrs`, que es la tabla
 * hermana de ésta. Un nombre nuevo para la misma cosa obliga a recordar cuál
 * va en cada sitio.
 *
 * El enum se toca con `change()` y no con un `ALTER ... MODIFY` a mano: en
 * SQLite —el motor de las pruebas— Laravel impone el enum con una restricción
 * CHECK, y ahí un INSERT con el valor nuevo fallaría igual. Mismo criterio que
 * `add_envio_gratis_a_cupones`.
 */
return new class extends Migration
{
    private const TIPOS_CON_SOS = [
        'accidente_transito',
        'caida',
        'robo',
        'agresion',
        'extorsion',
        'falla_vehiculo',
        'condicion_insegura',
        'otro',
    ];

    private const TIPOS_SIN_SOS = [
        'accidente_transito',
        'caida',
        'robo',
        'agresion',
        'falla_vehiculo',
        'condicion_insegura',
        'otro',
    ];

    public function up(): void
    {
        Schema::table('safety_incidents', function (Blueprint $table) {
            $table->unsignedInteger('business_id')->nullable()->after('order_id');

            $table->decimal('latitude', 10, 7)->nullable()->after('location');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');

            $table->foreign('business_id')
                ->references('busines_id')->on('business')
                ->nullOnDelete();
        });

        Schema::table('safety_incidents', function (Blueprint $table) {
            $table->enum('type', self::TIPOS_CON_SOS)->change();
        });
    }

    public function down(): void
    {
        // Las extorsiones pasan a «otro» antes de estrechar el vocabulario: si
        // no, la restricción las rechaza y la bajada se queda a medias.
        DB::table('safety_incidents')->where('type', 'extorsion')->update(['type' => 'otro']);

        Schema::table('safety_incidents', function (Blueprint $table) {
            $table->enum('type', self::TIPOS_SIN_SOS)->change();
        });

        Schema::table('safety_incidents', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->dropColumn(['business_id', 'latitude', 'longitude']);
        });
    }
};
