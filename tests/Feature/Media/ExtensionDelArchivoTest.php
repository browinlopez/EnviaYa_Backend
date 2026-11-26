<?php

use App\Services\MediaService;
use App\Support\ExtensionSegura;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * La extensión con la que se guarda un archivo la decide su contenido.
 *
 * Salía del nombre que mandaba el cliente: un PNG llamado `shell.php` pasaba
 * la validación `mimes:` —que mira el contenido— y quedaba en el bucket como
 * `.php`.
 */
function pngDeVerdad(): string
{
    // PNG de 1×1 transparente.
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
}

/**
 * Una subida de verdad, no `UploadedFile::fake()`: el doble de pruebas
 * adivina el tipo POR EL NOMBRE, que es justo lo que no se quiere medir.
 */
function subidaReal(string $nombre, string $contenido): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'sub');
    file_put_contents($ruta, $contenido);

    return new UploadedFile($ruta, $nombre, null, null, true);
}

beforeEach(function () {
    Storage::fake('r2');
    config([
        'filesystems.disks.r2.key' => 'k',
        'filesystems.disks.r2.secret' => 's',
        'filesystems.disks.r2.endpoint' => 'https://r2.ejemplo.test',
    ]);
});

test('un PNG llamado .php se guarda como .png', function () {
    $archivo = subidaReal('shell.php', pngDeVerdad());

    app(MediaService::class)->subir('negocios', 7, 'Tienda', $archivo, 'galeria');

    $key = DB::table('media_files')->value('object_key');

    expect($key)->toEndWith('.png')
        ->and($key)->not->toContain('.php');
    Storage::disk('r2')->assertExists($key);
});

test('lo que no es de la lista se guarda con la extension de respaldo, nunca la del cliente', function () {
    $archivo = subidaReal('nota.php', '<?php echo "hola";');

    expect(ExtensionSegura::de($archivo))->toBe('bin')
        ->and(ExtensionSegura::de($archivo, 'jpg'))->toBe('jpg');
});

test('un PDF y un JPG de verdad llevan la suya aunque el nombre diga otra', function () {
    $pdf = subidaReal('contrato.png', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

    // La imagen falsa vive en un temporal que se borra al soltarla.
    $imagen = UploadedFile::fake()->image('x.jpg');
    $jpg = subidaReal('foto.gif', file_get_contents($imagen->getRealPath()));

    expect(ExtensionSegura::de($pdf))->toBe('pdf')
        ->and(ExtensionSegura::de($jpg))->toBe('jpg');
});
