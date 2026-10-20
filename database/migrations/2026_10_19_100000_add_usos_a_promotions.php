<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CUÁNTAS VECES SE PUEDE USAR UNA PROMOCIÓN.
 *
 * El tendero pide poder decir «los primeros 50». Hoy no puede: una promoción
 * vale hasta que vence o hasta que la retira a mano, y si se le va de las
 * manos un 50 % en un producto, lo descubre mirando las ventas.
 *
 * `max_usos` NULL significa «sin tope», que es como se comportan todas las
 * promociones que ya existen. Por eso no hace falta tocar una sola fila.
 *
 * `usos` empieza en cero para todas, incluidas las viejas, y eso es
 * HONESTO y no una pérdida de datos: hasta hoy nadie contaba los usos. La
 * tabla `orders_promotions` existe desde 2025 con su índice único y sus dos
 * relaciones declaradas, pero NUNCA se ha escrito en ella —el descuento se
 * calculaba y se aplicaba sin dejar constancia de qué promoción lo causó—.
 * Así que no hay un histórico que recuperar: el contador arranca cuando
 * arranca la redención.
 *
 * Esa es la parte que de verdad se construye acá: contar usos obliga a
 * escribir la redención que faltaba, en `ArmadoDelPedido`, al lado del canje
 * del cupón y dentro de la misma transacción del pedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            /*
             * NULL = sin tope. Es el valor que deja a las promociones de hoy
             * comportándose exactamente igual que ayer.
             */
            $table->unsignedInteger('max_usos')->nullable()->after('end_date');

            /*
             * El contador. Se incrementa con un UPDATE condicional
             * (`usos < max_usos`) dentro de la transacción del pedido, igual
             * que `coupons.uses_count`: entre validar y guardar pasa tiempo, y
             * con una promoción que funciona hay varias personas canjeando el
             * último uso a la vez.
             */
            $table->unsignedInteger('usos')->default(0)->after('max_usos');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn(['max_usos', 'usos']);
        });
    }
};
