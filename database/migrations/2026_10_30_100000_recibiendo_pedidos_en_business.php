<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «RECIBIENDO PEDIDOS»: EL TENDERO PUEDE PARAR SIN DARSE DE BAJA
 *
 * Hasta ahora la única manera de que no entrara un pedido era `state`, que es
 * el alta y la baja de la tienda en la plataforma — una decisión del equipo,
 * que `MiNegocioController@actualizar` le niega al tendero a propósito y con
 * razón: si pudiera apagarse a sí mismo desaparecería del catálogo sin que
 * nadie supiera por qué.
 *
 * Pero el motivo por el que de verdad quiere parar es otro y dura una hora: se
 * le acabó el gas, se quedó solo en el mostrador, está cerrando. Para eso no
 * hay nada, y lo que hacía era dejar los pedidos sin aceptar hasta que la
 * tarjeta se ponía roja.
 *
 * SON DOS COLUMNAS Y NO UNA porque son dos decisiones de dos personas
 * distintas, con dos duraciones distintas. Mezclarlas en `state` significaría
 * que el equipo no puede distinguir una tienda dada de baja de una tienda que
 * cerró a almorzar.
 *
 * Arranca en 1: todas las tiendas que ya existen están recibiendo, que es como
 * funcionaban ayer. La columna no necesita un solo UPDATE de arranque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business', function (Blueprint $table) {
            $table->boolean('accepting_orders')->default(true)->after('state');

            /*
             * Cuándo fue la última vez que se tocó el interruptor. No es
             * auditoría —de eso ya se encarga `Audit`—: es para poder decirle
             * al tendero «llevas cuatro horas sin recibir pedidos», que es el
             * error que esta función introduce. Quien pausa a las 2 de la
             * tarde se le olvida, y una tienda apagada sin querer no se queja:
             * simplemente deja de vender.
             */
            $table->timestamp('accepting_changed_at')->nullable()->after('accepting_orders');
        });
    }

    public function down(): void
    {
        Schema::table('business', function (Blueprint $table) {
            $table->dropColumn(['accepting_orders', 'accepting_changed_at']);
        });
    }
};
