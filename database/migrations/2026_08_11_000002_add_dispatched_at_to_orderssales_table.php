<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Momento en que la tienda despachó la orden (transición 2 → 3).
     * El cronómetro de entrega de la app se ancla a esta hora del servidor:
     * corre igual para todos y aunque la app esté cerrada.
     */
    public function up(): void
    {
        if (Schema::hasColumn('orderssales', 'dispatched_at')) {
            return;
        }

        Schema::table('orderssales', function (Blueprint $table) {
            $table->timestamp('dispatched_at')->nullable()->after('delivery_date');
        });
    }

    public function down(): void
    {
        Schema::table('orderssales', function (Blueprint $table) {
            $table->dropColumn('dispatched_at');
        });
    }
};
