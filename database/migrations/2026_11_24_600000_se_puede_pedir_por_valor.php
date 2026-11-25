<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «VECINO, ME DA $1.500 DE QUESO».
 *
 * Pedir por plata y no por medida es como se compra el queso costeño, el
 * aceite suelto o la panela partida. Pero no todo lo que se mide se vende
 * así: nadie pide «$2.000 de pechuga entera». La tienda decide, producto por
 * producto, si acepta que se lo pidan por valor.
 *
 * Nace apagado: ninguna tienda vende hoy al peso, y que aparezca un botón de
 * «pídelo por valor» sin que el tendero lo haya decidido sería un pedido que
 * él no sabe cómo despachar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products_business', function (Blueprint $t) {
            $t->boolean('por_valor')->default(false)->after('step_qty');
        });
    }

    public function down(): void
    {
        Schema::table('products_business', function (Blueprint $t) {
            $t->dropColumn('por_valor');
        });
    }
};
