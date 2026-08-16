<?php

namespace Database\Seeders;

use App\Support\AreasSemilla;
use Illuminate\Database\Seeder;

/**
 * Reimpone el reparto de módulos de fábrica sobre las siete áreas.
 *
 * La instalación la hace la migración, que respeta lo que ya esté ajustado.
 * Este seeder es lo contrario y a propósito: REESCRIBE la matriz de las áreas
 * de fábrica con lo declarado en AreasSemilla.
 *
 *     php artisan db:seed --class=AreaSeeder
 *
 * Se usa después de agregar un módulo al catálogo, para que las áreas lo
 * reciban según el criterio declarado en el código. Quien lo ejecuta quiere
 * justamente eso, así que no se le pide confirmación — pero conviene saber que
 * los ajustes hechos a mano desde el panel sobre esas siete áreas se pierden.
 * Las áreas creadas desde el panel no se tocan nunca.
 */
class AreaSeeder extends Seeder
{
    public function run(): void
    {
        AreasSemilla::sembrar(forzar: true);

        $this->command?->info(
            'Áreas resincronizadas con el reparto de fábrica. '
            . 'Las áreas creadas desde el panel no se modificaron.',
        );
    }
}
