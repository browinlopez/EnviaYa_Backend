<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEGUNDO FACTOR Y SESIONES RECONOCIBLES
 *
 * Catorce cuentas entran al panel; unas cuantas ven y mueven dinero. Hasta ahora
 * la única defensa era una contraseña, y una contraseña filtrada no deja rastro
 * de que se filtró.
 *
 * EN `user`, tres columnas:
 *
 *  · `two_factor_secret` — el secreto TOTP, CIFRADO. Con el secreto en claro,
 *    quien lea la base puede generar los códigos igual que el teléfono: el
 *    segundo factor dejaría de serlo justo ante el atacante que más importa.
 *
 *  · `two_factor_confirmed_at` — separado del secreto a propósito. Entre generar
 *    el secreto y demostrar que la app lo tiene hay un paso, y activarlo antes
 *    de esa prueba deja fuera a quien escaneó mal el código.
 *
 *  · `two_factor_recovery_codes` — cifrados. Un teléfono se pierde, se rompe o
 *    se cambia; sin códigos de recuperación, el segundo factor convierte cada
 *    teléfono perdido en una cuenta perdida.
 *
 * EN `personal_access_tokens`, de dónde salió cada sesión. Sin esto, la lista de
 * sesiones activas dice "token #3, token #7" y no sirve para lo único que
 * existe: reconocer la que no es tuya y cerrarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // 45 caracteres: cabe una IPv6 completa.
            $table->string('ip', 45)->nullable()->after('last_used_at');
            $table->string('user_agent', 255)->nullable()->after('ip');
        });
    }

    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['ip', 'user_agent']);
        });
    }
};
