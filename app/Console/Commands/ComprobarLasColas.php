<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * ¿SIGUE HABIENDO QUIEN SAQUE LOS TRABAJOS DE LA COLA?
 *
 * EL AGUJERO QUE ESTO CIERRA, y salió al recorrer el sistema entero:
 * **noventa y cuatro trabajos llevaban en la cola desde el 8 de septiembre**,
 * con cero intentos. Ochenta avisos push, siete trazados de ruta y siete
 * cargas de catálogo. Se drenaron a mano y los noventa y cuatro pasaron sin un
 * solo fallo, así que el código estaba bien: lo que no existía era **manera de
 * enterarse**.
 *
 * Y es la clase de avería que no se nota desde fuera. `QUEUE_CONNECTION` es
 * `database`, así que encolar siempre funciona —la petición del comprador
 * acaba en 201 igual— y lo que deja de pasar es lo de después: el tendero no
 * recibe el aviso del pedido nuevo, el mapa no traza la ruta y el catálogo que
 * subió se queda quieto. Tres síntomas en tres pantallas distintas, ninguno
 * apuntando a un trabajador muerto.
 *
 * MIRA TRES COSAS, y la del medio es la que de verdad importa:
 *
 *   · cuántos trabajos esperan — un número alto puede ser un rato de tráfico;
 *   · **la edad del más viejo** — esto no tiene explicación inocente: si el
 *     primero de la fila lleva horas, nadie está atendiendo la fila;
 *   · cuántos fallaron — un trabajo que se agotó los reintentos no vuelve
 *     solo, y `failed_jobs` es un sitio donde nadie mira por costumbre.
 *
 * AVISA POR CORREO, que es el punto. Un comando que solo imprime en el cron no
 * vigila nada: la salida se va a `/dev/null` y nadie la lee. Y un aviso que
 * nazca DENTRO de la aplicación no puede informar de que la aplicación no
 * corre, así que lo que esto cubre es lo que sí puede cubrir: el planificador
 * vive y los trabajadores no. Que el planificador entero muera lo delata el
 * latido diario de la copia de seguridad, por ausencia.
 */
class ComprobarLasColas extends Command
{
    protected $signature = 'colas:comprobar
        {--avisar : Manda un correo si algo esta mal}';

    protected $description = 'Comprueba que los trabajos en cola se esten procesando';

    public function handle(): int
    {
        $esperando = DB::table('jobs')->count();
        $fallidos = DB::table('failed_jobs')->count();
        $masViejo = DB::table('jobs')->min('created_at');

        /*
         * `created_at` de la tabla `jobs` es un entero de Unix, no una fecha:
         * compararlo con `now()` de Eloquent daría cualquier cosa.
         */
        $minutos = $masViejo === null
            ? 0
            : (int) round((time() - (int) $masViejo) / 60);

        $topeMinutos = (int) config('rendimiento.colas.minutos_de_espera');
        $topeEsperando = (int) config('rendimiento.colas.maximo_esperando');

        $this->line('');
        $this->line('  COLAS');
        $this->line('  '.str_repeat('-', 46));
        $this->line(sprintf('  %-26s %s', 'esperando', $esperando));
        $this->line(sprintf('  %-26s %s', 'fallidos', $fallidos));
        $this->line(sprintf('  %-26s %s', 'el mas viejo lleva', $minutos.' min'));
        $this->line('');

        $motivos = [];

        if ($minutos > $topeMinutos) {
            $motivos[] = sprintf(
                'El trabajo mas viejo de la cola lleva %d minutos esperando (el tope es '
                .'%d). Eso no tiene explicacion inocente: significa que nadie esta '
                .'sacando trabajos. Revisar que el proceso `queue:work` siga vivo.',
                $minutos, $topeMinutos
            );
        }

        if ($esperando > $topeEsperando) {
            $motivos[] = sprintf(
                'Hay %d trabajos esperando (el tope es %d). Puede ser un rato de '
                .'trafico, o un trabajador que no da abasto.',
                $esperando, $topeEsperando
            );
        }

        if ($fallidos > 0) {
            $motivos[] = sprintf(
                'Hay %d trabajos en `failed_jobs`. Un trabajo que agoto sus reintentos '
                .'no vuelve solo: se revisan con `queue:failed` y se reintentan con '
                .'`queue:retry`.',
                $fallidos
            );
        }

        if ($motivos === []) {
            $this->info('  Las colas van al dia.');

            return self::SUCCESS;
        }

        foreach ($motivos as $m) {
            $this->warn('  '.$m);
        }

        Log::warning('Las colas no van al dia', [
            'esperando' => $esperando,
            'fallidos' => $fallidos,
            'minutos_del_mas_viejo' => $minutos,
        ]);

        if ($this->option('avisar')) {
            $this->avisar($motivos, $esperando, $fallidos, $minutos);
        }

        return self::FAILURE;
    }

    /** @param  list<string>  $motivos */
    private function avisar(array $motivos, int $esperando, int $fallidos, int $minutos): void
    {
        /*
         * AL MISMO SITIO QUE LOS AVISOS DE LA COPIA, a propósito: es el buzón
         * que ya se eligió para «algo de la operación se rompió», y tener dos
         * destinos para lo mismo acaba con uno de los dos sin leer. Ese valor
         * ya está arreglado —traía `your@example.com` del paquete— y hay una
         * prueba que impide que vuelva a ser una dirección de pega.
         */
        $destino = (string) config('backup.notifications.mail.to');

        $cuerpo = "Los trabajos en cola no se estan procesando como deberian.\n\n"
            ."  esperando ............ {$esperando}\n"
            ."  fallidos ............. {$fallidos}\n"
            ."  el mas viejo lleva ... {$minutos} min\n\n"
            .implode("\n\n", $motivos)."\n\n"
            ."Lo que deja de pasar mientras esto siga asi: el tendero no recibe el\n"
            ."aviso de un pedido nuevo, el mapa no traza rutas y las cargas de\n"
            ."catalogo se quedan quietas. Nada de eso da error en pantalla.\n";

        try {
            Mail::raw($cuerpo, function ($m) use ($destino) {
                $m->to($destino)->subject(
                    '['.config('app.name').'] Las colas no van al dia'
                );
            });

            $this->line('  Aviso enviado a '.$destino);
        } catch (\Throwable $e) {
            // Que no se pueda avisar no puede tapar el diagnostico: el estado
            // de las colas ya quedo en el registro y en la salida del comando.
            Log::error('No se pudo avisar del estado de las colas', [
                'error' => $e->getMessage(),
            ]);
            $this->error('  No se pudo enviar el aviso: '.$e->getMessage());
        }
    }
}
