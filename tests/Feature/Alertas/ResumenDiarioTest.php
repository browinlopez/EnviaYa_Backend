<?php

use App\Mail\ResumenDiario;
use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use App\Services\ResumenDeArea;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * EL RESUMEN DIARIO
 *
 * Lo que se comprueba es lo que decide si un aviso automático se lee o se
 * ignora:
 *
 *  · no se escribe a quien no tiene nada;
 *  · cada asunto llega a su RESPONSABLE, no a todo el que pueda verlo;
 *  · y el permiso sigue mandando, para no contarle a nadie algo de una sección
 *    que no puede abrir.
 */

function personalDe(string $codigoArea, int $cuantos = 1): void
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    User::factory()->count($cuantos)->create([
        'rol'     => 4,
        'area_id' => Area::where('code', $codigoArea)->firstOrFail()->id,
        'state'   => 1,
    ]);
}

function domiciliarioSinAcuerdo(): void
{
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);
    $u = User::factory()->create(['rol' => 3]);

    DB::table('domiciliary')->insert([
        'user_id'            => $u->user_id,
        'available'          => 1,
        'state'              => 1,
        'contract_signed_at' => null,
        'qualification'      => 0,
    ]);
}

it('sin pendientes no se escribe a nadie', function () {
    Mail::fake();
    personalDe('sst');

    $this->artisan('resumen:diario')
        ->expectsOutputToContain('No hay nada pendiente')
        ->assertExitCode(0);

    // Un correo diario que la mitad de los días dice "todo en orden" se
    // convierte en un correo que nadie abre.
    Mail::assertNothingSent();
});

it('el asunto llega a su responsable', function () {
    Mail::fake();
    personalDe('sst');
    domiciliarioSinAcuerdo();

    $this->artisan('resumen:diario')->assertExitCode(0);

    Mail::assertSent(ResumenDiario::class, function ($correo) {
        return $correo->area->code === 'sst'
            && collect($correo->asuntos)->contains(fn ($a) => $a['clave'] === 'sin_acuerdo');
    });
});

it('no le llega a las áreas que solo PUEDEN verlo', function () {
    Mail::fake();
    personalDe('sst');
    personalDe('calidad');
    domiciliarioSinAcuerdo();

    $this->artisan('resumen:diario')->assertExitCode(0);

    /*
     * Calidad ve a los domiciliarios, así que con solo el filtro de permisos
     * este asunto también le llegaba — igual que a otras cuatro áreas. Y eso es
     * lo que hace que un aviso se ignore: si le llega a todos, nadie lo siente
     * suyo y nadie lo atiende.
     */
    Mail::assertNotSent(ResumenDiario::class, fn ($c) => $c->area->code === 'calidad');
});

it('a quien supervisa le llega lo urgente de las demás, marcado como ajeno', function () {
    Mail::fake();
    personalDe('gerencia');
    domiciliarioSinAcuerdo();

    $this->artisan('resumen:diario')->assertExitCode(0);

    Mail::assertSent(ResumenDiario::class, function ($correo) {
        if ($correo->area->code !== 'gerencia') {
            return false;
        }

        $a = collect($correo->asuntos)->firstWhere('clave', 'sin_acuerdo');

        // Marcado, y con el nombre de quién lo atiende: sin eso parecería su
        // tarea y Gerencia acabaría persiguiendo trabajo de SST.
        return $a && $a['ajeno'] === true && $a['de'] === 'SST';
    });
});

it('lo NO urgente de otra área no se reenvía a quien supervisa', function () {
    personalDe('gerencia');

    // Un documento que vence en 10 días: hay que renovarlo, no es urgente.
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);
    $u = User::factory()->create(['rol' => 3]);
    $id = DB::table('domiciliary')->insertGetId([
        'user_id' => $u->user_id, 'available' => 1, 'state' => 1,
        'contract_signed_at' => now(), 'qualification' => 0,
    ], 'domiciliary_id');

    DB::table('domiciliary_documents')->insert([
        'domiciliary_id' => $id,
        'type'           => 'soat',
        'expires_at'     => now()->addDays(10)->toDateString(),
        'state'          => 1,
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    $asuntos = app(ResumenDeArea::class)
        ->asuntosDe(Area::where('code', 'gerencia')->firstOrFail());

    // Reenviar a Gerencia lo no urgente de todas es el relleno que convierte un
    // resumen en correo basura.
    expect(collect($asuntos)->pluck('clave'))->not->toContain('documentos_por_vencer');
});

it('el orden pone lo urgente primero', function () {
    personalDe('sst');
    domiciliarioSinAcuerdo();

    $id = (int) DB::table('domiciliary')->value('domiciliary_id');

    // Uno vencido (urgente) y uno por vencer (no urgente).
    DB::table('domiciliary_documents')->insert([
        [
            'domiciliary_id' => $id, 'type' => 'soat',
            'expires_at' => now()->subDay()->toDateString(),
            'state' => 1, 'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'domiciliary_id' => $id, 'type' => 'arl',
            'expires_at' => now()->addDays(10)->toDateString(),
            'state' => 1, 'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    $asuntos = app(ResumenDeArea::class)
        ->asuntosDe(Area::where('code', 'sst')->firstOrFail());

    $urgentes = array_map(fn ($a) => $a['urgente'], $asuntos);

    /*
     * Todos los urgentes antes del primer no urgente. Sin esto, lo que hay que
     * atender hoy aparecía debajo de lo que puede esperar un mes — y fue
     * exactamente lo que pasó: el comparador cruzaba `$a` y `$b` para invertir
     * un criterio y sacaba lo no urgente primero.
     */
    $primerNoUrgente = array_search(false, $urgentes, true);

    if ($primerNoUrgente !== false) {
        expect(array_slice($urgentes, $primerNoUrgente))->each->toBeFalse();
    }

    expect($urgentes[0])->toBeTrue();
});

it('un área con pendientes y sin nadie asignado se avisa', function () {
    Mail::fake();
    domiciliarioSinAcuerdo();
    // Nadie en SST: el asunto existe y no hay a quién contárselo.

    $this->artisan('resumen:diario')
        ->expectsOutputToContain('sin nadie asignado')
        ->assertExitCode(0);

    Mail::assertNothingSent();
});

it('la prueba en seco no envía nada', function () {
    Mail::fake();
    personalDe('sst');
    domiciliarioSinAcuerdo();

    $this->artisan('resumen:diario --seco')
        ->expectsOutputToContain('no se envió nada')
        ->assertExitCode(0);

    Mail::assertNothingSent();
});
