<?php

use App\Models\Conjunto\ComplexStaff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * El libro de la portería: qué ve cada quien, qué queda auditado y que el
 * buscador no se deje engañar por comodines.
 */
function visitaEnElLibro(int $complexId, string $nombre = 'Marta Robles'): int
{
    return DB::table('complex_entries')->insertGetId([
        'complex_id' => $complexId, 'kind' => 'visitante', 'method' => 'manual',
        'visitor_name' => $nombre, 'visitor_document' => '1098765678',
        'visitor_phone' => '3001234321', 'orders_count' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/* ------------------------- DATOS ENMASCARADOS ------------------------- */

test('el celador ve cedula y telefono con solo los cuatro ultimos', function () {
    $c = conjuntoConPersonal(ComplexStaff::CELADOR);
    visitaEnElLibro($c['complexId']);

    $domi = repartidorConPedidoEn(null, '1090125678');
    DB::table('complex_entries')->insert([
        'complex_id' => $c['complexId'], 'domiciliary_id' => $domi->domiciliary_id,
        'method' => 'cedula', 'orders_count' => 1,
        'created_at' => now()->subMinute(), 'updated_at' => now(),
    ]);

    Sanctum::actingAs($c['user']);

    $filas = collect($this->getJson('/v1/conjunto/porteria/entradas')->assertOk()->json('data'));
    $visita = $filas->firstWhere('kind', 'visitante');
    $domiciliario = $filas->firstWhere('kind', 'domiciliario');

    expect($visita['visitor_document'])->toBe('••••5678')
        ->and($visita['visitor_phone'])->toBe('••••4321')
        ->and($visita['visitor_name'])->toBe('Marta Robles')
        ->and($domiciliario['document'])->toBe('••••5678')
        ->and($domiciliario['domiciliary_phone'])->toStartWith('••••');

    // Quién sigue adentro, igual.
    $adentro = collect($this->getJson('/v1/conjunto/porteria/adentro')->assertOk()->json('data'));
    expect($adentro->pluck('documento')->all())->each->toStartWith('••••');
});

test('el administrador del conjunto ve los datos completos', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    visitaEnElLibro($c['complexId']);

    Sanctum::actingAs($c['user']);

    $r = $this->getJson('/v1/conjunto/porteria/entradas')->assertOk();

    expect($r->json('data.0.visitor_document'))->toBe('1098765678')
        ->and($r->json('data.0.visitor_phone'))->toBe('3001234321');

    expect($this->getJson('/v1/conjunto/porteria/adentro')->json('data.0.documento'))->toBe('1098765678');
});

test('el celador sigue encontrando por nombre', function () {
    $c = conjuntoConPersonal(ComplexStaff::CELADOR);
    visitaEnElLibro($c['complexId'], 'Marta Robles');
    visitaEnElLibro($c['complexId'], 'Pedro Gil');

    Sanctum::actingAs($c['user']);

    $r = $this->getJson('/v1/conjunto/porteria/entradas?search=Marta')->assertOk();

    expect($r->json('data'))->toHaveCount(1)
        ->and($r->json('data.0.visitor_name'))->toBe('Marta Robles');
});

/* ----------------------------- AUDITORÍA ------------------------------ */

test('marcar la salida deja una fila en audits con el antes y el despues', function () {
    $c = conjuntoConPersonal(ComplexStaff::CELADOR);
    $id = visitaEnElLibro($c['complexId']);

    // El paquete trae `audit.console => false` y las pruebas corren en consola.
    config(['audit.console' => true]);

    Sanctum::actingAs($c['user']);

    $this->travelTo(now()->setTime(15, 30, 0));
    $this->putJson("/v1/conjunto/porteria/entradas/{$id}/salida")->assertOk();

    $audit = DB::table('audits')
        ->where('auditable_type', 'App\\Models\\Conjunto\\ComplexEntry')
        ->where('auditable_id', $id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->event)->toBe('updated')
        ->and((int) $audit->user_id)->toBe($c['user']->user_id);

    $antes = json_decode($audit->old_values, true);
    $despues = json_decode($audit->new_values, true);

    expect($antes['exited_at'])->toBeNull()
        ->and($despues['exited_at'])->toBe(now()->toDateTimeString())
        ->and((int) $despues['exit_registered_by'])->toBe($c['user']->user_id);

    // Y la respuesta y la fila siguen como antes.
    expect(DB::table('complex_entries')->where('id', $id)->value('exited_at'))->toBe(now()->toDateTimeString());
});

/* ------------------------- COMODINES EN EL LIKE ------------------------ */

test('buscar % en el libro no lo devuelve todo', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);
    visitaEnElLibro($c['complexId'], 'Marta Robles');
    visitaEnElLibro($c['complexId'], 'Pedro Gil');
    visitaEnElLibro($c['complexId'], 'Rebaja 50% Ltda');

    Sanctum::actingAs($c['user']);

    // Antes: las tres filas.
    expect($this->getJson('/v1/conjunto/porteria/entradas?search=%25')->json('data'))->toHaveCount(1);
    // `_` tampoco vale por «cualquier letra».
    expect($this->getJson('/v1/conjunto/porteria/entradas?search=M_rta')->json('data'))->toHaveCount(0);
    // `!` es el escape: escrito por el usuario es una letra más.
    expect($this->getJson('/v1/conjunto/porteria/entradas?search=!')->json('data'))->toHaveCount(0);
    // Una barra invertida tampoco rompe nada.
    $this->getJson('/v1/conjunto/porteria/entradas?search='.urlencode('\\'))->assertOk();
});

test('buscar % en los residentes no lo devuelve todo', function () {
    $c = conjuntoConPersonal(ComplexStaff::DUENO);

    foreach (['Ana Ruiz', 'Luis Mora'] as $nombre) {
        $u = User::factory()->create(['name' => $nombre]);
        $buyerId = DB::table('buyer')->insertGetId([
            'user_id' => $u->user_id, 'qualification' => 0, 'belongs_to_complex' => 1, 'state' => 1,
        ]);
        DB::table('buyer_complex')->insert(['buyer_id' => $buyerId, 'complex_id' => $c['complexId']]);
    }

    Sanctum::actingAs($c['user']);

    expect($this->getJson('/v1/conjunto/residentes/detalle')->json('meta.total'))->toBe(2)
        ->and($this->getJson('/v1/conjunto/residentes/detalle?search=%25')->json('meta.total'))->toBe(0)
        ->and($this->getJson('/v1/conjunto/residentes/detalle?search=_')->json('meta.total'))->toBe(0)
        ->and($this->getJson('/v1/conjunto/residentes/detalle?search=Ana')->json('meta.total'))->toBe(1);
});
