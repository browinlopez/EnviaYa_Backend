<?php

use Illuminate\Support\Facades\DB;

/*
 * Las fotos revisadas que no son del producto se quitan; las buenas no se
 * tocan, y el ensayo no escribe nada.
 */
function productoConFoto(array $datos): int
{
    return DB::table('products')->insertGetId(array_merge([
        'name' => 'Producto',
        'image' => 'https://ejemplo.test/foto.jpg',
        'state' => 1,
    ], $datos), 'products_id');
}

test('corrige la de la lista, arregla la carga de prueba y deja la buena', function () {
    // Está en la lista revisada por su código de barras.
    $malo = productoConFoto(['name' => 'PAÑALES WINNY ETAPA 3', 'barcode' => '7700000004499']);
    $dePrueba = productoConFoto(['name' => 'LECHE BAVARIA 107G', 'barcode' => '7790000100007', 'origen' => 'prueba-carga']);
    // Nombre inventado pero producto real: lleva la foto del real.
    $deVerdad = productoConFoto(['name' => 'ARROZ DIANA 100G', 'barcode' => '7790000100000', 'origen' => 'prueba-carga']);
    $bueno = productoConFoto(['name' => 'ACEITE RIQUISIMO 900ML', 'barcode' => '7700000000071']);

    $this->artisan('catalogo:quitar-fotos-equivocadas')->assertSuccessful();
    expect(DB::table('products')->where('products_id', $malo)->value('image'))->toBe('https://ejemplo.test/foto.jpg');

    $this->artisan('catalogo:quitar-fotos-equivocadas', ['--aplicar' => true])->assertSuccessful();

    // En la lista trae su foto correcta: se reemplaza, no se deja vacía.
    expect(DB::table('products')->where('products_id', $malo)->value('image'))
        ->not->toBeNull()->not->toBe('https://ejemplo.test/foto.jpg');
    expect(DB::table('products')->where('products_id', $dePrueba)->value('image'))->toBeNull();
    expect(DB::table('products')->where('products_id', $deVerdad)->value('image'))
        ->toContain('olimpica');
    expect(DB::table('products')->where('products_id', $bueno)->value('image'))->not->toBeNull();
});
