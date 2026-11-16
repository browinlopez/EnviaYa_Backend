<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\DatosDelEmisor;
use Illuminate\Console\Command;

/**
 * LO QUE ESTÁ MAL CONFIGURADO Y NO DA NINGÚN ERROR.
 *
 * Hay una clase de fallo que no se ve en ninguna prueba y no sale en ningún
 * registro: la variable de entorno que está puesta, con un valor plausible, y
 * que hace algo distinto de lo que quien la escribió creía. No falla al
 * desplegar; falla el día que alguien firma un acuerdo, paga un pedido o
 * intenta registrarse.
 *
 * Los cinco de acá se encontraron leyendo el `.env` de producción del
 * 16 de noviembre de 2026, no las pruebas. Las pruebas estaban todas en verde.
 *
 *     php artisan configuracion:comprobar
 *
 * NO COMPRUEBA NADA QUE VIVA FUERA DEL SERVIDOR. Si la clave de Google está
 * restringida por IP, si el alias de correo está verificado en Gmail, si el
 * bucket de respaldos es privado: eso se decide en la consola de cada
 * proveedor y desde acá no se ve. Lo que sí se ve es lo que el servidor cree,
 * y es donde estaban los cinco.
 *
 * `bold:comprobar` sigue aparte, porque hace más que leer configuración: con
 * `--simular` crea un pedido y se manda un aviso firmado. Este solo mira.
 */
class ComprobarLaConfiguracion extends Command
{
    protected $signature = 'configuracion:comprobar';

    protected $description = 'Dice qué está mal configurado sin dar ningún error: documentos, correo, medios y respaldos';

    /** Cuántos problemas se encontraron. Es el código de salida. */
    private int $problemas = 0;

    public function handle(): int
    {
        /*
         * Se pone a cero acá y no solo en la declaración: Artisan reutiliza la
         * instancia del comando mientras viva el proceso, así que dos llamadas
         * seguidas —una prueba, un `Artisan::call()` repetido— arrastrarían la
         * cuenta de la anterior y la segunda saldría con un número inventado.
         */
        $this->problemas = 0;

        $this->newLine();
        $this->line('  <options=bold>CONFIGURACIÓN QUE NO AVISA CUANDO ESTÁ MAL</>');
        $this->newLine();

        $this->losDocumentos();
        $this->elCorreo();
        $this->lasFotos();
        $this->losRespaldos();
        $this->elCobro();

        $this->newLine();

        if ($this->problemas === 0) {
            $this->info('  Nada que arreglar por acá.');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->warn("  {$this->problemas} cosa(s) por arreglar. Ninguna da error hoy: por eso están.");
        $this->newLine();

        /*
         * Devuelve 1 para que esto se pueda encadenar en un arranque o en un
         * trabajo del CI y falle. Un comando que informa y siempre sale con 0
         * solo sirve si alguien se acuerda de leerlo.
         */
        return self::FAILURE;
    }

    /** El acuerdo del domiciliario y el comprobante de entrega. */
    private function losDocumentos(): void
    {
        $this->titulo('Documentos que la plataforma firma');

        $faltan = DatosDelEmisor::faltantes();

        foreach (array_keys(DatosDelEmisor::OBLIGATORIOS) as $variable) {
            $falta = in_array($variable, $faltan, true);

            $this->fila(
                $variable,
                ! $falta,
                $falta
                    ? 'VACÍA — el documento sale sin esto y no se queja'
                    : 'puesta',
            );
        }

        if ($faltan !== []) {
            $this->detalle(
                'La plantilla envuelve estos datos en un `@if`, así que el PDF no se '
                .'rompe: la cláusula no se imprime y queda una raya para firmar a mano. '
                .'Un acuerdo sin el NIT ni el representante legal de quien lo firma es '
                .'más débil de lo que parece.',
            );
        }
    }

    /** Que los correos de verificación no caigan en la basura. */
    private function elCorreo(): void
    {
        $this->titulo('Correo');

        $remitente = (string) config('mail.from.address');
        $cuenta = (string) config('mail.mailers.smtp.username');
        $respuesta = (string) (config('mail.reply_to.address') ?? '');

        $dominioDe = static fn (string $correo): string => str_contains($correo, '@')
            ? strtolower(substr($correo, strpos($correo, '@') + 1))
            : '';

        $alineado = $cuenta === ''
            || $dominioDe($remitente) === $dominioDe($cuenta);

        $this->fila(
            'MAIL_FROM_ADDRESS',
            $alineado,
            $alineado
                ? $remitente.' — mismo dominio que la cuenta que envía'
                : $remitente.' enviado por '.$cuenta.' — dominios distintos',
        );

        if (! $alineado) {
            $this->fila(
                'MAIL_REPLY_TO_ADDRESS',
                $respuesta !== '',
                $respuesta !== '' ? $respuesta : 'sin poner',
            );

            $this->detalle(
                'Quien recibe comprueba el remitente contra quien entregó el correo: el '
                .'SPF que vale es el de '.$dominioDe($cuenta).' y la firma DKIM también. '
                .'Un remitente de otro dominio no queda amparado por ninguna de las dos, '
                .'y eso manda los correos de verificación a la carpeta de basura. Se '
                .'arregla alineando el remitente con la cuenta y poniendo '
                .'MAIL_REPLY_TO_ADDRESS, o verificando el alias en Gmail y añadiendo '
                .'include:_spf.google.com al SPF del dominio. Lo explica config/mail.php.',
            );
        }
    }

    /** Por dónde se sirven las fotos del catálogo. */
    private function lasFotos(): void
    {
        $this->titulo('Medios');

        $url = (string) config('filesystems.disks.r2.url');
        $esDeEnsayo = str_contains($url, '.r2.dev');

        $this->fila(
            'R2_PUBLIC_URL',
            $url !== '' && ! $esDeEnsayo,
            match (true) {
                $url === '' => 'sin poner — las fotos no se pueden enseñar',
                $esDeEnsayo => 'dominio r2.dev — limitado a propósito por Cloudflare',
                default => $url,
            },
        );

        if ($esDeEnsayo) {
            $this->detalle(
                'El dominio r2.dev lo limita Cloudflare a propósito y pide uno propio '
                .'para producción. Son TODAS las fotos del catálogo: cuando el límite '
                .'muerde, la app se queda con cuadros en blanco y no hay error en el '
                .'servidor que lo explique. Se ata un subdominio propio al bucket en '
                .'Cloudflare y se cambia esta variable; no hay que tocar código.',
            );
        }
    }

    /** Que la copia de la base no esté legible por quien tenga las llaves. */
    private function losRespaldos(): void
    {
        $this->titulo('Respaldos');

        $clave = (string) (config('backup.backup.password') ?? '');

        $this->fila(
            'BACKUP_ARCHIVE_PASSWORD',
            $clave !== '',
            $clave !== '' ? 'puesta' : 'VACÍA — la copia va sin cifrar',
        );

        if ($clave === '') {
            $this->detalle(
                'El volcado de la base entera se sube sin cifrar, y las llaves de R2 '
                .'viven en el mismo fichero de entorno que estas líneas. Por separado '
                .'son dos descuidos; juntos son la base de datos completa a un curl.',
            );
        }
    }

    /**
     * El entorno de Bold, que decide si el aviso de pago se puede falsificar.
     *
     * Lo comprueba `bold:comprobar` con mucho más detalle. Acá solo está
     * porque es la variable más caliente del fichero y quien corra esto tiene
     * que verla, aunque venga a mirar otra cosa.
     */
    private function elCobro(): void
    {
        $this->titulo('Cobro');

        $entorno = (string) config('services.bold.entorno');
        $enProduccion = app()->environment('production');
        $bien = ! $enProduccion || $entorno === 'produccion';

        $this->fila(
            'BOLD_ENTORNO',
            $bien,
            $bien
                ? $entorno
                : $entorno.' con APP_ENV=production — el aviso de pago acepta firma vacía',
        );

        if (! $bien) {
            $this->detalle(
                'En pruebas se admite una firma HMAC calculada con clave VACÍA, porque '
                .'así firma Bold en su entorno de ensayo. En producción eso significa '
                .'que quien conozca POST /v1/webhooks/bold puede marcar un pedido como '
                .'pagado sin pagarlo. Corre `php artisan bold:comprobar`.',
            );
        }
    }

    /* ================================================================== */

    private function titulo(string $que): void
    {
        $this->line("  <options=bold>{$que}</>");
    }

    private function fila(string $que, bool $bien, string $detalle): void
    {
        if (! $bien) {
            $this->problemas++;
        }

        $marca = $bien ? '<fg=green>OK   </>' : '<fg=red>FALTA</>';

        $this->line("  {$marca} ".str_pad($que, 26)." {$detalle}");
    }

    private function detalle(string $texto): void
    {
        foreach (explode("\n", wordwrap($texto, 72)) as $linea) {
            $this->line("         <fg=gray>{$linea}</>");
        }

        $this->newLine();
    }
}
