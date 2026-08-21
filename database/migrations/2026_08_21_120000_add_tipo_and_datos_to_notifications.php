<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un aviso necesita saber DE QUÉ es, no solo qué dice.
 *
 * La tabla `notifications` existía con `message` y poco más, y sin usar: los
 * endpoints estaban comentados y no había modelo. Con solo un texto, la campana
 * puede enseñar la frase y nada más: no sabe qué icono poner ni a dónde llevar
 * al tocarla, que es justo lo que hace útil un aviso.
 *
 * `tipo` lo clasifica (`entrega_asignada`, `pedido_cancelado`…) y `datos` lleva
 * el contexto —el número del pedido, normalmente— para que tocar el aviso
 * abra lo que corresponde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications', 'tipo')) {
                $table->string('tipo', 40)->nullable()->after('user_id');
            }

            if (!Schema::hasColumn('notifications', 'datos')) {
                $table->json('datos')->nullable()->after('message');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            foreach (['tipo', 'datos'] as $columna) {
                if (Schema::hasColumn('notifications', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
