<?php

namespace App\Console\Commands;

use App\Services\FacturaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Emite el comprobante de las entregas que se hicieron antes de que esto
 * existiera, y de las que se le escaparon al disparador.
 *
 *     php artisan facturas:emitir              # todo lo pendiente
 *     php artisan facturas:emitir --limite=50  # de a poco
 *
 * Hace falta por dos razones distintas:
 *
 * · La histórica: hay entregas anteriores al módulo y sin comprobante quedan
 *   como un hueco en el registro.
 *
 * · La permanente: la emisión al entregar está dentro de un try —una entrega no
 *   puede fallar por un problema de papeleo—, así que si alguna vez falla, esto
 *   es lo que la recupera. Va programado a diario para que ese hueco no dure más
 *   de un día.
 */
class EmitirFacturasPendientes extends Command
{
    protected $signature = 'facturas:emitir
        {--limite=0 : Cuántas emitir como máximo (0 = todas)}';

    protected $description = 'Emite el comprobante de los pedidos entregados que no lo tienen';

    public function handle(FacturaService $facturas): int
    {
        $q = DB::table('orderssales as o')
            ->where('o.state', 4)
            ->whereNotExists(fn ($sub) => $sub
                ->from('invoices as i')
                ->whereColumn('i.orderSales_id', 'o.orderSales_id'))
            // Del más viejo al más nuevo: así el consecutivo sigue el orden
            // real de las entregas y no el orden en que se corrió el comando.
            ->orderBy('o.delivery_date')
            ->orderBy('o.orderSales_id');

        $limite = (int) $this->option('limite');
        $total = (clone $q)->count();

        if ($total === 0) {
            $this->info('Todas las entregas tienen su comprobante.');

            return self::SUCCESS;
        }

        if ($limite > 0) {
            $q->limit($limite);
        }

        $pedidos = $q->pluck('o.orderSales_id');

        $this->info("Pendientes: {$total}. Se emiten {$pedidos->count()}.");
        $barra = $this->output->createProgressBar($pedidos->count());
        $barra->start();

        $emitidas = 0;
        $fallos = [];

        foreach ($pedidos as $id) {
            try {
                if ($facturas->emitirPara((int) $id)) {
                    $emitidas++;
                }
            } catch (\Throwable $e) {
                // Un pedido que falla no detiene a los demás: es mejor emitir
                // 84 de 85 y decir cuál falló, que no emitir ninguno.
                $fallos[] = "#{$id}: " . $e->getMessage();
            }

            $barra->advance();
        }

        $barra->finish();
        $this->newLine(2);
        $this->info("Emitidas: {$emitidas}.");

        if ($fallos !== []) {
            $this->warn('No se pudieron emitir ' . count($fallos) . ':');
            foreach (array_slice($fallos, 0, 10) as $f) {
                $this->line("  · {$f}");
            }
        }

        return self::SUCCESS;
    }
}
