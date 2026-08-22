<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La portería deja de ser sólo de domiciliarios de EnviaYa.
 *
 * Hasta ahora `complex_entries` respondía una única pregunta —¿este
 * domiciliario tiene pedidos aquí?— y todo lo demás que pasa por la puerta no
 * dejaba rastro: visitas, personal de servicio, contratistas, mudanzas, y los
 * domicilios de OTRAS plataformas.
 *
 * El efecto era que el conjunto no tenía su minuta. El resumen decía
 * «entradas» cuando en realidad eran «entradas de domiciliarios de EnviaYa»,
 * una fracción del movimiento real del edificio.
 *
 * DOS CAMBIOS DE FONDO
 *
 * 1. `domiciliary_id` pasa a ser NULLABLE. Un visitante no es usuario de la
 *    plataforma y no tiene ninguna fila en `domiciliary`; obligarlo a tenerla
 *    sería crear cuentas fantasma para gente que sólo vino a visitar a su
 *    hermana.
 *
 * 2. Aparece `exited_at`. Un libro de portería sin salidas no puede responder
 *    la pregunta que más se hace en una portería: «¿cuánta gente hay adentro
 *    ahora mismo?». Las entradas anteriores quedan con salida nula, que es
 *    exactamente lo que se sabe de ellas: nada.
 *
 * Los datos del visitante van EN ESTA TABLA y no en una de personas. Quien
 * entra hoy a ver a un vecino no vuelve, y una tabla de visitantes se llenaría
 * de filas de un solo uso con nombres mal escritos que nadie deduplica. Lo que
 * importa es el hecho —entró, a esta hora, a este apartamento— y eso es la
 * entrada.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Quitar la clave foránea antes de aflojar la columna: MySQL no deja
         * cambiar una columna que participa en una FK.
         */
        Schema::table('complex_entries', function (Blueprint $table) {
            $table->dropForeign(['domiciliary_id']);
        });

        Schema::table('complex_entries', function (Blueprint $table) {
            $table->unsignedInteger('domiciliary_id')->nullable()->change();

            $table->foreign('domiciliary_id')
                ->references('domiciliary_id')->on('domiciliary')
                ->cascadeOnDelete();
        });

        Schema::table('complex_entries', function (Blueprint $table) {
            /*
             * Qué clase de entrada es. Con valor por defecto para que las
             * filas que ya existen queden bien clasificadas sin tocarlas: son
             * todas de domiciliarios, porque hasta hoy no había otra cosa.
             */
            $table->string('kind', 20)->default('domiciliario')->after('complex_id');

            // Datos de quien entra cuando NO es un domiciliario del sistema.
            $table->string('visitor_name', 120)->nullable()->after('method');
            $table->string('visitor_document', 40)->nullable()->after('visitor_name');
            $table->string('visitor_phone', 40)->nullable()->after('visitor_document');
            /* De qué empresa viene: la plataforma de domicilios, la empresa de
               gas, la inmobiliaria. Es lo que distingue una visita personal de
               un servicio, y no se puede deducir del nombre. */
            $table->string('visitor_company', 120)->nullable()->after('visitor_phone');
            $table->string('vehicle_plate', 20)->nullable()->after('visitor_company');

            // A dónde va. Para un domiciliario sale de sus pedidos; para un
            // visitante lo escribe el celador.
            $table->string('tower', 30)->nullable()->after('vehicle_plate');
            $table->string('apartment', 30)->nullable()->after('tower');

            /* Quién lo autorizó, tal como lo dijo el celador. Texto libre a
               propósito: el residente que contesta el citófono no siempre es
               el titular registrado, y forzar a elegirlo de una lista haría
               que se anotara al titular aunque hubiera contestado otro. */
            $table->string('authorized_by', 120)->nullable()->after('apartment');

            $table->text('notes')->nullable()->after('authorized_by');

            $table->timestamp('exited_at')->nullable()->after('notes');
            $table->unsignedBigInteger('exit_registered_by')->nullable()->after('exited_at');

            /*
             * El índice que sostiene todas las consultas del panel: siempre se
             * filtra por conjunto y se ordena por fecha. Sin él, el listado y
             * el tablero recorren la tabla entera de todos los conjuntos.
             */
            $table->index(['complex_id', 'created_at'], 'idx_entries_conjunto_fecha');
            // Para «¿quién está adentro?»: las que aún no tienen salida.
            $table->index(['complex_id', 'exited_at'], 'idx_entries_dentro');
        });
    }

    public function down(): void
    {
        Schema::table('complex_entries', function (Blueprint $table) {
            $table->dropIndex('idx_entries_conjunto_fecha');
            $table->dropIndex('idx_entries_dentro');

            $table->dropColumn([
                'kind', 'visitor_name', 'visitor_document', 'visitor_phone',
                'visitor_company', 'vehicle_plate', 'tower', 'apartment',
                'authorized_by', 'notes', 'exited_at', 'exit_registered_by',
            ]);
        });

        /*
         * `domiciliary_id` se deja nullable al revertir. Volverlo obligatorio
         * exigiría inventarse un domiciliario para cada visita registrada
         * mientras tanto, o borrarlas; las dos cosas son peores que una
         * columna más floja de lo que era.
         */
    }
};
