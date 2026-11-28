<?php

use App\Models\Area;
use App\Models\Chat\Preorden;
use App\Models\Chat\PreordenEvento;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

// Las cifras del panel salen de la tabla de preórdenes, no del texto de los mensajes.
function panelDeCalidad(): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);
    $user = User::factory()->create([
        'rol' => 4, 'area_id' => Area::where('code', 'calidad')->value('id'), 'access_level' => 'gestor',
    ]);
    Sanctum::actingAs($user);

    return $user;
}

function preordenEnTabla(int $chat, int $negocio, string $estado, float $original, float $final, bool $ajustada = false): Preorden
{
    $fila = Preorden::create([
        'chat_id' => $chat, 'busines_id' => $negocio, 'created_by' => 1, 'de' => 'cliente',
        'status' => $estado, 'items' => [['product_id' => 1, 'quantity' => 1, 'price' => $final]],
        'total' => $final, 'ajustada' => $ajustada,
        'confirmed_at' => in_array($estado, ['confirmed', 'paid'], true) ? now() : null,
    ]);
    PreordenEvento::create(['preorder_id' => $fila->id, 'accion' => 'creada', 'despues' => ['total' => $original]]);

    return $fila;
}

test('el resumen cuenta creadas, pagadas, canceladas y cuánto ajustan los negocios', function () {
    $negocio = DB::table('business')->insertGetId(['name' => 'Droguería del Panel', 'qualification' => 0, 'state' => 1], 'busines_id');
    $chat = DB::table('chats')->insertGetId(['type' => 'private', 'busines_id' => $negocio], 'chat_id');
    preordenEnTabla($chat, $negocio, 'paid', 30000, 16000, ajustada: true);
    preordenEnTabla($chat, $negocio, 'cancelled', 10000, 10000);
    preordenEnTabla($chat, $negocio, 'pending', 5000, 5000);

    panelDeCalidad();

    $this->getJson('/v1/admin/preordenes/resumen?days=30')
        ->assertOk()
        ->assertJsonPath('creadas', 3)
        ->assertJsonPath('pagadas', 1)
        ->assertJsonPath('canceladas', 1)
        ->assertJsonPath('pendientes', 1)
        ->assertJsonPath('ajustadas', 1)
        ->assertJsonPath('monto_pagado', 16000)
        ->assertJsonPath('ajuste_total', 14000)
        ->assertJsonPath('negocios.0.nombre', 'Droguería del Panel')
        ->assertJsonPath('negocios.0.creadas', 3)
        ->assertJsonCount(3, 'recientes');

    // El contador de la lista de chats también sale de la tabla.
    $fila = collect($this->getJson('/v1/admin/chats')->json())->firstWhere('chat_id', $chat);
    expect((int) $fila['preorders_count'])->toBe(3);
});

test('solo acepta los periodos del panel', function () {
    panelDeCalidad();

    $this->getJson('/v1/admin/preordenes/resumen?days=13')->assertStatus(422);
});

test('quien no es del panel no ve las cifras', function () {
    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    Sanctum::actingAs(User::factory()->create(['rol' => 1]));

    $this->getJson('/v1/admin/preordenes/resumen')->assertForbidden();
});
