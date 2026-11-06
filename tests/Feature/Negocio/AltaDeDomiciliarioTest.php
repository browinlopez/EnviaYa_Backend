<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * LA TIENDA DA DE ALTA A SUS DOMICILIARIOS.
 *
 * Lo que no se puede romper:
 *  1. El que crea la tienda queda solo en esa tienda, fuera de turno y SIN
 *     poder recibir pedidos hasta tener acuerdo y papeles.
 *  2. A los que dio de alta el equipo no se les exige nada nuevo: hoy
 *     reparten sin papeles en el sistema y no pueden quedar bloqueados.
 *  3. Vincular a uno existente no lo duplica.
 */
function tiendaParaAltas(): array
{
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);

    $tendero = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId(['user_id' => $tendero->user_id, 'state' => 1], 'owner_id');
    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $mia = DB::table('business')->insertGetId([
        'name' => 'Tienda del Buho', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');
    DB::table('owner_busines')->insert(['owner_id' => $ownerId, 'busines_id' => $mia, 'state' => 1]);

    return compact('tendero', 'mia');
}

function datosDeDomiciliario(array $cambios = []): array
{
    return array_merge([
        'name' => 'Carlos Mendoza', 'document' => '1045678901', 'phone' => '300 123 4568',
        'email' => 'carlos@ejemplo.com', 'password' => 'clave-segura-1',
    ], $cambios);
}

test('el que crea la tienda queda en su tienda, fuera de turno y sin poder repartir', function () {
    $t = tiendaParaAltas();
    Sanctum::actingAs($t['tendero']);

    $id = $this->postJson('/v1/negocio/domiciliarios', datosDeDomiciliario())
        ->assertCreated()->json('domiciliary_id');

    $d = DB::table('domiciliary')->where('domiciliary_id', $id)->first();
    expect((int) $d->registrado_por_negocio)->toBe($t['mia'])
        ->and((int) $d->available)->toBe(0)
        ->and($d->document)->toBe('1045678901')
        ->and(DB::table('business_domiciliary')->where('domiciliary_id', $id)->count())->toBe(1);

    $lista = collect($this->getJson('/v1/negocio/domiciliarios')->assertOk()->json('domiciliaries'));
    $suyo = $lista->firstWhere('domiciliary_id', $id);
    expect($suyo['can_take'])->toBeFalse()
        ->and($suyo['papeles_faltantes'])->toBe(['acuerdo', 'cedula', 'licencia', 'soat']);

    $ficha = $this->getJson("/v1/negocio/domiciliarios/{$id}")->assertOk();
    expect($ficha->json('despacho.bloqueado'))->toBeTrue();
});

test('con acuerdo y papeles vigentes ya puede repartir', function () {
    $t = tiendaParaAltas();
    Sanctum::actingAs($t['tendero']);
    $id = $this->postJson('/v1/negocio/domiciliarios', datosDeDomiciliario())->json('domiciliary_id');

    DB::table('domiciliary')->where('domiciliary_id', $id)
        ->update(['contract_signed_at' => now(), 'available' => 1]);
    foreach (['cedula' => null, 'licencia' => now()->addYear(), 'soat' => now()->addYear()] as $tipo => $vence) {
        DB::table('domiciliary_documents')->insert([
            'domiciliary_id' => $id, 'type' => $tipo, 'expires_at' => $vence, 'state' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $suyo = collect($this->getJson('/v1/negocio/domiciliarios')->json('domiciliaries'))
        ->firstWhere('domiciliary_id', $id);
    expect($suyo['can_take'])->toBeTrue()->and($suyo['papeles_faltantes'])->toBe([]);
});

test('a los que dio de alta el equipo no se les exige papeles nuevos', function () {
    $t = tiendaParaAltas();
    $u = User::factory()->create(['rol' => 3]);
    $id = DB::table('domiciliary')->insertGetId([
        'user_id' => $u->user_id, 'available' => 1, 'qualification' => 0, 'state' => 1,
    ], 'domiciliary_id');
    DB::table('business_domiciliary')->insert(['busines_id' => $t['mia'], 'domiciliary_id' => $id, 'state' => 1]);

    Sanctum::actingAs($t['tendero']);
    $delEquipo = collect($this->getJson('/v1/negocio/domiciliarios')->json('domiciliaries'))
        ->firstWhere('domiciliary_id', $id);

    expect($delEquipo['can_take'])->toBeTrue()->and($delEquipo['papeles_faltantes'])->toBe([]);
});

test('se busca por teléfono y se vincula sin duplicarlo', function () {
    $t = tiendaParaAltas();
    $u = User::factory()->create(['rol' => 3, 'phone' => '3001112233']);
    $id = DB::table('domiciliary')->insertGetId([
        'user_id' => $u->user_id, 'available' => 1, 'qualification' => 0, 'state' => 1,
    ], 'domiciliary_id');

    Sanctum::actingAs($t['tendero']);
    $hallado = $this->getJson('/v1/negocio/domiciliarios/buscar?q=300 111 2233')->assertOk()->json('data');
    expect($hallado['domiciliary_id'])->toBe($id)->and($hallado['ya_es_tuyo'])->toBeFalse();

    $this->postJson('/v1/negocio/domiciliarios', ['domiciliary_id' => $id, 'q' => '300 111 2233'])->assertCreated();
    $this->postJson('/v1/negocio/domiciliarios', ['domiciliary_id' => $id, 'q' => '3001112233'])->assertCreated();

    expect(DB::table('business_domiciliary')->where('domiciliary_id', $id)->where('busines_id', $t['mia'])->count())->toBe(1)
        ->and(DB::table('domiciliary')->count())->toBe(1);
});

test('un correo que ya tiene cuenta o una cédula repetida no crean otro', function () {
    $t = tiendaParaAltas();
    User::factory()->create(['email' => 'ya@existe.com']);
    Sanctum::actingAs($t['tendero']);

    $this->postJson('/v1/negocio/domiciliarios', datosDeDomiciliario(['email' => 'ya@existe.com']))
        ->assertUnprocessable()->assertJsonValidationErrors('email');

    $this->postJson('/v1/negocio/domiciliarios', datosDeDomiciliario())->assertCreated();
    $this->postJson('/v1/negocio/domiciliarios', datosDeDomiciliario(['email' => 'otro@correo.com']))
        ->assertUnprocessable()->assertJsonValidationErrors('document');
});


test('no se vincula a un domiciliario por su id a secas ni con un dato de otro', function () {
    $t = tiendaParaAltas();
    $u = User::factory()->create(['rol' => 3, 'phone' => '3005550001']);
    $id = DB::table('domiciliary')->insertGetId([
        'user_id' => $u->user_id, 'document' => '1122334455', 'available' => 1, 'qualification' => 0, 'state' => 1,
    ], 'domiciliary_id');
    $otro = User::factory()->create(['rol' => 3, 'phone' => '3005550002']);
    DB::table('domiciliary')->insert(['user_id' => $otro->user_id, 'available' => 1, 'qualification' => 0, 'state' => 1]);

    Sanctum::actingAs($t['tendero']);

    // Recorrer ids 1, 2, 3... dejaba a cualquier tienda dueña de toda la flota.
    $this->postJson('/v1/negocio/domiciliarios', ['domiciliary_id' => $id])->assertStatus(422);
    // Un dato real, pero de OTRO domiciliario, tampoco sirve.
    $this->postJson('/v1/negocio/domiciliarios', ['domiciliary_id' => $id, 'q' => '3005550002'])->assertStatus(422);

    expect(DB::table('business_domiciliary')->where('domiciliary_id', $id)->where('busines_id', $t['mia'])->exists())->toBeFalse();

    // Con su cédula exacta, sí.
    $this->postJson('/v1/negocio/domiciliarios', ['domiciliary_id' => $id, 'q' => '1122334455'])->assertCreated();
});

test('una tienda que lo desvinculo ya no ve su ficha ni sus papeles', function () {
    $t = tiendaParaAltas();
    $u = User::factory()->create(['rol' => 3, 'phone' => '3005550003']);
    $id = DB::table('domiciliary')->insertGetId([
        'user_id' => $u->user_id, 'available' => 1, 'qualification' => 0, 'state' => 1,
    ], 'domiciliary_id');
    DB::table('business_domiciliary')->insert(['busines_id' => $t['mia'], 'domiciliary_id' => $id, 'state' => 0]);

    Sanctum::actingAs($t['tendero']);

    // Un vínculo apagado no es un vínculo: antes seguía abriendo todo.
    $this->getJson("/v1/negocio/domiciliarios/{$id}")->assertNotFound();
    $this->getJson("/v1/negocio/domiciliarios/{$id}/documentos/cedula/archivo")->assertNotFound();
});
