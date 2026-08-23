<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El expediente de cada carga de Excel.
 *
 * Antes esto no existía y por eso el importador usaba `dump()` en medio del
 * bucle: no había dónde dejar el resultado, así que lo escupía en la salida de
 * la petición. Con un archivo de cuatrocientas filas eso son cuatrocientas
 * líneas de texto pegadas a la respuesta HTTP.
 *
 * Guardar la carga cambia tres cosas a la vez:
 *
 *  · Se puede ENCOLAR. Un Excel grande no cabe en el tiempo de una petición, y
 *    hoy el tendero se queda mirando una barra hasta que el navegador se rinde.
 *  · Se puede DECIR QUÉ PASÓ fila por fila. «Fila 34: la categoría "Lacteo" no
 *    existe» es accionable; «error al importar» no lo es.
 *  · Se puede VOLVER A MIRAR. La carga de ayer sigue ahí cuando el tendero
 *    pregunta por qué le faltan doce productos.
 *
 * `resumen` va en JSON y no en columnas porque lo que hay que contar cambia con
 * cada formato de archivo, y una columna por concepto obliga a migrar cada vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_uploads', function (Blueprint $table) {
            $table->id('catalog_upload_id');
            $table->unsignedBigInteger('busines_id');
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('archivo', 255);
            $table->string('nombre_original', 255)->nullable();

            // pendiente | procesando | terminada | fallida
            $table->string('estado', 20)->default('pendiente');

            $table->unsignedInteger('filas')->default(0);
            $table->unsignedInteger('creados')->default(0);
            $table->unsignedInteger('actualizados')->default(0);
            $table->unsignedInteger('rechazados')->default(0);

            /*
             * Los errores, con número de fila. Se recortan a los primeros
             * doscientos: quien sube un archivo con mil filas malas no
             * necesita leerlas todas, necesita darse cuenta de que el archivo
             * está mal.
             */
            $table->json('resumen')->nullable();

            $table->timestamps();

            $table->index(['busines_id', 'created_at'], 'ix_cargas_por_negocio');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_uploads');
    }
};
