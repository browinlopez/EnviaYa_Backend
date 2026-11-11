<?php

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Config\Config as ConfigDeCopias;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\HealthyBackupWasFound;
use Spatie\Backup\Exceptions\NotificationCouldNotBeSent;
use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatus;

/**
 * LOS AVISOS DE LA COPIA DE SEGURIDAD
 *
 * `spatie/laravel-backup` estaba instalado y programado —copia a las 02:30,
 * limpieza a las 02:00, vigilancia a las 08:00— y los seis avisos encendidos.
 * El destinatario era `your@example.com`, el valor que trae el paquete.
 *
 * O sea que el aviso de que la copia FALLÓ se mandaba a una dirección que no
 * existe, desde `gerencia@vecipaya.com`. Una copia que deja de hacerse en
 * silencio es exactamente igual que una que funciona, hasta el día que hay que
 * restaurar; y encima cada fallo rebotaba contra el propio dominio, que es de
 * donde salen los correos de los pedidos.
 *
 * Lo que fijan estas pruebas no es el valor de una línea de configuración: es
 * que el camino entero —evento, oyente, notificable, ruta de correo— acaba en
 * una dirección de verdad, y que desactivar un aviso se hace vaciando sus
 * canales y no borrando su clave, porque borrarla tumba el comando.
 */

/** Spatie construye su Config una vez por petición (`scoped`). */
function recargarLaConfigDeCopias(): void
{
    app()->forgetInstance(ConfigDeCopias::class);
}

function destinoDePrueba(): BackupDestination
{
    Storage::fake('local');

    return BackupDestination::create('local', config('app.name'));
}

it('manda el aviso de que la copia fallo a una direccion que existe', function () {
    Notification::fake();

    event(new BackupHasFailed(new Exception('mysqldump se murio a medias')));

    Notification::assertSentTo(
        app(Notifiable::class),
        BackupHasFailedNotification::class,
        function ($aviso, array $canales) {
            expect($canales)->toContain('mail');

            return true;
        }
    );

    $destinatario = app(Notifiable::class)->routeNotificationForMail();

    expect($destinatario)
        ->toBeString()
        ->not->toContain('example.com')
        ->not->toStartWith('your@')
        ->toMatch('/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i');
});

it('no deja ninguna direccion de pega en la configuracion', function () {
    /*
     * La prueba de arriba habría pasado el día que alguien vuelva a publicar
     * la configuración del paquete con `vendor:publish --force`, porque
     * comprueba el valor RESUELTO y el `.env` de este entorno no define
     * `BACKUP_MAIL_TO`. Esta mira los archivos: el valor por defecto, que es
     * el que se usa cuando la variable falta, que es justo el caso en el que
     * nadie se enteraría.
     */
    $sospechosas = [];

    foreach (glob(config_path('*.php')) as $archivo) {
        /*
         * SIN LOS COMENTARIOS, y no es un detalle: la primera versión de esta
         * prueba leía el archivo entero y se puso roja contra el comentario de
         * `config/backup.php` que EXPLICA que el valor era `your@example.com`.
         * Un detector que no distingue el código de la prosa que lo documenta
         * castiga justo el haber dejado dicho lo que pasó.
         */
        $codigo = '';

        foreach (token_get_all(file_get_contents($archivo)) as $pieza) {
            if (is_array($pieza) && in_array($pieza[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $codigo .= is_array($pieza) ? $pieza[1] : $pieza;
        }

        foreach (['example.com', 'your@', 'changeme'] as $pega) {
            if (str_contains($codigo, $pega)) {
                $sospechosas[] = basename($archivo).' → '.$pega;
            }
        }
    }

    expect($sospechosas)->toBe([]);
});

it('avisa de los tres fallos por correo y del latido, y de nada mas', function () {
    $canales = config('backup.notifications.notifications');

    expect($canales[BackupHasFailedNotification::class])->toBe(['mail']);
    expect($canales[UnhealthyBackupWasFoundNotification::class])->toBe(['mail']);
    expect($canales[CleanupHasFailedNotification::class])->toBe(['mail']);

    // El latido: el único «todo bien» que se manda. Si el cron entero muere,
    // ningún aviso puede nacer dentro de la aplicación, y la única señal
    // posible es que alguien eche en falta el correo de cada mañana.
    expect($canales[BackupWasSuccessfulNotification::class])->toBe(['mail']);

    expect($canales[HealthyBackupWasFoundNotification::class])->toBe([]);
    expect($canales[CleanupWasSuccessfulNotification::class])->toBe([]);
});

it('desactiva un aviso vaciando sus canales, no borrando su clave', function () {
    Notification::fake();

    $estado = new BackupDestinationStatus(destinoDePrueba(), []);

    // Con la clave puesta y la lista vacía: el comando corre y no manda nada.
    event(new HealthyBackupWasFound($estado));

    Notification::assertNothingSent();
});

it('se cae si alguien BORRA la clave de un aviso en vez de vaciarla', function () {
    Notification::fake();

    /*
     * ESTE ES EL MOTIVO DEL COMENTARIO EN `config/backup.php`.
     *
     * `Notifications\EventHandler::subscribe()` se suscribe a los seis eventos
     * pase lo que pase, y `determineNotification()` lanza en cuanto el evento
     * no encuentra su clase en la lista. O sea que «limpiar» la configuración
     * borrando las dos líneas que no se usan no silencia dos avisos: tumba
     * `backup:monitor` precisamente cuando la copia está sana.
     */
    $sin = config('backup.notifications.notifications');
    unset($sin[HealthyBackupWasFoundNotification::class]);
    config(['backup.notifications.notifications' => $sin]);
    recargarLaConfigDeCopias();

    $estado = new BackupDestinationStatus(destinoDePrueba(), []);

    expect(fn () => event(new HealthyBackupWasFound($estado)))
        ->toThrow(NotificationCouldNotBeSent::class);
});

it('manda el latido cuando la copia sale bien', function () {
    Notification::fake();

    event(new BackupWasSuccessful(destinoDePrueba()));

    Notification::assertSentTo(
        app(Notifiable::class),
        BackupWasSuccessfulNotification::class
    );
});
