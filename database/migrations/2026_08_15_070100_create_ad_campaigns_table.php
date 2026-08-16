<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La campaña agrupa banners bajo un mismo trato comercial.
 *
 * Se separa de `banners` porque lo que se vende y se cobra es la campaña
 * ("pauta de agosto, 800.000"), mientras que las piezas cambian dentro de ella:
 * un banner para la app y otro para la web, o uno nuevo a mitad de mes porque
 * el anterior rendía mal. Con todo en una sola tabla, cambiar la pieza
 * obligaría a duplicar el presupuesto y las fechas.
 *
 * La vigencia vive acá y también en el banner: la de la campaña es el marco
 * comercial y la del banner es opcional dentro de ese marco. Un banner sin
 * fechas propias hereda las de su campaña.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('advertiser_id');

            $table->string('name', 150);
            $table->text('description')->nullable();

            /*
             * Qué se persigue. No cambia el comportamiento del servidor, pero
             * es lo que decide qué métrica mirar: en `awareness` importa la
             * impresión y en `traffic` el clic, y sin declararlo cada quien
             * juzga la campaña con la métrica que le convenga.
             */
            $table->enum('objective', ['awareness', 'traffic', 'conversion'])
                ->default('traffic');

            $table->date('starts_at');
            $table->date('ends_at');

            // Lo pactado con el anunciante, en COP. Sirve para el reporte de
            // ingresos por pauta; no limita las entregas por sí solo.
            $table->decimal('budget', 12, 2)->default(0);

            // 0 borrador | 1 activa | 2 pausada | 3 finalizada
            $table->tinyInteger('state')->default(0);

            $table->timestamps();

            $table->index(['state', 'starts_at', 'ends_at'], 'campana_vigencia_idx');
            $table->index('advertiser_id');

            $table->foreign('advertiser_id')
                ->references('id')->on('advertisers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_campaigns');
    }
};
