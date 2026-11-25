<?php

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * EL OTRO BUSCADOR DE CLIENTES POR TELÉFONO.
 *
 * Hay dos rutas que hacen lo mismo --encontrar a un comprador por su número
 * para afiliarlo-- y sólo una estaba arreglada:
 *
 *   · `businesses/affiliations/searchBusinesses`, que es la que usa la app,
 *     y que ya tiene sus pruebas en `BuscarCompradorPorTelefonoTest`;
 *   · `negocio/clientes/buscar`, que comparaba el texto exacto
 *     --`where('phone', $telefono)`-- y hoy no la llama nadie desde la app.
 *
 * Que no la llame nadie no la hace inofensiva: está publicada, y el día que
 * una pantalla la use heredará el fallo ya corregido en la otra. Las dos
 * pasan ahora por el mismo `scopeConTelefono`, y estas pruebas son el
 * recordatorio de por qué.
 */
function clienteYTendero(string $telefonoGuardado): array
{
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);

    $tendero = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId(['user_id' => $tendero->user_id, 'state' => 1], 'owner_id');

    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');
    $negocio = DB::table('business')->insertGetId([
        'name' => 'La tienda', 'qualification' => 0, 'state' => 1, 'type' => $tipo,
        'latitude' => 11.0, 'longitude' => -74.8,
    ], 'busines_id');
    DB::table('owner_busines')->insert(['owner_id' => $ownerId, 'busines_id' => $negocio, 'state' => 1]);

    $comprador = User::factory()->create(['rol' => 1, 'phone' => $telefonoGuardado]);
    DB::table('buyer')->insert([
        'user_id' => $comprador->user_id, 'qualification' => 0, 'belongs_to_complex' => 0, 'state' => 1,
    ]);

    Sanctum::actingAs($tendero);

    return compact('tendero', 'comprador');
}

it('encuentra al comprador aunque su telefono este guardado con espacios', function () {
    $e = clienteYTendero('304 536 8709');

    $this->getJson('/v1/negocio/clientes/buscar?telefono=3045368709')
        ->assertOk()
        ->assertJsonPath('cliente.user_id', $e['comprador']->user_id);
})->group('afiliacion');

it('y al reves, guardado seguido y buscado con espacios', function () {
    $e = clienteYTendero('3045368709');

    $this->getJson('/v1/negocio/clientes/buscar?telefono='.urlencode('304 536 8709'))
        ->assertOk()
        ->assertJsonPath('cliente.user_id', $e['comprador']->user_id);
})->group('afiliacion');

it('sigue sin encontrar a quien no existe', function () {
    clienteYTendero('304 536 8709');

    $this->getJson('/v1/negocio/clientes/buscar?telefono=3000000000')
        ->assertNotFound()
        ->assertJsonPath('cliente', null);
})->group('afiliacion');
