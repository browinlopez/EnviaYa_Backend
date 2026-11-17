<?php

use App\Models\Invoice\Invoice;
use App\Services\ContratoService;
use App\Services\FacturaService;
use App\Support\DatosDelEmisor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CONFIGURACIÓN QUE ESTÁ MAL Y NO DA NINGÚN ERROR
 *
 * Todo lo de este fichero se encontró leyendo el `.env` de producción, no
 * corriendo pruebas: las 1421 estaban en verde mientras los acuerdos salían
 * sin NIT y los correos de verificación caían en la basura.
 *
 * Por eso casi todas estas pruebas afirman que algo SE DICE. Y una prueba que
 * afirma que aparece un aviso es exactamente igual de ciega que el problema
 * que persigue si el aviso se deja de emitir por otro motivo —un `if` que
 * cambia, un nombre de variable que ya no se lee—, así que cada una lleva su
 * PASADA DE CONTROL: el mismo camino con la configuración completa, donde el
 * aviso NO tiene que salir.
 */
beforeEach(function () {
    /*
     * El emisor COMPLETO es el punto de partida de cada prueba, y no lo que
     * hay en el entorno de quien la corra. Con los valores de producción
     * —vacíos— la pasada de control pasaría sin comprobar nada.
     */
    config()->set('services.contrato.nit', '901234567-8');
    config()->set('services.contrato.representante', 'Quien Firma Perez');
    config()->set('services.contrato.representante_cc', '1234567890');
});

/** Un domiciliario de mentira: `comoTexto()` solo lee nombre y documento. */
function alguienQueFirma(): object
{
    return (object) [
        'name' => 'Repartidor De Prueba',
        'document' => '1098765432',
        'user_id' => 1,
        'domiciliary_id' => 1,
    ];
}

/* ====================================================================== */
/* La regla: qué falta */
/* ====================================================================== */

it('dice QUE variable falta, no solo que falta algo', function () {
    /*
     * Devuelve nombres y no un booleano a propósito: «la configuración está
     * incompleta» no se puede arreglar, «falta CONTRATO_NIT» sí. Quien lee
     * esto es una persona delante de un panel de despliegue.
     */
    expect(DatosDelEmisor::faltantes())->toBe([])
        ->and(DatosDelEmisor::completos())->toBeTrue();

    config()->set('services.contrato.nit', '');
    config()->set('services.contrato.representante_cc', '   ');

    expect(DatosDelEmisor::faltantes())
        ->toBe(['CONTRATO_NIT', 'CONTRATO_REPRESENTANTE_CC'])
        ->and(DatosDelEmisor::completos())->toBeFalse();
});

it('un espacio en blanco no cuenta como puesto', function () {
    /*
     * POR QUÉ ESTA PRUEBA. Es el fallo que se cuela al pegar valores en un
     * panel de despliegue: `CONTRATO_NIT=" "` se ve puesto en la lista de
     * variables y llega vacío al papel. Un `!== ''` a secas lo daría por
     * bueno.
     */
    config()->set('services.contrato.representante', "\t \n");

    expect(DatosDelEmisor::faltantes())->toContain('CONTRATO_REPRESENTANTE');
});

/* ====================================================================== */
/* Los documentos */
/* ====================================================================== */

it('un acuerdo sin el NIT de quien lo firma deja rastro en el registro', function () {
    // PASADA DE CONTROL: completo, no hay nada que avisar.
    Log::spy();
    app(ContratoService::class)->comoTexto(alguienQueFirma());
    Log::shouldNotHaveReceived('warning');

    config()->set('services.contrato.nit', '');

    Log::spy();
    app(ContratoService::class)->comoTexto(alguienQueFirma());

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $mensaje, array $contexto) => str_contains($mensaje, 'datos del emisor incompletos')
            && $contexto['faltan'] === ['CONTRATO_NIT (NIT de la empresa que firma)']
            && $contexto['documento'] === '1098765432',
    )->once();
});

it('el acuerdo se sigue emitiendo: avisar no es bloquear', function () {
    /*
     * POR QUÉ ESTA PRUEBA. La tentación con un dato obligatorio que falta es
     * abortar, y acá sería un error: dejaría a la tienda sin poder dar de alta
     * a un repartidor por una variable de entorno. Se cambia un hueco en un
     * papel por una puerta cerrada.
     */
    config()->set('services.contrato.nit', '');
    config()->set('services.contrato.representante', '');
    config()->set('services.contrato.representante_cc', '');

    $bloques = app(ContratoService::class)->comoTexto(alguienQueFirma());

    expect($bloques)->not->toBeEmpty();
});

it('el comprobante avisa del NIT y NO del representante, que no sale en el papel', function () {
    /*
     * POR QUÉ ESTA PRUEBA. El comprobante imprime el NIT pero no el
     * representante legal. Avisar de los tres en cada comprobante sería ruido
     * en el registro sobre algo que ese documento no enseña, y el ruido es lo
     * que hace que nadie lea los avisos.
     */
    /*
     * Se inserta a mano y no con `Invoice::create()` porque `invoice_id` no
     * está en `$fillable` y la tabla no es AUTO_INCREMENT en MySQL —lo dice su
     * propia migración—. Con `create()` la prueba pasaría en SQLite, donde una
     * clave INTEGER sí se numera sola, y fallaría en el motor de producción.
     */
    DB::table('invoices')->insert([
        'invoice_id' => 1,
        'invoice_number' => 'PRUEBA-1',
        'invoice_date' => now(),
        'subtotal' => 10000,
        'total' => 12000,
        'snapshot' => '[]',
    ]);

    $factura = Invoice::query()->findOrFail(1);

    // PASADA DE CONTROL: con el NIT puesto no se avisa, aunque falte el resto.
    config()->set('services.contrato.representante', '');
    config()->set('services.contrato.representante_cc', '');

    Log::spy();
    app(FacturaService::class)->pdf($factura);
    Log::shouldNotHaveReceived('warning');

    config()->set('services.contrato.nit', '');

    Log::spy();
    app(FacturaService::class)->pdf($factura);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $mensaje, array $contexto) => str_contains($mensaje, 'sin el NIT')
            && $contexto['faltan'] === ['CONTRATO_NIT (NIT de la empresa que firma)'],
    )->once();
});

/* ====================================================================== */
/* El correo */
/* ====================================================================== */

it('el remitente del esqueleto se nombra y el de verdad no', function () {
    configuracionSana();
    config()->set('mail.from.address', 'hello@example.com');

    $this->artisan('configuracion:comprobar')
        ->expectsOutputToContain('esqueleto de Laravel')
        ->assertExitCode(1);
});

/* ====================================================================== */
/* El comando */
/* ====================================================================== */

it('configuracion:comprobar falla y nombra cada cosa que esta mal', function () {
    /*
     * Los cuatro que tenía producción el 16 de noviembre de 2026, tal cual.
     */
    configuracionSana();
    config()->set('services.contrato.nit', '');
    config()->set('filesystems.disks.r2.url', 'https://pub-algo.r2.dev');
    config()->set('backup.backup.password', null);
    config()->set('mail.from.address', 'hello@example.com');

    $this->artisan('configuracion:comprobar')
        ->expectsOutputToContain('CONTRATO_NIT')
        ->expectsOutputToContain('R2_PUBLIC_URL')
        ->expectsOutputToContain('BACKUP_ARCHIVE_PASSWORD')
        ->expectsOutputToContain('MAIL_FROM_ADDRESS')
        ->assertExitCode(1);
});

it('configuracion:comprobar pasa cuando todo esta en su sitio', function () {
    /*
     * LA PASADA DE CONTROL DEL COMANDO, y sin ella el comando no vale nada:
     * uno que devuelve 1 siempre es tan inútil como uno que devuelve 0
     * siempre. Esto fija que los cinco criterios se pueden satisfacer.
     */
    configuracionSana();

    $this->artisan('configuracion:comprobar')
        ->expectsOutputToContain('Nada que arreglar')
        ->assertExitCode(0);
});

/**
 * Todo en su sitio menos lo que cada prueba estropee a propósito.
 *
 * Va aparte del `beforeEach` porque no todas las pruebas de este fichero
 * corren el comando, y porque así cada una dice en una línea cuál es el único
 * criterio que está tocando.
 */
function configuracionSana(): void
{
    config()->set('filesystems.disks.r2.url', 'https://medios.vecipaya.com');
    config()->set('backup.backup.password', 'una-clave-de-verdad');
    config()->set('mail.from.address', 'gerencia@vecipaya.com');
    config()->set('services.bold.entorno', 'produccion');
}

it('en un entorno que no es production, BOLD_ENTORNO=prueba esta bien', function () {
    /*
     * PASADA DE CONTROL de la que viene detrás, y no es un adorno:
     * `BOLD_ENTORNO=prueba` es lo CORRECTO en local y en el entorno de ensayo
     * —ahí es donde se ensaya—. Si el comando lo marcara siempre, el aviso se
     * volvería ruido que todo el mundo aprende a ignorar, justo el que importa
     * el día que aparece en producción.
     *
     * Las dos mitades están en pruebas separadas porque el ayudante de Artisan
     * solo captura la salida de la PRIMERA orden de cada prueba: con las dos
     * juntas, la segunda comprobaba el código de salida y se tragaba el texto.
     */
    configuracionSana();
    config()->set('services.bold.entorno', 'prueba');

    expect(app()->environment('production'))->toBeFalse();

    $this->artisan('configuracion:comprobar')
        ->expectsOutputToContain('Nada que arreglar')
        ->assertExitCode(0);
});

it('en production, BOLD_ENTORNO=prueba se nombra y dice por que', function () {
    configuracionSana();
    config()->set('services.bold.entorno', 'prueba');

    app()->detectEnvironment(fn () => 'production');

    /*
     * LAS DOS AFIRMACIONES VAN SOBRE LÍNEAS DISTINTAS A PROPÓSITO.
     *
     * `expectsOutputToContain` registra una expectativa de Mockery por cada
     * texto (`PendingCommand::createABufferedOutputMock`), y cada línea que
     * imprime el comando es UNA llamada a `doWrite`. Mockery da la llamada a
     * la primera expectativa cuyo filtro coincide y ahí se queda, así que dos
     * textos de la MISMA línea no pueden coincidir los dos: el segundo falla
     * aunque esté en la pantalla. Comprobado volcando la salida: el comando
     * devuelve 1 y la línea dice las dos cosas.
     *
     * Así que se afirma el renglón por un lado y la explicación por otro.
     */
    $this->artisan('configuracion:comprobar')
        ->expectsOutputToContain('BOLD_ENTORNO')
        ->expectsOutputToContain('webhooks/bold')
        ->assertExitCode(1);
});

/**
 * Los sitios de un árbol de ficheros que miran el disco local del contenedor.
 *
 * @return list<string>
 */
function mirasAlDiscoDelContenedor(string $carpeta): array
{
    $sitios = [];

    /** @var SplFileInfo $fichero */
    foreach (
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($carpeta, FilesystemIterator::SKIP_DOTS)
        ) as $fichero
    ) {
        if ($fichero->getExtension() !== 'php') {
            continue;
        }

        foreach (file($fichero->getPathname()) as $n => $linea) {
            if (str_contains($linea, "Storage::disk('public')")) {
                $sitios[] = basename($fichero->getPathname()).':'.($n + 1);
            }
        }
    }

    sort($sitios);

    return $sitios;
}

it('ningun modelo construye URLs mirando el disco local del contenedor', function () {
    /*
     * POR QUÉ ESTA PRUEBA. Se borraron dos accessors muertos —`image_url` de
     * `Product` y `profile_photo_url` de `Owner`— que armaban una URL mirando
     * `storage/app/public`, el disco del contenedor, para fotos que viven en
     * R2. Su condición era siempre falsa en producción, así que devolvían la
     * imagen por defecto para siempre sin que nada fallara.
     *
     * No afirma que esos dos ya no estén —eso sería una tautología—: afirma
     * que el patrón no VUELVE, que es lo que pasa de verdad cuando alguien
     * copia un accessor de un proyecto viejo. Y es la clase de error que no da
     * ningún error.
     *
     * PASADA DE CONTROL, porque esto afirma una AUSENCIA: si el recorrido se
     * rompe, la lista sale vacía y felicita a todo el mundo para siempre. Se
     * le da primero un árbol con la respuesta sabida.
     */
    $control = sys_get_temp_dir().'/control-disco-'.bin2hex(random_bytes(4));
    mkdir($control.'/Anidado', 0777, true);

    file_put_contents(
        $control.'/Anidado/Sospechoso.php',
        "<?php\n\nclass X\n{\n    // Storage::disk('public')->exists(\$x);\n}\n",
    );
    file_put_contents($control.'/NoEsPhp.txt', "Storage::disk('public')\n");

    try {
        expect(mirasAlDiscoDelContenedor($control))
            ->toBe(['Sospechoso.php:5']);
    } finally {
        @unlink($control.'/Anidado/Sospechoso.php');
        @unlink($control.'/NoEsPhp.txt');
        @rmdir($control.'/Anidado');
        @rmdir($control);
    }

    expect(mirasAlDiscoDelContenedor(app_path('Models')))->toBe([]);
});

afterEach(function () {
    // El comando no toca la base, pero la factura de prueba sí.
    DB::table('invoices')->delete();
});
