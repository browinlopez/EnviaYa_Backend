<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * LA AUDITORÍA NO SE BORRA SOLA.
 *
 * `owen-it/laravel-auditing` guarda una fila por cada cambio de cada modelo
 * auditable, con el antes y el después en JSON. Medido en esta base: **1,7 kB
 * por fila**. A mil cambios al día son unos 600 MB al año, y crece más rápido
 * cuanto mejor va la plataforma.
 *
 * El paquete trae un `threshold` que limita cuántas auditorías guarda POR
 * REGISTRO, y no sirve para esto: un negocio que se edita a diario conservaría
 * sus últimas cincuenta y perdería el año pasado, mientras uno que no se toca
 * guardaría las suyas para siempre. Lo que hace falta es un horizonte de
 * tiempo, igual para todos.
 *
 * VEINTICUATRO MESES POR DEFECTO. Dos años cubre lo que se le pide a un
 * registro de auditoría —reconstruir quién cambió qué en el ejercicio anterior,
 * responder un reclamo, sostener una discusión con un aliado— sin arrastrar
 * indefinidamente el detalle de cada edición de precio de 2025.
 *
 * SE BORRA POR TANDAS. Un `DELETE` de medio millón de filas bloquea la tabla el
 * rato que tarde, y `audits` se escribe en cada petición que cambia algo: la
 * plataforma entera se quedaría esperando. De mil en mil no se nota.
 */
class PodarAuditoria extends Command
{
    protected $signature = 'auditoria:podar
        {--meses=24 : Cuánto se conserva}
        {--tanda=1000 : Cuántas filas por vuelta}
        {--seco : Dice cuántas borraría, sin borrar nada}';

    protected $description = 'Retira las auditorías anteriores al horizonte que se conserva';

    public function handle(): int
    {
        $meses = max(1, (int) $this->option('meses'));
        $tanda = max(100, (int) $this->option('tanda'));
        $corte = now()->subMonths($meses);

        $total = DB::table('audits')->where('created_at', '<', $corte)->count();

        $this->newLine();
        $this->line('  <fg=gray>Se conservan los últimos</>  ' . $meses . ' meses (desde ' . $corte->format('Y-m-d') . ')');
        $this->line('  <fg=gray>Auditorías en total</>       ' . number_format(DB::table('audits')->count(), 0, ',', '.'));
        $this->line('  <fg=gray>Anteriores al corte</>       ' . number_format($total, 0, ',', '.'));
        $this->newLine();

        if ($total === 0) {
            $this->info('  No hay nada que podar.');

            return self::SUCCESS;
        }

        if ($this->option('seco')) {
            $this->warn('  En seco: no se borró nada.');

            return self::SUCCESS;
        }

        $borradas = 0;

        do {
            /*
             * `limit` sobre un DELETE, y no un `whereIn` con mil claves: es una
             * consulta más simple y usa el índice de fecha directamente.
             */
            $n = DB::table('audits')
                ->where('created_at', '<', $corte)
                ->limit($tanda)
                ->delete();

            $borradas += $n;
        } while ($n > 0);

        $this->info('  Retiradas ' . number_format($borradas, 0, ',', '.') . ' auditorías.');

        return self::SUCCESS;
    }
}
