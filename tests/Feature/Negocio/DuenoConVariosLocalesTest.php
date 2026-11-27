<?php

use App\Jobs\EnviarAvisoPush;
use App\Models\Notification;
use App\Models\Owner\Owner;
use App\Models\Rol;
use App\Models\User;
use App\Services\Avisos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Sin orden ni local en el aviso, la app y el servidor podían elegir locales distintos.
function duenoConLocales(array $nombres): array
{
    Rol::firstOrCreate(['rol_id' => 2], ['name' => 'tendero', 'guard_name' => 'web']);
    $user = User::factory()->create(['rol' => 2]);
    $ownerId = DB::table('owner')->insertGetId(['user_id' => $user->user_id, 'state' => 1], 'owner_id');
    $tipo = DB::table('category_business')->insertGetId(['name' => 'Tienda'], 'id');

    $ids = [];
    foreach ($nombres as $nombre) {
        $ids[$nombre] = DB::table('business')->insertGetId([
            'name' => $nombre, 'qualification' => 0, 'state' => 1, 'type' => $tipo,
        ], 'busines_id');
        DB::table('owner_busines')->insert(['owner_id' => $ownerId, 'busines_id' => $ids[$nombre], 'state' => 1]);
    }

    return ['user' => $user, 'ownerId' => $ownerId, 'ids' => $ids];
}

test('los locales del dueño salen por nombre, como los elige el servidor', function () {
    // Insertados al revés del orden alfabético a propósito.
    $d = duenoConLocales(['Zeta Market', 'Ana Bakery']);

    $nombres = Owner::find($d['ownerId'])->businesses->pluck('name')->all();

    expect($nombres)->toBe(['Ana Bakery', 'Zeta Market']);
});

test('el aviso de un pedido lleva el local del pedido', function () {
    Queue::fake();
    $d = duenoConLocales(['Ana Bakery', 'Zeta Market']);

    Rol::firstOrCreate(['rol_id' => 1], ['name' => 'comprador', 'guard_name' => 'web']);
    $comprador = User::factory()->create(['rol' => 1]);
    $buyerId = DB::table('buyer')->insertGetId(['user_id' => $comprador->user_id, 'qualification' => 0, 'state' => 1]);
    $pedido = DB::table('orderssales')->insertGetId([
        'buyer_id' => $buyerId, 'busines_id' => $d['ids']['Zeta Market'],
        'subtotal' => 18000, 'domicilio' => 2000, 'total' => 20000,
        'sale_date' => now(), 'state' => 1, 'payment_state' => 'paid',
    ], 'orderSales_id');

    Avisos::para($d['user']->user_id, 'pedido_nuevo', 'Pedido nuevo', ['order_id' => $pedido]);

    // En la campana…
    $aviso = Notification::where('user_id', $d['user']->user_id)->latest('notification_id')->first();
    expect((int) $aviso->datos['business_id'])->toBe($d['ids']['Zeta Market']);

    // …y en el push, como texto.
    Queue::assertPushed(
        EnviarAvisoPush::class,
        fn ($job) => $job->datos['business_id'] === (string) $d['ids']['Zeta Market'],
    );
});

test('si el aviso ya dice su local, no se toca', function () {
    Queue::fake();
    $d = duenoConLocales(['Ana Bakery']);

    Avisos::para($d['user']->user_id, 'mensaje_nuevo', 'Hola', ['chat_id' => 5, 'business_id' => 99]);

    $aviso = Notification::where('user_id', $d['user']->user_id)->latest('notification_id')->first();
    expect((int) $aviso->datos['business_id'])->toBe(99);
});
