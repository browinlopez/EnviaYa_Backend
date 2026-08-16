<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Papelería vigente de cada domiciliario.
 *
 * Es la tabla que le faltaba a SST. La plataforma pone a trabajar gente en la
 * calle en moto, y hasta ahora no había dónde registrar si su licencia, su
 * SOAT o su afiliación a la ARL seguían vigentes. Un domiciliario rodando con
 * el SOAT vencido es responsabilidad de la empresa, y sin esto nadie se entera
 * hasta que pasa algo.
 *
 * `expires_at` es el corazón del módulo: no está para consultarlo cuando
 * alguien se acuerde, sino para que el panel avise ANTES. Por eso va indexado
 * junto al estado, que es como se consulta ("qué vence en los próximos 30
 * días").
 *
 * El archivo escaneado va a `media_files` con entity_type 'sst', igual que el
 * resto de medios: la foto de la licencia es un documento más y no merece un
 * mecanismo propio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domiciliary_documents', function (Blueprint $table) {
            $table->id();

            // `domiciliary.domiciliary_id` es INT UNSIGNED (increments).
            $table->unsignedInteger('domiciliary_id');

            /*
             * Qué documento es. Los cinco primeros son los que la ley exige
             * para repartir en moto en Colombia; `otro` deja registrar lo que
             * pida un cliente corporativo sin tener que migrar la tabla.
             */
            $table->enum('type', [
                'licencia',       // licencia de conducción
                'soat',
                'tecnomecanica',
                'arl',            // afiliación a riesgos laborales
                'eps',            // afiliación a salud
                'examen_medico',  // examen médico ocupacional
                'cedula',
                'otro',
            ]);

            $table->string('number', 60)->nullable();
            $table->date('issued_at')->nullable();

            /*
             * Nulo se admite a propósito: la cédula y algunos exámenes no
             * caducan. Un documento sin fecha simplemente no entra en las
             * alertas, en vez de obligar a inventarle un vencimiento.
             */
            $table->date('expires_at')->nullable();

            // Referencia al archivo en media_files, si se subió el escaneo.
            $table->unsignedBigInteger('media_id')->nullable();

            $table->string('notes', 255)->nullable();
            $table->tinyInteger('state')->default(1); // 1 vigente | 0 archivado

            $table->timestamps();

            // Así se consulta el tablero de vencimientos.
            $table->index(['state', 'expires_at'], 'doc_vencimiento_idx');
            $table->index(['domiciliary_id', 'type'], 'doc_domiciliario_tipo_idx');

            $table->foreign('domiciliary_id')
                ->references('domiciliary_id')->on('domiciliary')
                ->cascadeOnDelete();

            $table->foreign('media_id')
                ->references('id')->on('media_files')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domiciliary_documents');
    }
};
