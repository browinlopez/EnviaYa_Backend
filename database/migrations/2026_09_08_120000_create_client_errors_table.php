<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOS FALLOS QUE REVIENTAN LA APP EN EL TELÉFONO DE ALGUIEN
 *
 * Hasta ahora no había NADA: ni Sentry, ni Crashlytics, ni un registro propio.
 * Una excepción al pintar dejaba la pantalla en blanco y del lado del servidor
 * no quedaba rastro. Lo único que se veía era una desinstalación, sin saber por
 * qué. Con la aplicación saliendo a producción, eso es la diferencia entre «me
 * falló» y algo que se pueda arreglar.
 *
 * NO ES UNA TABLA DE REGISTRO GENÉRICA. Solo entra lo que rompió la pantalla,
 * que es poco y vale mucho. Los errores esperados —un 422, una tienda que no
 * reparte— ya se manejan donde ocurren y no tienen por qué acabar acá.
 *
 * `user_id` va sin clave foránea y aceptando nulo A PROPÓSITO: la app puede
 * reventar antes de iniciar sesión, y un fallo al guardar el informe del fallo
 * sería la peor forma de perderlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_errors', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('platform', 10)->nullable();     // ios | android
            $table->string('app_version', 20)->nullable();
            $table->string('pantalla', 120)->nullable();

            $table->string('mensaje', 500);
            $table->text('traza')->nullable();

            $table->timestamp('created_at')->nullable();

            /*
             * Para la consulta que de verdad se hace: «qué está fallando esta
             * semana». Buscar por usuario es lo raro; contar por fecha es lo
             * primero que mira cualquiera.
             */
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_errors');
    }
};
