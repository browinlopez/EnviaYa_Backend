<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Índice para `cash_deposits.settlement_id`.
 *
 * El corte de la tienda busca las entregas en mostrador que ya descontó por
 * esta columna, y al anular un corte las libera por ella. Sin índice, cada
 * corte recorre todas las consignaciones de la plataforma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_deposits', function (Blueprint $table) {
            $table->index('settlement_id', 'cash_deposits_corte_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cash_deposits', function (Blueprint $table) {
            $table->dropIndex('cash_deposits_corte_idx');
        });
    }
};
