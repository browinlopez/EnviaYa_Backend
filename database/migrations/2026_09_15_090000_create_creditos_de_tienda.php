<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRÉDITO DE LA TIENDA: el «fiado» del barrio, dentro de la app.
 *
 * El tendero le da a un comprador afiliado un CUPO. El comprador paga pedidos
 * con él hasta agotarlo, y cuando le paga al tendero —en la tienda, por fuera
 * de la app— el tendero registra el abono y el cupo se libera. Es rotativo.
 *
 * Ese dinero nunca pasa por la plataforma: lo cobra la tienda. Por eso cada
 * pedido a crédito se DESCUENTA de la liquidación de la tienda (productos +
 * domicilio), y la liquidación puede quedar en negativo: se arrastra al corte
 * siguiente.
 *
 * Cuatro piezas:
 *  · `business.credit_debt_cap`  — el tope que pone el equipo por tienda. La
 *    suma de cupos no puede pasarlo, y con una deuda arrastrada igual o mayor
 *    la tienda no vende más a crédito. Nulo: la tienda no tiene crédito.
 *  · `store_credits`             — un cupo por tienda y comprador.
 *  · `store_credit_movements`    — el libro. Lo usado es la suma: consumo +,
 *    reverso −, abono −, ajuste ±. Nadie guarda «usado» en una columna que se
 *    pueda desincronizar del libro.
 *  · `settlements` / `settlement_items` — cuánto se vendió a crédito y el
 *    saldo negativo que entra arrastrado del corte anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business', function (Blueprint $table) {
            $table->decimal('credit_debt_cap', 14, 2)->nullable()->after('max_courier_cash');
        });

        Schema::create('store_credits', function (Blueprint $table) {
            $table->id();
            // int y no bigint: `business.busines_id` es `int unsigned`.
            $table->unsignedInteger('busines_id');
            $table->foreign('busines_id')->references('busines_id')->on('business')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('user_id')->on('user')->cascadeOnDelete();
            $table->decimal('credit_limit', 12, 2)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('user_id')->on('user')->nullOnDelete();
            $table->timestamps();

            $table->unique(['busines_id', 'user_id'], 'credito_tienda_comprador_unico');
        });

        Schema::create('store_credit_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_credit_id');
            $table->foreign('store_credit_id')->references('id')->on('store_credits')->cascadeOnDelete();
            // consumo · reverso · abono · ajuste
            $table->string('type', 20);
            $table->decimal('amount', 12, 2);
            $table->unsignedInteger('order_id')->nullable();
            $table->foreign('order_id')->references('orderSales_id')->on('orderssales')->nullOnDelete();
            $table->string('notes', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('user_id')->on('user')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_credit_id', 'created_at'], 'movimientos_credito_fecha_idx');
            // Un pedido consume una vez y se reversa una vez: un reintento de
            // red no duplica ni la deuda ni la devolución.
            $table->unique(['order_id', 'type'], 'movimiento_credito_pedido_unico');
        });

        Schema::table('settlements', function (Blueprint $table) {
            $table->decimal('credit_sales', 14, 2)->default(0)->after('platform_fee');
            // Negativo: lo que la tienda quedó debiendo en el corte anterior.
            $table->decimal('carried_in', 14, 2)->default(0)->after('credit_sales');
            $table->unsignedBigInteger('carried_from_id')->nullable()->after('carried_in');
            $table->foreign('carried_from_id')->references('id')->on('settlements')->nullOnDelete();
        });

        Schema::table('settlement_items', function (Blueprint $table) {
            $table->decimal('credit', 12, 2)->default(0)->after('discount');
        });

        DB::table('payment_methods')->insertOrIgnore([
            'methods_id' => 7,
            'name'       => 'Crédito de la tienda',
            'state'      => 1,
        ]);
    }

    public function down(): void
    {
        DB::table('payment_methods')->where('methods_id', 7)->delete();

        Schema::table('settlement_items', function (Blueprint $table) {
            $table->dropColumn('credit');
        });

        Schema::table('settlements', function (Blueprint $table) {
            $table->dropForeign(['carried_from_id']);
            $table->dropColumn(['credit_sales', 'carried_in', 'carried_from_id']);
        });

        Schema::dropIfExists('store_credit_movements');
        Schema::dropIfExists('store_credits');

        Schema::table('business', function (Blueprint $table) {
            $table->dropColumn('credit_debt_cap');
        });
    }
};
