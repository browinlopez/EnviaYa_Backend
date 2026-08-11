<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla estándar del password broker de Laravel. Se perdió cuando la
     * migración por defecto de users (que también la creaba) fue reemplazada
     * por la tabla custom `user`. Sin ella, /forgot-password revienta al
     * intentar guardar el token. hasTable() la hace segura en bases que ya
     * la tengan creada de un deploy antiguo.
     */
    public function up(): void
    {
        if (Schema::hasTable('password_reset_tokens')) {
            return;
        }

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
