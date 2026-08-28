<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ¿ESTÁN A LA MISMA HORA PHP Y LA BASE DE DATOS?
 *
 * Es la pregunta que hay que responder ANTES de poner `DB_TIMEZONE`, y no se
 * puede responder desde el portátil: depende de cómo esté el servidor.
 *
 * POR QUÉ IMPORTA, Y HASTA DÓNDE. Las fechas de los registros —`created_at`,
 * `updated_at`— las escribe SIEMPRE la aplicación con el reloj de PHP: no hay
 * ninguna columna con `DEFAULT CURRENT_TIMESTAMP`, y es deliberado, porque en
 * un VPS ese reloj no es el de Bogotá.
 *
 * Lo que sí depende de la zona de la sesión es lo que calcula la base: un
 * `NOW()` en una consulta cruda, un `CURDATE()`, una comparación de fechas que
 * resuelva MySQL. Con el servidor en UTC, «hoy» para la base empieza cinco
 * horas antes que para la aplicación.
 *
 * Y POR QUÉ NO SE ARREGLA SOLO. MySQL guarda los `timestamp` en UTC y los
 * convierte a la zona de la sesión al leerlos. Fijar la sesión a `-05:00` en
 * una base que venía funcionando en UTC hace que TODO lo ya guardado se lea
 * cinco horas corrido. Por eso `DB_TIMEZONE` viene vacío: se pone a
 * conciencia, mirando esto primero.
 */
class ZonaHorariaDeLaBase extends Command
{
    protected $signature = 'db:zona-horaria';

    protected $description = 'Compara la hora de PHP con la de la base y dice si es seguro fijar DB_TIMEZONE';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->warn('Esto solo tiene sentido en MySQL. La conexión es ' . DB::getDriverName() . '.');

            return self::SUCCESS;
        }

        $sql = DB::selectOne(
            'SELECT NOW() ahora, @@system_time_zone sistema, @@session.time_zone sesion, @@global.time_zone global'
        );

        $php   = Carbon::now();
        $base  = Carbon::parse($sql->ahora);
        // En segundos y sin signo: lo que importa es si coinciden, no cuál va
        // por delante.
        $desfase = abs($php->getTimestamp() - $base->getTimestamp());

        $this->newLine();
        $this->line('  <fg=gray>Hora de PHP</>          ' . $php->format('Y-m-d H:i:s') . '  (' . config('app.timezone') . ')');
        $this->line('  <fg=gray>Hora de MySQL</>        ' . $base->format('Y-m-d H:i:s'));
        $this->line('  <fg=gray>Zona del sistema</>     ' . $sql->sistema);
        $this->line('  <fg=gray>Zona de la sesión</>    ' . $sql->sesion . '   (DB_TIMEZONE=' . (env('DB_TIMEZONE') ?: 'sin poner') . ')');
        $this->newLine();

        // Un minuto de margen: los relojes de dos procesos nunca son idénticos
        // al segundo, y eso no es un problema de zona horaria.
        if ($desfase < 60) {
            $this->info('  Las dos horas coinciden. No hay dos relojes.');
            $this->newLine();

            if (!env('DB_TIMEZONE')) {
                $this->line('  <fg=gray>Puedes fijar `DB_TIMEZONE=-05:00` sin mover nada de lo ya guardado:</>');
                $this->line('  <fg=gray>coinciden hoy por cómo está el servidor, y con la variable puesta</>');
                $this->line('  <fg=gray>seguirán coincidiendo aunque alguien cambie la hora del servidor.</>');
            }

            return self::SUCCESS;
        }

        $horas = round($desfase / 3600, 1);

        $this->error("  Hay {$horas} horas de diferencia entre PHP y la base.");
        $this->newLine();
        $this->line('  Las fechas de los registros están bien: las escribe la aplicación.');
        $this->line('  Lo que no coincide es lo que CALCULA la base — `NOW()`, `CURDATE()`,');
        $this->line('  y cualquier comparación de fechas que resuelva MySQL.');
        $this->newLine();
        $this->warn('  NO pongas `DB_TIMEZONE` sin más: los `timestamp` ya guardados');
        $this->warn("  se leerían {$horas} horas corridos.");
        $this->newLine();
        $this->line('  Lo correcto es alinear el SERVIDOR, que no reinterpreta nada:');
        $this->line('    · en el contenedor de MySQL:  TZ=America/Bogota');
        $this->line("    · o en la base:               SET GLOBAL time_zone = '-05:00'");
        $this->newLine();
        $this->line('  Y cuando las dos horas coincidan, entonces sí `DB_TIMEZONE=-05:00`');
        $this->line('  para que no vuelvan a separarse.');

        return self::FAILURE;
    }
}
