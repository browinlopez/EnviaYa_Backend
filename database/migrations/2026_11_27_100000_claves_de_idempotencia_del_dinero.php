<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->foreign('user_id')->references('user_id')->on('user')->cascadeOnDelete();
            $t->string('idempotency_key', 36);
            $t->string('method', 10);
            $t->string('path', 255);
            $t->char('fingerprint', 64);
            // Null mientras la petición sigue en curso.
            $t->unsignedSmallInteger('status_code')->nullable();
            $t->longText('response_body')->nullable();
            $t->string('response_content_type', 100)->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();

            // La unicidad la pone la base: un firstOrCreate no evita la carrera.
            $t->unique(['user_id', 'idempotency_key'], 'idempotencia_usuario_clave_unica');
            $t->index('expires_at', 'idempotencia_vence_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
