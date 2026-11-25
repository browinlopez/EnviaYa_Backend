<?php

use App\Support\ProductTypeSchema;

/*
 * Lo que el comprador lee de un producto: una sola lista para todos los
 * oficios, solo con lo que tiene valor y con las etiquetas del esquema.
 */

function productoDePrueba(array $comunes, ?string $relacion = null, array $delOficio = []): object
{
    $p = (object) array_merge(
        ['brand' => null, 'content' => null, 'presentation' => null, 'requiere_frio' => null],
        $comunes,
    );
    foreach (['grocery', 'pharmacy', 'restaurant', 'carPart'] as $r) {
        $p->{$r} = null;
    }
    if ($relacion) {
        $p->{$relacion} = (object) $delOficio;
    }

    return $p;
}

test('una droguería enseña principio activo, dosis y si pide fórmula', function () {
    $p = productoDePrueba(
        ['brand' => 'MK', 'content' => '10 tabletas'],
        'pharmacy',
        ['active_ingredient' => 'Acetaminofén', 'dosage' => '500 mg', 'requiere_formula' => 1, 'registro_invima' => null],
    );

    $detalles = ProductTypeSchema::detallesParaElComprador(ProductTypeSchema::TYPE_PHARMACY, $p);

    expect(array_column($detalles, 'valor', 'campo'))->toBe([
        'brand' => 'MK',
        'content' => '10 tabletas',
        'requiere_formula' => 'Sí',
        'active_ingredient' => 'Acetaminofén',
        'dosage' => '500 mg',
    ]);
    expect(array_column($detalles, 'etiqueta', 'campo')['requiere_formula'])
        ->toBe('Requiere fórmula médica');
});

test('los sí/no que valen no no se dicen', function () {
    $p = productoDePrueba(
        ['requiere_frio' => 0],
        'restaurant',
        ['is_vegan' => false, 'es_vegetariano' => true, 'minutos_preparacion' => 25],
    );

    $campos = array_column(
        ProductTypeSchema::detallesParaElComprador(ProductTypeSchema::TYPE_RESTAURANT, $p),
        'campo',
    );

    expect($campos)->toContain('es_vegetariano', 'minutos_preparacion')
        ->not->toContain('is_vegan', 'requiere_frio');

    $valores = array_column(
        ProductTypeSchema::detallesParaElComprador(ProductTypeSchema::TYPE_RESTAURANT, $p),
        'valor',
        'campo',
    );
    expect($valores['minutos_preparacion'])->toBe('25 min');
});

test('sin fila del oficio, solo lo común', function () {
    $p = productoDePrueba(['content' => '500 g']);

    expect(ProductTypeSchema::detallesParaElComprador(ProductTypeSchema::TYPE_CAR_PARTS, $p))
        ->toBe([['campo' => 'content', 'etiqueta' => 'Contenido', 'valor' => '500 g']]);
    expect(ProductTypeSchema::detallesParaElComprador(null, productoDePrueba([])))->toBe([]);
});
