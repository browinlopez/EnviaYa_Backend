<?php

namespace App\Console\Commands;

use App\Services\LiquidacionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * El corte de cada día, en borrador.
 *
 * Los periodos eran libres: alguien escribía dos fechas en el panel y se
 * generaba. Eso funciona mientras hay poco movimiento, pero con el efectivo de
 * por medio deja de servir — si nadie lanza el corte, nadie sabe cuánto debe
 * cada domiciliario, y el saldo se acumula en silencio.
 *
 * Se genera en BORRADOR y no aprobada a propósito: un error de cálculo
 * aprobado solo se descubre cuando el dinero ya salió. Alguien lo revisa y lo
 * aprueba desde el panel.
 *
 * Es idempotente por construcción: `LiquidacionService` descarta los pedidos
 * que ya estén en otro corte vivo, así que correrlo dos veces el mismo día no
 * duplica nada. Y si un destinatario no tiene pedidos, el servicio lanza y acá
 * simplemente se salta: no es un fallo, es un día sin ventas.
 */
class LiquidarElDia extends Command
{
    protected $signature = 'liquidaciones:diarias
        {--fecha= : El día a liquidar (Y-m-d). Por defecto, ayer}
        {--tipo=  : Solo business o solo domiciliary. Por defecto, los dos}';

    protected $description = 'Genera en borrador las liquidaciones del día anterior';

    public function handle(LiquidacionService $liquidaciones): int
    {
        $fecha = $this->option('fecha')
            ? Carbon::parse($this->option('fecha'))->toDateString()
            /*
             * Ayer, no hoy: se corre de madrugada y el día de hoy todavía está
             * abierto. Liquidar un día a medias obligaría a un segundo corte
             * por los pedidos de la tarde.
             */
            : Carbon::yesterday()->toDateString();

        $tipos = $this->option('tipo')
            ? [$this->option('tipo')]
            : ['business', 'domiciliary'];

        foreach ($tipos as $tipo) {
            if (!in_array($tipo, ['business', 'domiciliary'], true)) {
                $this->error("Tipo no válido: {$tipo}");

                return self::FAILURE;
            }
        }

        $this->info("Liquidando {$fecha}…");

        $generadas = 0;
        $saltadas  = 0;

        foreach ($tipos as $tipo) {
            $columna = $tipo === 'business' ? 'busines_id' : 'domiciliary_id';

            $destinatarios = DB::table('orderssales')
                ->whereDate('sale_date', $fecha)
                ->where('state', 4)
                ->whereNotNull($columna)
                ->distinct()
                ->pluck($columna);

            foreach ($destinatarios as $id) {
                try {
                    $liq = $liquidaciones->generar($tipo, (int) $id, $fecha, $fecha);
                    $generadas++;

                    $this->line(sprintf(
                        '  %-12s #%-4s %s pedidos · neto %s',
                        $tipo,
                        $id,
                        $liq->orders_count,
                        number_format((float) $liq->net_payable, 0, ',', '.'),
                    ));
                } catch (RuntimeException $e) {
                    // Ya liquidado, o sin pedidos que liquidar. Ninguna de las
                    // dos es un fallo del corte.
                    $saltadas++;
                }
            }
        }

        $this->newLine();
        $this->info("{$generadas} liquidaciones en borrador · {$saltadas} sin nada que liquidar.");

        Log::info('Liquidaciones diarias generadas', [
            'fecha' => $fecha, 'generadas' => $generadas, 'saltadas' => $saltadas,
        ]);

        return self::SUCCESS;
    }
}
