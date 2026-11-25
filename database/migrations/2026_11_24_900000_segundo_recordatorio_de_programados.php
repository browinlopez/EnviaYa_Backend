<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EL SEGUNDO RECORDATORIO DE UN PROGRAMADO, a los 15 minutos.
 *
 * `recordado_at` marca el de media hora. Este marca el de un cuarto de hora,
 * para que cada uno salga una sola vez aunque el programador corra cada
 * minuto. Ver `RecordatorioDeProgramados`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orderssales', function (Blueprint $t) {
            $t->timestamp('recordado_15_at')->nullable()->after('recordado_at');
        });
    }

    public function down(): void
    {
        Schema::table('orderssales', fn (Blueprint $t) => $t->dropColumn('recordado_15_at'));
    }
};
