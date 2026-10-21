<?php

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * EL ACUERDO DE VINCULACIÓN, FIRMADO POR SU PROPIO DUEÑO
 *
 * Solo se podía firmar desde el panel de administración, así que vincularse
 * exigía coincidir físicamente con alguien del equipo y la firma de una
 * persona quedaba capturada desde la sesión de otra.
 *
 * Las pruebas de este archivo son sobre un documento legal, y por eso van
 * sobre lo que tiene que poder sostenerse: que firme lo que leyó, que se sepa
 * de dónde salió la firma y que nadie refirme por encima.
 */

/** Un PNG de un píxel, que es lo que produciría el trazo más corto posible. */
function firmaDePrueba(): string
{
    return 'data:image/png;base64,'
        . 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
}

/** Un domiciliario con su cuenta, dentro. */
function domiciliarioQueEntra(?string $documento = '1143112233'): array
{
    \App\Models\Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);

    $user = \App\Models\User::factory()->create(['rol' => 3]);

    $id = DB::table('domiciliary')->insertGetId([
        'user_id' => $user->user_id, 'available' => 1,
        'qualification' => 0, 'state' => 1, 'document' => $documento,
    ], 'domiciliary_id');

    Sanctum::actingAs($user);

    return ['user' => $user, 'domiciliary_id' => $id];
}

/* ---------------------------------------------------------------------- */

test('el domiciliario puede leer su acuerdo antes de firmarlo', function () {
    domiciliarioQueEntra();

    $r = $this->getJson('/v1/domiciliaries/contrato')->assertSuccessful();

    expect($r->json('firmado'))->toBeFalse()
        ->and($r->json('texto'))->not->toBeEmpty();
});

test('el texto que lee es el MISMO del PDF, no una copia', function () {
    /*
     * Dos redacciones del mismo acuerdo se desincronizan en la primera
     * corrección que alguien haga de un lado solo, y entonces hay una persona
     * que aceptó un texto y un archivo que dice otro.
     */
    domiciliarioQueEntra();

    $texto = collect($this->getJson('/v1/domiciliaries/contrato')->json('texto'));
    $titulos = $texto->where('tipo', 'titulo')->pluck('texto');

    expect($titulos)->toContain('CLÁUSULA PRIMERA. OBJETO')
        ->and($titulos)->toContain('CLÁUSULA DÉCIMA SÉPTIMA. ACEPTACIÓN');
});

test('el acuerdo dice que la ubicación se toma SOLO durante el turno', function () {
    /*
     * Es lo que hace defendible el reporte en segundo plano. Sin esta cláusula
     * escrita y aceptada, seguir a un trabajador es tratamiento de datos sin
     * finalidad declarada.
     */
    domiciliarioQueEntra();

    $texto = collect($this->getJson('/v1/domiciliaries/contrato')->json('texto'))
        ->pluck('texto')
        ->implode(' ');

    expect($texto)->toContain('GEOLOCALIZACIÓN DURANTE EL TURNO DE REPARTO')
        ->and($texto)->toContain('Fuera del turno de reparto no se recolecta la ubicación')
        ->and($texto)->toContain('revocar');
});

test('firmarlo lo deja guardado y con su fecha', function () {
    $d = domiciliarioQueEntra();

    $this->postJson('/v1/domiciliaries/contrato', [
        'signature' => firmaDePrueba(),
        'city'      => 'Barranquilla',
        'acepta'    => true,
    ])->assertStatus(201);

    $fila = DB::table('domiciliary')->where('domiciliary_id', $d['domiciliary_id'])->first();

    expect($fila->contract_signed_at)->not->toBeNull()
        ->and($fila->contract_city)->toBe('Barranquilla')
        ->and($fila->contract_media_id)->not->toBeNull();
});

test('sin aceptar, no se firma: trazar no es aceptar', function () {
    // Trazar una firma es un gesto; decir «leí y acepto» es la declaración.
    domiciliarioQueEntra();

    $this->postJson('/v1/domiciliaries/contrato', [
        'signature' => firmaDePrueba(),
        'city'      => 'Barranquilla',
        'acepta'    => false,
    ])->assertStatus(422);
});

test('sin documento no se firma, porque el acuerdo lo lleva impreso', function () {
    domiciliarioQueEntra(documento: null);

    $this->postJson('/v1/domiciliaries/contrato', [
        'signature' => firmaDePrueba(),
        'city'      => 'Barranquilla',
        'acepta'    => true,
    ])->assertStatus(422)->assertJsonPath('reason', 'falta_documento');
});

test('el documento se puede escribir en el mismo paso', function () {
    $d = domiciliarioQueEntra(documento: null);

    $this->postJson('/v1/domiciliaries/contrato', [
        'signature' => firmaDePrueba(),
        'city'      => 'Barranquilla',
        'document'  => '1143998877',
        'acepta'    => true,
    ])->assertStatus(201);

    expect(DB::table('domiciliary')->where('domiciliary_id', $d['domiciliary_id'])->value('document'))
        ->toBe('1143998877');
});

test('un acuerdo ya firmado NO se refirma desde la app', function () {
    /*
     * Si se dejara, bastaría con entrar otra vez para reemplazar el documento
     * que respalda una vinculación.
     */
    domiciliarioQueEntra();

    $cuerpo = ['signature' => firmaDePrueba(), 'city' => 'Barranquilla', 'acepta' => true];

    $this->postJson('/v1/domiciliaries/contrato', $cuerpo)->assertStatus(201);
    $this->postJson('/v1/domiciliaries/contrato', $cuerpo)
        ->assertStatus(409)
        ->assertJsonPath('reason', 'ya_firmado');
});

test('una firma que no es una imagen se rechaza', function () {
    domiciliarioQueEntra();

    $this->postJson('/v1/domiciliaries/contrato', [
        'signature' => 'esto no es un PNG',
        'city'      => 'Barranquilla',
        'acepta'    => true,
    ])->assertStatus(422);
});

test('quien no es domiciliario no tiene acuerdo que firmar', function () {
    \App\Models\Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Sanctum::actingAs(\App\Models\User::factory()->create(['rol' => 1]));

    $this->getJson('/v1/domiciliaries/contrato')->assertStatus(403);
    $this->postJson('/v1/domiciliaries/contrato', [
        'signature' => firmaDePrueba(), 'city' => 'Barranquilla', 'acepta' => true,
    ])->assertStatus(403);
});

test('nadie firma el acuerdo de otro: no hay identificador en la ruta', function () {
    $mio = domiciliarioQueEntra();

    // Otro domiciliario, sin firmar.
    $otroUser = \App\Models\User::factory()->create(['rol' => 3]);
    $otroId = DB::table('domiciliary')->insertGetId([
        'user_id' => $otroUser->user_id, 'available' => 1,
        'qualification' => 0, 'state' => 1, 'document' => '1140001111',
    ], 'domiciliary_id');

    $this->postJson('/v1/domiciliaries/contrato', [
        'signature' => firmaDePrueba(), 'city' => 'Barranquilla', 'acepta' => true,
    ])->assertStatus(201);

    // El que firmó fue el de la sesión, y solo él.
    expect(DB::table('domiciliary')->where('domiciliary_id', $mio['domiciliary_id'])->value('contract_signed_at'))
        ->not->toBeNull();
    expect(DB::table('domiciliary')->where('domiciliary_id', $otroId)->value('contract_signed_at'))
        ->toBeNull();
});

test('una vez firmado, el domiciliario puede descargarlo', function () {
    /*
     * La clausula de firma electronica promete que lo pone a su disposicion
     * para descargarlo cuando quiera. Un acuerdo que describe algo que no se
     * puede hacer vale menos que uno que no lo menciona.
     */
    domiciliarioQueEntra();

    $this->postJson('/v1/domiciliaries/contrato', [
        'signature' => firmaDePrueba(),
        'city'      => 'Barranquilla',
        'acepta'    => true,
    ])->assertStatus(201);

    $r = $this->getJson('/v1/domiciliaries/contrato')->assertSuccessful();

    expect($r->json('firmado'))->toBeTrue()
        ->and($r->json('url'))->not->toBeNull();
});

test('sin firmar no hay nada que descargar', function () {
    domiciliarioQueEntra();

    expect($this->getJson('/v1/domiciliaries/contrato')->json('url'))->toBeNull();
});

test('el acuerdo declara cuanto se guarda la ubicacion, y hay quien la borre', function () {
    /*
     * La Ley 1581 obliga a suprimir los datos cuando termina la finalidad que
     * los justifico. Un plazo escrito en el contrato y una tabla que no se
     * borra nunca es la clase de desajuste que convierte un documento de
     * respaldo en una prueba en contra.
     */
    domiciliarioQueEntra();

    $texto = collect($this->getJson('/v1/domiciliaries/contrato')->json('texto'))
        ->pluck('texto')
        ->implode(' ');

    expect($texto)->toContain('noventa (90) días calendario');

    // Y el comando que lo cumple existe y esta programado.
    expect(array_key_exists('ubicaciones:podar', \Illuminate\Support\Facades\Artisan::all()))
        ->toBeTrue();

    $programadas = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->map(fn ($e) => $e->command ?? '')
        ->filter(fn ($c) => str_contains($c, 'ubicaciones:podar'));

    expect($programadas)->not->toBeEmpty();
});

test('la suspension no puede ser indefinida ni sin causa', function () {
    // Es el mayor riesgo de tutela del acuerdo: la Corte ya dijo que estas
    // empresas ejercen un «poder normativo» sobre los repartidores.
    domiciliarioQueEntra();

    $texto = collect($this->getJson('/v1/domiciliaries/contrato')->json('texto'))
        ->pluck('texto')
        ->implode(' ');

    expect($texto)->toContain('la causa concreta')
        ->and($texto)->toContain('diez (10) días hábiles')
        ->and($texto)->toContain('una persona')
        ->and($texto)->toContain('ya haya prestado');
});
