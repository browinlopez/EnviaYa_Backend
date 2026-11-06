<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * LA TIENDA CAMBIA SU LOGO DESDE SU PANEL.
 *
 * Solo imágenes, y queda apuntado en `business.logo`, que es lo que lee la
 * app. El logo de la tienda vecina no se toca.
 */
beforeEach(function () {
    config([
        'filesystems.disks.r2.key'      => 'prueba',
        'filesystems.disks.r2.secret'   => 'prueba',
        'filesystems.disks.r2.endpoint' => 'https://r2.prueba',
        'filesystems.disks.r2.url'      => 'https://archivos.prueba',
    ]);
    Storage::fake('r2');
});

test('la tienda sube su logo y queda en su ficha', function () {
    $t = tenderoConCatalogo();
    Sanctum::actingAs($t['user']);

    $url = $this->post('/v1/negocio/me/logo', [
        'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
    ], ['Accept' => 'application/json'])->assertCreated()->json('logo');

    expect($url)->toStartWith('https://archivos.prueba/')
        ->and(DB::table('business')->where('busines_id', $t['mio'])->value('logo'))->toBe($url)
        ->and(DB::table('business')->where('busines_id', $t['vecino'])->value('logo'))->not->toBe($url);
});

test('un archivo que no es imagen no entra como logo', function () {
    $t = tenderoConCatalogo();
    Sanctum::actingAs($t['user']);

    $this->post('/v1/negocio/me/logo', [
        'logo' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('logo');
});
