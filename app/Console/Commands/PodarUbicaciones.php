<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * LAS UBICACIONES DE LOS REPARTOS SE BORRAN A LOS NOVENTA DÍAS.
 *
 * ESTE COMANDO EXISTE PORQUE EL ACUERDO LO PROMETE. La cláusula décima segunda
 * del acuerdo de vinculación —la que el domiciliario firma— declara un plazo
 * de conservación, y la Ley 1581 obliga a suprimir los datos cuando termina la
 * finalidad que los justificó. Sin esto, el contrato diría una cosa y la base
 * guardaría la otra para siempre: es la clase de desajuste que convierte un
 * documento de respaldo en una prueba en contra.
 *
 * NOVENTA DÍAS cubre el plazo en que aparece un reclamo por una entrega —«no
 * me llegó», «llegó frío», un incidente de seguridad— y deja de guardar por
 * dónde anduvo una persona cuando ya nadie va a preguntarlo.
 *
 * SE BORRA POR TANDAS, como la auditoría: un `DELETE` de muchas filas bloquea
 * la tabla el rato que tarde, y `order_geolocation` se escribe mientras hay
 * repartos en la calle.
 *
 * Hoy la tabla tiene trece filas y esto no borra nada. Va igual, y va ahora:
 * con el reporte durante todo el turno pasa a escribirse una fila por minuto
 * por domiciliario en reparto, y el día que importe nadie se va a acordar de
 * que el acuerdo prometía un plazo.
 */
class PodarUbicaciones extends Command
{
    protected $signature = 'ubicaciones:podar
        {--dias=90 : Cuánto se conserva}
        {--tanda=1000 : Cuántas filas por vuelta}
        {--seco : Dice cuántas borraría, sin borrar nada}';

    protected $description = 'Retira las posiciones de reparto anteriores al plazo que declara el acuerdo';

    public function handle(): int
    {
        $dias  = max(1, (int) $this->option('dias'));
        $tanda = max(100, (int) $this->option('tanda'));
        $corte = now()->subDays($dias);

        $total = DB::table('order_geolocation')->where('created_at', '<', $corte)->count();

        $this->newLine();
        $this->line('  <fg=gray>Se conservan los últimos</>  ' . $dias . ' días (desde ' . $corte->format('Y-m-d') . ')');
        $this->line('  <fg=gray>Posiciones en total</>       ' . number_format(DB::table('order_geolocation')->count(), 0, ',', '.'));
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
            $n = DB::table('order_geolocation')
                ->where('created_at', '<', $corte)
                ->limit($tanda)
                ->delete();

            $borradas += $n;
        } while ($n > 0);

        $this->info('  Retiradas ' . number_format($borradas, 0, ',', '.') . ' posiciones.');

        return self::SUCCESS;
    }
}
