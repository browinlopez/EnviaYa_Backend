<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SOLICITUDES DE LA WEB PÚBLICA
 *
 * La landing tiene seis formularios —contacto, alta de comercio, alta de
 * domiciliario, alta de conjunto, "suma tu barrio" y eliminación de cuenta— y
 * hasta ahora ninguno guardaba nada: la capa de envío estaba en modo
 * demostración, así que validaba, respondía "¡Listo!" y descartaba el
 * mensaje. Cada tendero que pidió entrar se perdió sin que nadie lo supiera.
 *
 * POR QUÉ NO VAN A `pqrs`
 *
 * Un PQRS es de alguien que YA es usuario y tiene una queja sobre algo que
 * pasó: lleva radicado, plazo por prioridad, y se cierra con una resolución.
 * Esto es lo contrario: gente de fuera que quiere entrar. No hay pedido, ni
 * negocio, ni usuario al que enlazarlo, y el plazo no depende de la urgencia
 * sino del tipo. Meterlos en la misma tabla obligaría a dejar en nulo la
 * mitad de sus columnas y a inventarles una prioridad para calcular un plazo
 * que no aplica.
 *
 * LAS COLUMNAS COMUNES SON LAS QUE SE FILTRAN
 *
 * Nombre, contacto, barrio y mensaje los piden casi todos los formularios y
 * son por lo que se busca en el panel, así que son columnas. Lo que cambia
 * según el tipo —el nombre del negocio, si el domiciliario trabaja en una
 * tienda, la relación con el conjunto— va en `payload`. Una columna por
 * campo de cada formulario dejaría una tabla de treinta columnas casi
 * siempre nulas, y obligaría a migrar la base cada vez que la web añade una
 * pregunta.
 *
 * HABEAS DATA (Ley 1581 de 2012)
 *
 * `policy_version`, `accepted_at` e `ip` no son telemetría: son la prueba de
 * la autorización. Una autorización sin versión del texto ni fecha no se
 * puede demostrar, y es exactamente lo que pide la Superintendencia cuando
 * alguien reclama que nunca autorizó nada. Por eso se guardan, y por eso la
 * eliminación de una solicitud tiene que borrarlos con ella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_requests', function (Blueprint $table) {
            $table->id();

            // El radicado que se le puede dar a la persona por teléfono.
            $table->string('code', 20)->unique();

            /*
             * De qué es la solicitud. Determina quién la atiende y, en el caso
             * de la eliminación, que tenga plazo legal.
             *
             * `otro` existe para que un formulario nuevo en la web no pierda
             * envíos mientras nadie migra este enum: entra como `otro`, se ve
             * en el panel y se clasifica después.
             */
            $table->enum('type', [
                'comercio',
                'domiciliario',
                'vecino',
                'conjunto',
                'cobertura',
                'eliminacion',
                'otro',
            ])->default('otro');

            // De qué formulario salió y en qué página estaba. Sirve para saber
            // qué sección de la web trae solicitudes y cuál no trae ninguna.
            $table->string('origin', 40)->nullable();
            $table->string('page', 120)->nullable();

            $table->string('name', 150)->nullable();

            /*
             * La web pide "Correo o WhatsApp" en un solo campo, porque
             * partirlo en dos hace que la mitad de la gente deje uno vacío.
             * Se guarda tal cual llegó, y aparte se guarda el correo cuando lo
             * que escribieron es un correo: así el panel puede ofrecer
             * "responder" sin adivinar.
             */
            $table->string('contact', 150)->nullable();
            $table->string('contact_email', 150)->nullable();

            $table->string('neighborhood', 150)->nullable();
            $table->text('message')->nullable();

            // Lo que cambia de un formulario a otro.
            $table->json('payload')->nullable();

            // 0 nueva | 1 en gestión | 2 atendida | 3 descartada
            $table->tinyInteger('state')->default(0);

            $table->unsignedBigInteger('assigned_to')->nullable();

            /*
             * Solo lo llevan las solicitudes de eliminación de cuenta: la web
             * promete públicamente quince días hábiles y esa promesa hay que
             * poder vigilarla. Las demás no tienen plazo comprometido, y
             * ponerles uno inventado convertiría el indicador en ruido.
             */
            $table->timestamp('due_at')->nullable();

            $table->timestamp('handled_at')->nullable();
            $table->text('resolution')->nullable();

            // La prueba de la autorización de tratamiento de datos.
            $table->string('policy_version', 20)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamps();

            // Así se consulta el tablero: lo pendiente, lo más reciente arriba.
            $table->index(['state', 'created_at'], 'landing_requests_estado_fecha_idx');
            $table->index(['type', 'created_at'], 'landing_requests_tipo_fecha_idx');
            $table->index('assigned_to');

            $table->foreign('assigned_to')->references('user_id')->on('user')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_requests');
    }
};
