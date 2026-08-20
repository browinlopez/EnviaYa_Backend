<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TOKENS DE DISPOSITIVO PARA LAS NOTIFICACIONES
 *
 * Lo que faltaba para que el módulo de Notificaciones entregara algo. Hasta
 * ahora resolvía el segmento, guardaba a cuánta gente alcanzaba y cerraba la
 * campaña — sin mandar nada a ningún teléfono. El servidor lo decía
 * (`delivered: false`), pero cualquiera que usara la pantalla creía que había
 * enviado.
 *
 * Decisiones:
 *
 *  · La clave única es el TOKEN, no el par usuario-token. El mismo teléfono
 *    puede cambiar de dueño (alguien cierra sesión y entra otro), y entonces el
 *    token pasa a la persona nueva. Con el par como clave quedarían dos filas
 *    vivas y la notificación llegaría al teléfono equivocado.
 *
 *  · `last_seen_at` en vez de borrar. Un token deja de servir cuando el usuario
 *    desinstala, y de eso solo se entera el proveedor al rechazar el envío.
 *    Guardar cuándo se usó por última vez permite limpiar los muertos sin
 *    perder el rastro de por qué se fueron.
 *
 *  · `failed_at` y `fail_reason`: cuando FCM contesta que un token ya no vale,
 *    se marca en vez de borrarlo en el acto. Un fallo puntual de red no puede
 *    costar la suscripción de un usuario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->string('token', 255)->unique();
            $table->string('platform', 10);          // android | ios | web
            $table->string('app_version', 20)->nullable();
            $table->string('device_name', 80)->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('fail_reason', 120)->nullable();

            $table->timestamps();

            $table->foreign('user_id')
                ->references('user_id')->on('user')
                ->cascadeOnDelete();

            // Segmentar es "todos los compradores de Soledad": se recorre por
            // usuario, no por token.
            $table->index(['user_id', 'failed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
