<?php

use App\Jobs\EnviarAvisoPush;
use App\Models\Conjunto\SolicitudDeEntrada;
use App\Models\Rol;
use App\Models\User;
use App\Services\AccesoAlConjunto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/**
 * EL RESIDENTE AUTORIZA LA ENTRADA DESDE LA APP.
 *
 * Antes «quién autorizó» era el nombre que el celador escribía tras llamar al
 * citófono; nada comprobaba que esa persona hubiera dicho que sí.
 */
function residenteEn(int $complexId, string $torre = '3', string $apto = '502'): User
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    $u = User::factory()->create(['rol' => 1, 'name' => 'María Gómez']);
    DB::table('buyer')->insert(['user_id' => $u->user_id, 'qualification' => 0, 'state' => 1, 'belongs_to_complex' => 1]);
    DB::table('user_address')->insert([
        'user_id' => $u->user_id, 'address' => 'Calle 84',
        'complex_id' => $complexId, 'tower' => $torre, 'apartment' => $apto, 'state' => 1,
    ]);

    return $u;
}

beforeEach(fn () => Queue::fake());

test('el celador pregunta y al residente de ese apartamento le llega el aviso', function () {
    $c = conjuntoConPersonal();
    $vecina = residenteEn($c['complexId']);
    residenteEn($c['complexId'], '1', '101'); // otro apartamento: no

    Sanctum::actingAs($c['user']);

    $r = $this->postJson('/v1/conjunto/porteria/autorizaciones', [
        'visitor_name' => 'Juan Pérez', 'tower' => '3', 'apartment' => '502',
    ])->assertCreated()
        ->assertJsonPath('state', 'pendiente')
        ->assertJsonPath('residents_notified', 1);

    Queue::assertPushed(
        EnviarAvisoPush::class,
        fn ($job) => (int) $job->userId === (int) $vecina->user_id
            && $job->datos['tipo'] === 'visita_por_autorizar'
            && $job->datos['solicitud_id'] === (string) $r->json('id'),
    );
});

test('si nadie de ese apartamento tiene la app, se dice enseguida', function () {
    $c = conjuntoConPersonal();
    Sanctum::actingAs($c['user']);

    $this->postJson('/v1/conjunto/porteria/autorizaciones', [
        'visitor_name' => 'Juan', 'tower' => '9', 'apartment' => '999',
    ])->assertStatus(422)->assertJsonPath('reason', 'sin_residentes');
});

test('el residente dice que sí y el panel ve quién autorizó', function () {
    $c = conjuntoConPersonal();
    $vecina = residenteEn($c['complexId']);

    Sanctum::actingAs($c['user']);
    $id = $this->postJson('/v1/conjunto/porteria/autorizaciones', [
        'visitor_name' => 'Juan Pérez', 'tower' => '3', 'apartment' => '502',
    ])->json('id');

    Sanctum::actingAs($vecina);
    $this->getJson('/v1/porteria/visitas')->assertOk()
        ->assertJsonPath('data.0.visitor_name', 'Juan Pérez')
        ->assertJsonPath('data.0.conjunto', 'Los Almendros');

    $this->postJson("/v1/porteria/visitas/{$id}/respuesta", ['decision' => 'si'])
        ->assertOk()->assertJsonPath('state', 'autorizada');

    // Una sola respuesta: la segunda ya no cambia nada.
    $this->postJson("/v1/porteria/visitas/{$id}/respuesta", ['decision' => 'no'])
        ->assertStatus(409);

    Sanctum::actingAs($c['user']);
    $this->getJson("/v1/conjunto/porteria/autorizaciones/{$id}")
        ->assertOk()
        ->assertJsonPath('state', 'autorizada')
        ->assertJsonPath('answered_by_name', 'María Gómez');
});

test('quien no vive en ese apartamento no puede contestar', function () {
    $c = conjuntoConPersonal();
    residenteEn($c['complexId']);
    $otro = residenteEn($c['complexId'], '1', '101');

    Sanctum::actingAs($c['user']);
    $id = $this->postJson('/v1/conjunto/porteria/autorizaciones', [
        'visitor_name' => 'Juan', 'tower' => '3', 'apartment' => '502',
    ])->json('id');

    Sanctum::actingAs($otro);
    $this->postJson("/v1/porteria/visitas/{$id}/respuesta", ['decision' => 'si'])
        ->assertNotFound();
    $this->getJson('/v1/porteria/visitas')->assertJsonCount(0, 'data');
});

test('pasados los minutos, la solicitud vence y ya no abre a nadie', function () {
    $c = conjuntoConPersonal();
    $vecina = residenteEn($c['complexId']);

    Sanctum::actingAs($c['user']);
    $id = $this->postJson('/v1/conjunto/porteria/autorizaciones', [
        'visitor_name' => 'Juan', 'tower' => '3', 'apartment' => '502',
    ])->json('id');

    SolicitudDeEntrada::where('id', $id)->update(['expires_at' => now()->subMinute()]);

    Sanctum::actingAs($vecina);
    $this->postJson("/v1/porteria/visitas/{$id}/respuesta", ['decision' => 'si'])
        ->assertStatus(409)->assertJsonPath('state', 'vencida');
});

test('un celador sin turno no puede preguntar', function () {
    $c = conjuntoConPersonal(enTurno: false);
    residenteEn($c['complexId']);
    Sanctum::actingAs($c['user']);

    $this->postJson('/v1/conjunto/porteria/autorizaciones', [
        'visitor_name' => 'Juan', 'tower' => '3', 'apartment' => '502',
    ])->assertStatus(409)->assertJsonPath('reason', 'sin_turno');
});

test('cuando el domiciliario entra, al residente le llega «tu pedido ya entró»', function () {
    $c = conjuntoConPersonal();
    $domi = repartidorConPedidoEn($c['complexId']);
    $codigo = app(AccesoAlConjunto::class)->generar($domi)['code'];

    Sanctum::actingAs($c['user']);
    $this->postJson('/v1/conjunto/porteria/verificar', ['code' => $codigo])
        ->assertOk()->assertJsonPath('allowed', true);

    Queue::assertPushed(
        EnviarAvisoPush::class,
        fn ($job) => $job->datos['tipo'] === 'domiciliario_en_porteria',
    );
});
