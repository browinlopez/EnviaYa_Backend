<?php

use App\Models\Domiciliary;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * LA FOTO DE QUE SE ENTREGO.
 *
 * Una entrega era la palabra del domiciliario y nada mas: tocaba «Si, lo
 * entregue», el pedido pasaba a estado 4 y ahi terminaba. Cuando el cliente
 * llama diciendo que no le llego, no habia nada que mirar, y la discusion la
 * pierde siempre quien no tiene como probarlo.
 *
 * Lo que de verdad importa de estas pruebas son tres cosas:
 *
 *  · que NADIE pueda colgarle una foto a la entrega de otro --es una foto de
 *    la puerta de la casa de alguien--;
 *  · que la foto NO sea obligatoria para entregar, y por eso vive en una
 *    llamada aparte: si fueran la misma, una subida fallida --y en la calle
 *    falla la mitad-- impediria cerrar una entrega que si ocurrio;
 *  · y que se pueda subir DESPUES, con el pedido ya entregado.
 */
function domiciliarioConPedidoParaEvidencia(int $estado = 3): array
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);

    $u = User::factory()->create(['rol' => 3]);
    $d = Domiciliary::create([
        'user_id' => $u->user_id, 'document' => '1098700'.random_int(100, 999),
        'available' => 1, 'state' => 1,
    ]);

    $comprador = User::factory()->create(['rol' => 1]);
    $buyerId = DB::table('buyer')->insertGetId([
        'user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1,
    ], 'buyer_id');

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'La tienda', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
    ], 'busines_id');

    DB::table('payment_methods')->insertOrIgnore([
        'methods_id' => 1, 'name' => 'Efectivo', 'state' => 1,
    ]);

    $pedido = DB::table('orderssales')->insertGetId([
        'buyer_id' => $buyerId, 'busines_id' => $negocio,
        'domiciliary_id' => $d->domiciliary_id,
        'subtotal' => 20000, 'domicilio' => 0, 'total' => 20000,
        'sale_date' => now(), 'state' => $estado,
        'payment_state' => 'paid', 'methods_id' => 1,
    ], 'orderSales_id');

    return ['user' => $u, 'domiciliario' => $d, 'pedido' => $pedido];
}

beforeEach(function () {
    /* Disco falso: estas pruebas son sobre QUIEN puede subir y QUE queda
       apuntado, no sobre si R2 responde. */
    Storage::fake('r2');
    config(['filesystems.disks.r2.key' => 'de-mentira']);
});

it('guarda la foto y la deja apuntada en media_files', function () {
    $e = domiciliarioConPedidoParaEvidencia();
    Sanctum::actingAs($e['user']);

    $r = $this->postJson("/v1/domiciliaries/orders/{$e['pedido']}/evidencia", [
        'foto' => UploadedFile::fake()->image('entrega.jpg', 800, 600),
    ]);

    $r->assertStatus(201);

    $fila = DB::table('media_files')
        ->where('entity_type', 'order')
        ->where('entity_id', $e['pedido'])
        ->where('tipo', 'entrega')
        ->first();

    expect($fila)->not->toBeNull()
        ->and($fila->uploaded_by)->toEqual($e['user']->user_id);
})->group('evidencia');

it('NO deja colgarle una foto a la entrega de otro', function () {
    // Es una foto de la puerta de la casa de alguien.
    $mio = domiciliarioConPedidoParaEvidencia();
    $ajeno = domiciliarioConPedidoParaEvidencia();

    Sanctum::actingAs($mio['user']);

    $this->postJson("/v1/domiciliaries/orders/{$ajeno['pedido']}/evidencia", [
        'foto' => UploadedFile::fake()->image('entrega.jpg'),
    ])->assertStatus(404);

    expect(DB::table('media_files')->where('entity_id', $ajeno['pedido'])->count())->toBe(0);
})->group('evidencia');

it('404 y no 403: que exista pero sea de otro ya dice algo', function () {
    $ajeno = domiciliarioConPedidoParaEvidencia();
    $mio = domiciliarioConPedidoParaEvidencia();

    Sanctum::actingAs($mio['user']);

    $this->getJson("/v1/domiciliaries/orders/{$ajeno['pedido']}/evidencia")
        ->assertStatus(404);
})->group('evidencia');

it('se puede subir DESPUES de entregar, porque no es obligatoria', function () {
    // En la calle la mitad de las subidas falla. Obligar a que llegue antes
    // de cerrar la entrega dejaria al domiciliario plantado en la puerta.
    $e = domiciliarioConPedidoParaEvidencia(estado: 4);
    Sanctum::actingAs($e['user']);

    $this->postJson("/v1/domiciliaries/orders/{$e['pedido']}/evidencia", [
        'foto' => UploadedFile::fake()->image('tarde.jpg'),
    ])->assertStatus(201);
})->group('evidencia');

it('rechaza lo que no es una imagen', function () {
    $e = domiciliarioConPedidoParaEvidencia();
    Sanctum::actingAs($e['user']);

    $this->postJson("/v1/domiciliaries/orders/{$e['pedido']}/evidencia", [
        'foto' => UploadedFile::fake()->create('cosa.pdf', 100, 'application/pdf'),
    ])->assertStatus(422);
})->group('evidencia');

it('rechaza una foto de mas de 4 MB, y dice por que', function () {
    // 4 MB y no 8: esto se sube desde la calle, con la red del telefono y el
    // cliente esperando.
    $e = domiciliarioConPedidoParaEvidencia();
    Sanctum::actingAs($e['user']);

    $r = $this->postJson("/v1/domiciliaries/orders/{$e['pedido']}/evidencia", [
        'foto' => UploadedFile::fake()->create('enorme.jpg', 5000, 'image/jpeg'),
    ]);

    $r->assertStatus(422);
    expect($r->json('message').json_encode($r->json('errors')))->toContain('4 MB');
})->group('evidencia');

it('devuelve null cuando todavia no hay foto', function () {
    $e = domiciliarioConPedidoParaEvidencia();
    Sanctum::actingAs($e['user']);

    $this->getJson("/v1/domiciliaries/orders/{$e['pedido']}/evidencia")
        ->assertOk()
        ->assertJsonPath('data', null);
})->group('evidencia');

it('un comprador no puede subir nada', function () {
    $e = domiciliarioConPedidoParaEvidencia();

    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    $comprador = User::factory()->create(['rol' => 1]);
    Sanctum::actingAs($comprador);

    $this->postJson("/v1/domiciliaries/orders/{$e['pedido']}/evidencia", [
        'foto' => UploadedFile::fake()->image('entrega.jpg'),
    ])->assertStatus(404);
})->group('evidencia');
