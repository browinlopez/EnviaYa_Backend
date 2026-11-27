<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EL RESIDENTE DICE SÍ O NO DESDE SU TELÉFONO.
 *
 * Hasta ahora «quién autorizó» era el nombre que el celador escribía después
 * de una llamada al citófono: nada comprobaba que esa persona hubiera dicho
 * que sí. Con esto el celador pregunta desde el panel, a los residentes de
 * ese apartamento les llega el aviso en la app, y la respuesta queda con
 * quién la dio y cuándo.
 *
 * Una solicitud vive pocos minutos (`expires_at`): una visita no espera media
 * hora en la puerta, y una respuesta tardía no debe abrir a nadie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complex_entry_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('complex_id');
            $t->foreign('complex_id')->references('complex_id')->on('residential_complexes')->cascadeOnDelete();
            $t->string('tower', 30);
            $t->string('apartment', 30);
            $t->string('visitor_name', 120);
            $t->string('kind', 30)->default('visitante');
            // pendiente | autorizada | rechazada | vencida
            $t->string('state', 20)->default('pendiente');
            $t->unsignedSmallInteger('residents_notified')->default(0);
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->foreign('requested_by')->references('user_id')->on('user')->nullOnDelete();
            $t->unsignedBigInteger('answered_by')->nullable();
            $t->foreign('answered_by')->references('user_id')->on('user')->nullOnDelete();
            $t->timestamp('answered_at')->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();

            $t->index(['complex_id', 'tower', 'apartment', 'state'], 'solicitudes_de_entrada_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complex_entry_requests');
    }
};
