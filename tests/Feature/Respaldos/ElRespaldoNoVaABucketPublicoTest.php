<?php

use App\Support\DestinoDelRespaldo;
use Spatie\Backup\Events\DumpingDatabase;
use Spatie\DbDumper\DbDumper;

/**
 * LA COPIA DE SEGURIDAD NO VA A UN BUCKET PÚBLICO.
 *
 * `config/filesystems.php` resuelve el problema con dos discos: `r2` para los
 * medios, que **lleva `url`** porque la app tiene que enseñar las fotos, y
 * `r2_respaldos` para las copias, deliberadamente sin `url`.
 *
 * Lo que elige entre los dos es una variable de entorno y los dos nombres se
 * parecen. Escribir `r2` donde iba `r2_respaldos` **no da ningún error**: la
 * copia se sube, `backup:monitor` la encuentra sana, el aviso diario dice que
 * todo está bien, y el volcado de la base entera —personas, teléfonos,
 * direcciones, pagos— queda descargable por cualquiera que pruebe la URL.
 *
 * ESTO SE ESCRIBIÓ DESPUÉS DE COMETER EL ERROR. Al preparar las instrucciones
 * del despliegue se recomendó por escrito `BACKUP_DISK=r2`. Era el nombre del
 * bucket de medios. La recomendación equivocada es la razón de que exista esta
 * red: si el error se comete leyendo la documentación, la documentación no es
 * suficiente.
 */
test('el .env.example NO recomienda el disco publico, que es de donde salio el error', function () {
    /*
     * AQUÍ ESTABA EL ORIGEN, y por eso esta prueba es sobre un fichero de
     * texto y no sobre código.
     *
     * La recomendación `BACKUP_DISK=r2` no me la inventé: la traía el propio
     * `.env.example`, en su bloque de «qué poner en producción». O sea que
     * cualquiera que siguiera la plantilla del repositorio habría acabado
     * subiendo el volcado de la base a un bucket con URL pública, y no habría
     * hecho nada mal: habría hecho lo que el repositorio le dijo.
     *
     * La guarda de runtime es la red; esto es que la plantilla no empuje a
     * saltarla. Una guarda que se dispara todos los días en producción
     * acabaría quitada.
     */
    $plantilla = (string) file_get_contents(base_path('.env.example'));

    expect($plantilla)->not->toContain('BACKUP_DISK=r2'."\n");
    expect($plantilla)->not->toContain('BACKUP_DISK=r2 ');
    expect($plantilla)->toContain('r2_respaldos');
});

test('un disco con url aborta el respaldo', function () {
    config(['filesystems.disks.publico_de_prueba' => [
        'driver' => 'local',
        'root' => storage_path('app/publico_de_prueba'),
        'url' => 'https://medios.vecipaya.com',
    ]]);

    expect(fn () => DestinoDelRespaldo::comprobar(['publico_de_prueba']))
        ->toThrow(RuntimeException::class);
});

test('el mensaje dice cual es el disco bueno, porque el error es de nombre', function () {
    /*
     * El contenido del mensaje importa más de lo normal acá: quien lo lea
     * estará mirando un correo de «respaldo fallido» a las dos de la mañana,
     * sin este archivo delante. Si no dice el nombre correcto, la reacción
     * probable es quitar el disco y volver a `local`, que es perder el
     * respaldo fuera del servidor.
     */
    config(['filesystems.disks.r2' => [
        'driver' => 's3',
        'url' => 'https://medios.vecipaya.com',
    ]]);

    try {
        DestinoDelRespaldo::comprobar(['r2']);
        $this->fail('Tenía que lanzar.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())
            ->toContain('r2_respaldos')
            ->toContain('r2')
            ->toContain('https://medios.vecipaya.com');
    }
});

test('el disco de respaldos de verdad pasa, que es la otra mitad', function () {
    /*
     * Una comprobación que rechazara de más es peor que ninguna: dejaría la
     * plataforma sin copias y el mensaje diría que es por seguridad. Así que
     * se afirma también que el disco BUENO pasa, tal como está configurado en
     * el repositorio.
     */
    expect(config('filesystems.disks.r2_respaldos.url'))->toBeNull();

    DestinoDelRespaldo::comprobar(['r2_respaldos']);

    // Y el de por omisión, `local`, que es donde se queda si nadie configura
    // nada: tampoco tiene url.
    DestinoDelRespaldo::comprobar(['local']);

    expect(true)->toBeTrue();
});

test('la comprobacion esta ENGANCHADA al respaldo, no solo escrita', function () {
    /*
     * ESTA ES LA PRUEBA QUE IMPORTA. Las de arriba comprueban la clase; esta
     * comprueba que algo la llama. Una guarda perfecta que nadie invoca es
     * exactamente igual de útil que no tenerla, y el fallo sería invisible:
     * todo en verde y la base en un bucket público.
     *
     * Se dispara el evento que spatie lanza antes de volcar la base, con el
     * disco mal puesto, y se espera que reviente.
     */
    config(['filesystems.disks.r2' => [
        'driver' => 's3',
        'url' => 'https://medios.vecipaya.com',
    ]]);
    config(['backup.backup.destination.disks' => ['r2']]);

    $volcador = new class extends DbDumper
    {
        public function dumpToFile(string $dumpFile): void {}
    };

    expect(fn () => event(new DumpingDatabase($volcador)))
        ->toThrow(RuntimeException::class);
});

test('con el disco bien puesto, el respaldo arranca', function () {
    // La contrapartida del enganche: el evento no revienta cuando el destino
    // es correcto. Sin esto, la prueba de arriba pasaría igual con una guarda
    // que lanzara SIEMPRE, y eso dejaría la plataforma sin copias.
    config(['backup.backup.destination.disks' => ['r2_respaldos']]);

    $volcador = new class extends DbDumper
    {
        public function dumpToFile(string $dumpFile): void {}
    };

    event(new DumpingDatabase($volcador));

    expect(true)->toBeTrue();
});
