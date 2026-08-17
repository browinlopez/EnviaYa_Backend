<?php

namespace App\Console\Commands;

use App\Mail\ResumenDiario;
use App\Models\User;
use App\Services\ResumenDeArea;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Manda a cada área lo que tiene pendiente.
 *
 * El panel sabía desde el principio que hay un SOAT vencido y una PQRS fuera de
 * plazo. Lo decía solo si alguien abría la pantalla, así que la información
 * existía y no llegaba. Esto la lleva.
 *
 *     php artisan resumen:diario                  # envía
 *     php artisan resumen:diario --seco            # solo muestra qué enviaría
 *     php artisan resumen:diario --area=sst        # una sola área
 *
 * `--seco` existe porque la primera vez que se corre esto en un servidor
 * conviene ver a quién le va a llegar ANTES de que le llegue.
 */
class EnviarResumenDiario extends Command
{
    protected $signature = 'resumen:diario
        {--seco : Muestra qué se enviaría, sin enviar nada}
        {--area= : Solo esta área, por su código}';

    protected $description = 'Envía a cada área los pendientes que le corresponden';

    public function handle(ResumenDeArea $resumen): int
    {
        $porArea = $resumen->paraTodas();

        if ($codigo = $this->option('area')) {
            $porArea = array_filter(
                $porArea,
                fn ($k) => $k === $codigo,
                ARRAY_FILTER_USE_KEY,
            );
        }

        if ($porArea === []) {
            $this->info('No hay nada pendiente en ninguna área. No se envía nada.');

            return self::SUCCESS;
        }

        $urlPanel = rtrim((string) config('app.panel_url', config('app.url')), '/');
        $seco = (bool) $this->option('seco');
        $enviados = 0;
        $sinGente = [];

        foreach ($porArea as $codigo => $datos) {
            /** @var \App\Models\Area $area */
            $area = $datos['area'];
            $asuntos = $datos['asuntos'];

            /*
             * A TODA el área, no solo a quien gestiona: un auxiliar que ve el
             * SOAT vencido puede avisarle a su jefe, y enterarse tarde por no
             * estar en la lista es peor que un correo de más.
             */
            $destinatarios = User::where('area_id', $area->id)
                ->where('rol', 4)
                ->where(fn ($q) => $q->where('state', 1)->orWhereNull('state'))
                ->whereNotNull('email')
                ->pluck('email')
                ->all();

            $urgentes = count(array_filter($asuntos, fn ($a) => $a['urgente']));

            $this->line(sprintf(
                '  %-14s %d asunto(s), %d urgente(s) → %d destinatario(s)',
                $codigo,
                count($asuntos),
                $urgentes,
                count($destinatarios),
            ));

            foreach ($asuntos as $a) {
                $this->line(sprintf(
                    '      %s %s (%d)',
                    $a['urgente'] ? '!' : '·',
                    $a['titulo'],
                    $a['cuantos'],
                ));
            }

            if ($destinatarios === []) {
                // Se avisa: un área con pendientes y sin nadie asignado es un
                // problema de configuración que si no se dice, no se ve.
                $sinGente[] = $codigo;
                continue;
            }

            if ($seco) {
                continue;
            }

            try {
                Mail::to($destinatarios)->send(
                    new ResumenDiario($area, $asuntos, $urlPanel)
                );
                $enviados++;
            } catch (\Throwable $e) {
                // Un área que falla no detiene a las demás: es preferible que
                // cinco reciban su resumen y una no, a que no lo reciba nadie.
                $this->error("      no se pudo enviar a {$codigo}: {$e->getMessage()}");
            }
        }

        $this->newLine();

        if ($seco) {
            $this->info('Prueba en seco: no se envió nada.');
        } else {
            $this->info("Enviados: {$enviados} de " . count($porArea) . ' área(s) con pendientes.');
        }

        if ($sinGente !== []) {
            $this->warn(
                'Con pendientes y sin nadie asignado: ' . implode(', ', $sinGente)
                . '. Nadie se va a enterar de esos asuntos.'
            );
        }

        return self::SUCCESS;
    }
}
