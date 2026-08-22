<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QUIÉN ES DE QUÉ CONJUNTO.
 *
 * Este es el cimiento que faltaba, y no es una tabla más: hasta ahora todo el
 * sistema de permisos era POR MÓDULO y jamás por registro. `EnsureModuleAccess`
 * responde "¿puede ver la sección Conjuntos?", nunca "¿puede ver ESTE
 * conjunto?". Sin esta tabla, un dueño de conjunto con el módulo concedido
 * vería los conjuntos de todo el país.
 *
 * Dos roles nuevos, con la misma tabla porque comparten el vínculo y solo
 * cambia qué pueden hacer dentro:
 *
 *   dueño    ve su conjunto entero y administra a sus celadores
 *   celador  registra entradas de domiciliarios en la portería
 *
 * `rol` NO es autoincremental —es una tabla catálogo con la clave puesta a
 * mano— así que los identificadores se dan explícitos.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 5 y 6 continúan la serie: 1 comprador, 2 tendero, 3 domiciliario,
        // 4 personal interno.
        foreach ([5 => 'dueno_conjunto', 6 => 'celador'] as $id => $nombre) {
            DB::table('rol')->updateOrInsert(
                ['rol_id' => $id],
                ['name' => $nombre, 'guard_name' => 'web'],
            );
        }

        Schema::create('complex_staff', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')
                ->references('user_id')->on('user')->cascadeOnDelete();

            $table->unsignedBigInteger('complex_id');
            $table->foreign('complex_id')
                ->references('complex_id')->on('residential_complexes')
                ->cascadeOnDelete();

            // dueno | celador
            $table->string('role', 20);

            $table->boolean('state')->default(true);

            /*
             * Quién lo dio de alta. Los celadores los crea el dueño del
             * conjunto, y conviene poder responder quién le dio acceso a la
             * portería a quién.
             */
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')
                ->references('user_id')->on('user')->nullOnDelete();

            $table->timestamps();

            /*
             * Una persona pertenece a UN conjunto.
             *
             * Es una decisión, no una limitación técnica: con varios habría
             * que preguntarle en cada pantalla cuál está mirando, y la portería
             * —donde se usa esto de verdad— es de un edificio concreto.
             */
            $table->unique('user_id', 'personal_conjunto_unico');
            $table->index(['complex_id', 'role'], 'personal_conjunto_rol_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complex_staff');
        DB::table('rol')->whereIn('rol_id', [5, 6])->delete();
    }
};
