<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuánto efectivo deja un negocio que su domiciliario lleve encima.
 *
 * Desde que existe la custodia de efectivo, el saldo de un domiciliario crece
 * con cada entrega contra entrega y sólo baja cuando consigna y alguien lo
 * confirma. Nada impedía que siguiera acumulando: al final del día podía
 * andar con un millón de pesos en el bolsillo, y eso es un riesgo para él
 * antes que para nadie.
 *
 * ES DEL NEGOCIO Y NO UN AJUSTE DE LA PLATAFORMA porque el riesgo lo asume
 * quien despacha: una droguería de barrio y un supermercado con veinte
 * repartidores no tienen el mismo apetito. Un número único obligaría a poner
 * el del más conservador.
 *
 * `null` = sin tope, que es como funciona hoy. La columna nace vacía a
 * propósito: activarlo tiene que ser una decisión y no algo que aparece solo
 * tras la migración y deja pedidos sin poder despachar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business', function (Blueprint $table) {
            /*
             * `decimal` y no entero: el resto del dinero de la plataforma es
             * decimal, y comparar un tope entero contra un saldo decimal
             * obliga a redondear en algún sitio — y el sitio donde se redondee
             * decide si un pedido pasa o no pasa.
             */
            $table->decimal('max_courier_cash', 12, 2)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('business', function (Blueprint $table) {
            $table->dropColumn('max_courier_cash');
        });
    }
};
