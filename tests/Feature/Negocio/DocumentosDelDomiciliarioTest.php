<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * LA TIENDA SUBE LOS PAPELES DE SU DOMICILIARIO.
 *
 * Lo que se prueba es lo que se rompería en silencio:
 *  1. Solo la tienda donde reparte puede subirle papeles.
 *  2. Subir uno nuevo archiva el anterior en vez de borrarlo, y la ficha pasa
 *     a decir que lo tiene.
 *  3. Un papel con vencimiento no entra sin fecha.
 */
function tiendaParaPapeles(): array
{
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);

    $tendero = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId(
        ['user_id' => $tendero->user_id, 'state' => 1], 'owner_id',
    );
    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');

    $mia = DB::table('business')->insertGetId([
        'name' => 'Tienda del Buho', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');
    $otra = DB::table('business')->insertGetId([
        'name' => 'Tienda La Esquina', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    DB::table('owner_busines')->insert([
        'owner_id' => $ownerId, 'busines_id' => $mia, 'state' => 1,
    ]);

    $nuevoDomi = function (int $negocio) {
        $u = User::factory()->create(['rol' => 3]);
        $id = DB::table('domiciliary')->insertGetId([
            'user_id' => $u->user_id, 'available' => 1, 'qualification' => 0, 'state' => 1,
        ], 'domiciliary_id');
        DB::table('business_domiciliary')->insert([
            'busines_id' => $negocio, 'domiciliary_id' => $id, 'state' => 1,
        ]);

        return $id;
    };

    return [
        'tendero' => $tendero,
        'suyo'    => $nuevoDomi($mia),
        'ajeno'   => $nuevoDomi($otra),
    ];
}

beforeEach(function () {
    // Almacenamiento falso: ninguna prueba escribe en el bucket de verdad.
    config([
        'filesystems.disks.r2.key'      => 'prueba',
        'filesystems.disks.r2.secret'   => 'prueba',
        'filesystems.disks.r2.endpoint' => 'https://r2.prueba',
    ]);
    Storage::fake('r2');
});

test('no se le suben papeles a quien no reparte para tu tienda', function () {
    $t = tiendaParaPapeles();
    Sanctum::actingAs($t['tendero']);

    $this->postJson("/v1/negocio/domiciliarios/{$t['ajeno']}/documentos", [
        'type' => 'cedula',
        'file' => UploadedFile::fake()->image('cedula.jpg'),
    ])->assertNotFound();

    expect(DB::table('domiciliary_documents')->count())->toBe(0);
});

test('subir uno nuevo archiva el anterior y la ficha lo da por tenido', function () {
    $t = tiendaParaPapeles();
    Sanctum::actingAs($t['tendero']);

    $vence = now()->addYear()->toDateString();

    foreach (['soat-viejo.jpg', 'soat-nuevo.pdf'] as $nombre) {
        $this->post("/v1/negocio/domiciliarios/{$t['suyo']}/documentos", [
            'type'       => 'soat',
            'expires_at' => $vence,
            'file'       => UploadedFile::fake()->create($nombre, 200, str_ends_with($nombre, 'pdf') ? 'application/pdf' : 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    $filas = DB::table('domiciliary_documents')->where('type', 'soat')->get();
    expect($filas)->toHaveCount(2)
        ->and($filas->where('state', 1))->toHaveCount(1);

    $ficha = $this->getJson("/v1/negocio/domiciliarios/{$t['suyo']}")->assertOk();
    $soat = collect($ficha->json('documentos'))->firstWhere('tipo', 'soat');

    expect($soat['situacion'])->not->toBe('falta')
        ->and($soat['vence_el'])->toBe($vence);
});

test('un SOAT sin fecha de vencimiento no entra', function () {
    $t = tiendaParaPapeles();
    Sanctum::actingAs($t['tendero']);

    $this->postJson("/v1/negocio/domiciliarios/{$t['suyo']}/documentos", [
        'type' => 'soat',
        'file' => UploadedFile::fake()->image('soat.jpg'),
    ])->assertUnprocessable()->assertJsonValidationErrors('expires_at');
});

test('el acuerdo de vinculación no se sube desde la tienda', function () {
    $t = tiendaParaPapeles();
    Sanctum::actingAs($t['tendero']);

    $this->postJson("/v1/negocio/domiciliarios/{$t['suyo']}/documentos", [
        'type' => 'acuerdo',
        'file' => UploadedFile::fake()->create('acuerdo.pdf', 100, 'application/pdf'),
    ])->assertUnprocessable()->assertJsonValidationErrors('type');
});

test('la tienda ve el archivo de un papel de su domiciliario, y de uno ajeno no', function () {
    $t = tiendaParaPapeles();
    Sanctum::actingAs($t['tendero']);

    $this->post("/v1/negocio/domiciliarios/{$t['suyo']}/documentos", [
        'type' => 'cedula',
        'file' => UploadedFile::fake()->create('cedula.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $cedula = collect($this->getJson("/v1/negocio/domiciliarios/{$t['suyo']}")->json('documentos'))
        ->firstWhere('tipo', 'cedula');
    expect($cedula['tiene_archivo'])->toBeTrue()->and($cedula['es_pdf'])->toBeTrue();

    $this->get("/v1/negocio/domiciliarios/{$t['suyo']}/documentos/cedula/archivo")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    // Un papel que no tiene, o un domiciliario que no es de la tienda: nada.
    $this->getJson("/v1/negocio/domiciliarios/{$t['suyo']}/documentos/soat/archivo")->assertNotFound();
    $this->getJson("/v1/negocio/domiciliarios/{$t['ajeno']}/documentos/cedula/archivo")->assertNotFound();
});
