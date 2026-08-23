<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Manda un correo de prueba y dice qué falló, si falló.
 *
 * POR QUÉ EXISTE. El registro manda un correo de verificación y el login
 * rechaza a quien no lo verificó, así que un SMTP mal configurado no es una
 * molestia: es que nadie puede entrar. Y eso se descubría registrando a una
 * persona de verdad y esperando a que se quejara.
 *
 * Con esto se comprueba en diez segundos, antes de invitar a nadie:
 *
 *     php artisan correo:probar alguien@ejemplo.com
 *
 * En desarrollo `MAIL_MAILER=log` escribe el correo en el registro en vez de
 * enviarlo —que es lo correcto para no molestar a nadie mientras se trabaja—
 * así que este comando FUERZA el envío real. Es el único modo de saber si las
 * credenciales sirven.
 */
class ProbarCorreo extends Command
{
    protected $signature = 'correo:probar
                            {destino : A quién se le manda}
                            {--log : No enviar de verdad; escribirlo en el registro}';

    protected $description = 'Envía un correo de prueba para comprobar la configuración de SMTP';

    public function handle(): int
    {
        $destino = $this->argument('destino');

        if (!filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            $this->error("«{$destino}» no es un correo.");

            return self::FAILURE;
        }

        /*
         * Se fuerza `smtp` salvo que se pida lo contrario. Sin esto, en
         * desarrollo el comando diria «enviado» habiendolo escrito en un
         * archivo, que es justo la confusion que viene a resolver.
         */
        if (!$this->option('log')) {
            config(['mail.default' => 'smtp']);
        }

        $mailer = config('mail.default');
        $smtp = config('mail.mailers.smtp');

        $this->newLine();
        $this->line('  Vía         ' . ($mailer === 'smtp'
            /* En Laravel 11+ la clave es `scheme`; en las anteriores,
               `encryption`. Se prueba con las dos para que la linea no salga
               con un parentesis vacio segun la version. */
            ? sprintf('%s:%s (%s)', $smtp['host'], $smtp['port'],
                $smtp['scheme'] ?? $smtp['encryption'] ?? 'sin cifrado')
            : $mailer));
        $this->line('  Cuenta      ' . ($smtp['username'] ?: '(sin usuario)'));
        $this->line('  Remitente   ' . config('mail.from.address'));
        $this->line('  Destino     ' . $destino);
        $this->newLine();

        $inicio = microtime(true);

        try {
            Mail::raw($this->cuerpo(), function ($m) use ($destino) {
                $m->to($destino)->subject('Prueba de correo — VeciPa’Ya');
            });

            $this->info(sprintf('  Enviado en %.1f s.', microtime(true) - $inicio));

            if ($mailer === 'log') {
                $this->line('  Escrito en storage/logs, no enviado (--log).');
            } else {
                $this->line('  Revisa la bandeja Y el correo no deseado.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error(sprintf('  Falló tras %.1f s.', microtime(true) - $inicio));
            $this->newLine();

            $msg = $e->getMessage();

            // Algunos servidores devuelven la contraseña dentro del error.
            if (!empty($smtp['password'])) {
                $msg = str_replace($smtp['password'], '********', $msg);
            }

            $this->line('  ' . mb_substr($msg, 0, 400));
            $this->newLine();
            $this->line('  ' . $this->pista($msg));

            return self::FAILURE;
        }
    }

    /** Traduce el error del servidor a algo accionable. */
    private function pista(string $msg): string
    {
        if (str_contains($msg, '535') || stripos($msg, 'authenticat') !== false) {
            return 'La cuenta o la contraseña no sirven para ese servidor. '
                . 'Si es Gmail, hace falta una CLAVE DE APLICACIÓN, no la contraseña normal.';
        }

        if (stripos($msg, 'timed out') !== false || stripos($msg, 'connection refused') !== false) {
            return 'No se pudo abrir la conexión: el puerto está bloqueado o el servidor no responde. '
                . 'Con Hostinger, el 587 suele estar abierto cuando el 465 no.';
        }

        if (stripos($msg, 'certificate') !== false) {
            return 'El certificado no se pudo verificar: revisa que el cifrado (tls/ssl) '
                . 'corresponda al puerto.';
        }

        if (stripos($msg, 'sender') !== false || str_contains($msg, '553')) {
            return 'El servidor no acepta ese remitente. MAIL_FROM_ADDRESS tiene que ser '
                . 'una dirección que esa cuenta pueda usar.';
        }

        return 'Sin pista conocida para este error.';
    }

    private function cuerpo(): string
    {
        return "Prueba de envío de VeciPa’Ya.\n\n"
            . "Si estás leyendo esto, el correo del servidor funciona y el registro\n"
            . "de usuarios puede enviar su verificación.\n\n"
            . 'Enviado ' . now()->format('d/m/Y H:i:s') . ".\n";
    }
}
