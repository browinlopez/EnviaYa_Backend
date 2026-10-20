<?php

use App\Models\Promotion;
use Laravel\Sanctum\Sanctum;

/**
 * DESDE CUÁNDO Y HASTA CUÁNDO VALE UNA PROMOCIÓN.
 *
 * Hasta ahora el tendero solo podía elegir entre cuatro plazos enlatados —hoy,
 * mañana, fin de semana, una semana—. Quien quería un día exacto tenía que
 * escribirlo en el texto, y entonces la fecha era una frase que el servidor no
 * entendía: la promoción seguía venciendo cuando le daba la gana al plazo.
 *
 * Las dos formas conviven a propósito. Los botones rápidos son buena interfaz
 * para el caso común y quitarlos sería romper algo que funciona; y además son
 * lo que manda la app ya publicada en las tiendas, que no se puede actualizar
 * a voluntad. Por eso la primera prueba de este archivo es la de atrás.
 */

/** El tendero, ya dentro. */
function tenderoDePromociones(): array
{
    $t = tenderoConCatalogo();
    Sanctum::actingAs($t['user']);

    return $t;
}

/* ---------------------------------------------------------------------- */

test('la app publicada sigue mandando su plazo de siempre y funciona', function () {
    tenderoDePromociones();

    $this->postJson('/v1/negocio/promociones', [
        'texto' => 'Hoy el arroz a mitad de precio',
        'hasta' => 'hoy',
    ])->assertStatus(201);

    $p = Promotion::latest('promotion_id')->first();

    expect($p->end_date)->not->toBeNull()
        ->and($p->end_date->setTimezone('America/Bogota')->toDateString())
        ->toBe(now('America/Bogota')->toDateString());
});

test('el tendero puede poner las fechas exactas que quiera', function () {
    tenderoDePromociones();

    $this->postJson('/v1/negocio/promociones', [
        'texto'      => 'Arroz al 20% el puente',
        'desde'      => '2026-11-20',
        'hasta'      => '2026-11-23',
        'tipo'       => Promotion::PORCENTAJE,
        'porcentaje' => 20,
    ])->assertStatus(201);

    $p = Promotion::latest('promotion_id')->first();

    /*
     * En hora colombiana y de día entero, que es lo que significan para quien
     * las escribe: «del 20 al 23» incluye el 23 completo, no hasta las 00:00.
     */
    expect($p->start_date->setTimezone('America/Bogota')->toDateString())->toBe('2026-11-20')
        ->and($p->end_date->setTimezone('America/Bogota')->toDateString())->toBe('2026-11-23')
        ->and($p->end_date->setTimezone('America/Bogota')->format('H:i'))->toBe('23:59');
});

test('una promoción con fecha de inicio futura nace sin aplicarse todavía', function () {
    tenderoDePromociones();

    $this->postJson('/v1/negocio/promociones', [
        'texto' => 'El viernes, tres por dos en gaseosa',
        'desde' => now('America/Bogota')->addDays(2)->toDateString(),
        'hasta' => now('America/Bogota')->addDays(3)->toDateString(),
    ])->assertStatus(201);

    $p = Promotion::latest('promotion_id')->first();

    expect($p->empezada())->toBeFalse()
        ->and($p->vigente())->toBeFalse();
});

test('el tope de usos se guarda y viaja a la app', function () {
    tenderoDePromociones();

    $r = $this->postJson('/v1/negocio/promociones', [
        'texto'    => 'Los primeros 50 llevan el doble',
        'hasta'    => 'semana',
        'max_usos' => 50,
    ])->assertStatus(201);

    expect($r->json('promocion.max_usos'))->toBe(50)
        ->and($r->json('promocion.usos'))->toBe(0)
        ->and($r->json('promocion.agotada'))->toBeFalse();

    expect((int) Promotion::latest('promotion_id')->first()->max_usos)->toBe(50);
});

test('un tope de cero se rechaza, porque eso no es un límite: es no mandarla', function () {
    tenderoDePromociones();

    $this->postJson('/v1/negocio/promociones', [
        'texto'    => 'Promoción imposible',
        'max_usos' => 0,
    ])->assertStatus(422);
});

test('corregir el texto no le borra la fecha a la promoción', function () {
    /*
     * Esto estaba roto: `corregir()` recalculaba `end_date` desde el plazo
     * SIEMPRE, así que una corrección que no volviera a mandar el plazo
     * —cambiar una palabra mal escrita— dejaba la promoción sin vencimiento y
     * valiendo para siempre.
     */
    tenderoDePromociones();

    $this->postJson('/v1/negocio/promociones', [
        'texto' => 'Arroz barato esta semana',
        'hasta' => 'semana',
    ])->assertStatus(201);

    $p = Promotion::latest('promotion_id')->first();
    $vencia = $p->end_date->toIso8601String();

    $this->putJson("/v1/negocio/promociones/{$p->promotion_id}", [
        'texto' => 'Arroz barato esta semana, mientras dure',
    ])->assertSuccessful();

    expect($p->fresh()->end_date?->toIso8601String())->toBe($vencia);
});

test('corregir sin mandar el tope no se lo borra', function () {
    /*
     * La app publicada no sabe que `max_usos` existe, así que al corregir no
     * manda nada. Si el campo se escribiera siempre, esa corrección le
     * quitaría el tope a una promoción creada desde la pantalla nueva.
     */
    tenderoDePromociones();

    $this->postJson('/v1/negocio/promociones', [
        'texto'    => 'Los primeros 20',
        'max_usos' => 20,
    ])->assertStatus(201);

    $p = Promotion::latest('promotion_id')->first();

    $this->putJson("/v1/negocio/promociones/{$p->promotion_id}", [
        'texto' => 'Los primeros 20, mientras alcance',
    ])->assertSuccessful();

    expect((int) $p->fresh()->max_usos)->toBe(20);
});
