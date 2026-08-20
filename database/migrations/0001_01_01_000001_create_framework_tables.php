<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAS TABLAS DEL FRAMEWORK QUE FALTABAN
 *
 * `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` y `sessions` son
 * de Laravel, y sus migraciones de fábrica no estaban en el repositorio. La
 * base de producción sí las tiene —se crearon alguna vez y luego el archivo
 * desapareció— así que nadie lo notó: en desarrollo se trabaja sobre una copia
 * importada de producción, no sobre una base creada desde cero.
 *
 * Se nota al desplegar. Con una base nueva, `migrate` deja 83 tablas de las 97
 * que hacen falta, y como esta plataforma usa la base para las tres cosas
 * —CACHE_STORE, QUEUE_CONNECTION y SESSION_DRIVER en `database`— el resultado
 * es que la API responde 500 a casi todo, el trabajador de la cola muere al
 * arrancar y nadie puede iniciar sesión. Verificado levantando la pila en
 * Docker contra un MySQL vacío.
 *
 * Cada tabla lleva su comprobación: en la base que ya existe esta migración no
 * hace nada, y en una nueva la deja igual que la de producción.
 *
 * El nombre empieza por 0001_01_01 —la convención de Laravel para lo que va
 * primero— porque `create_permission_tables` vacía la caché al correr, y sin la
 * tabla `cache` esa migración revienta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        if (!Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }

        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (!Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        if (!Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        if (!Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }
    }

    public function down(): void
    {
        /*
         * A propósito no se borra nada.
         *
         * Un `migrate:rollback` en producción tirando la tabla de sesiones deja
         * a todo el mundo fuera, y tirando `jobs` pierde el trabajo encolado.
         * Estas tablas son del framework y llevan ahí desde antes que esta
         * migración: deshacerla no debería llevárselas por delante.
         */
    }
};
