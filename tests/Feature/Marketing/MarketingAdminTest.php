<?php

use App\Models\Marketing\AdCampaign;
use App\Models\Marketing\Advertiser;
use App\Models\Marketing\Banner;
use App\Models\Marketing\Coupon;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * El módulo de marketing visto desde el panel.
 *
 * Lo que se prueba acá no es el CRUD por el CRUD, sino las reglas que impiden
 * perder plata o historial: que no se borre lo que ya facturó, que no se
 * vendan dos veces los mismos puestos, y que la puerta de rol 4 esté puesta.
 */

function comoAdmin(): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    $admin = User::factory()->create(['rol' => 4]);
    Sanctum::actingAs($admin);

    return $admin;
}

function anuncianteConCampana(): array
{
    $a = Advertiser::create(['name' => 'Marca X']);

    $c = AdCampaign::create([
        'advertiser_id' => $a->id,
        'name'          => 'Campaña X',
        'starts_at'     => now()->subDay()->toDateString(),
        'ends_at'       => now()->addDays(20)->toDateString(),
        'state'         => AdCampaign::ACTIVA,
    ]);

    return [$a, $c];
}

/* ------------------------------ PUERTA ------------------------------- */

test('sin sesión el módulo entero responde 401', function () {
    $this->getJson('/v1/admin/marketing/overview')->assertUnauthorized();
    $this->getJson('/v1/admin/marketing/banners')->assertUnauthorized();
});

test('un usuario sin rol de administración recibe 403', function () {
    // El panel de React ya filtra por rol, pero eso es comodidad de la
    // interfaz: la puerta real tiene que estar en el servidor.
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/v1/admin/marketing/overview')->assertForbidden();
});

/* --------------------------- ANUNCIANTES ----------------------------- */

test('crea y lista anunciantes con su conteo de campañas', function () {
    comoAdmin();

    $this->postJson('/v1/admin/marketing/advertisers', ['name' => 'Bebidas del Caribe'])
        ->assertCreated();

    $this->getJson('/v1/admin/marketing/advertisers')
        ->assertOk()
        ->assertJsonPath('0.name', 'Bebidas del Caribe')
        ->assertJsonPath('0.campaigns_count', 0);
});

test('no deja borrar un anunciante que ya tiene campañas', function () {
    comoAdmin();
    [$a] = anuncianteConCampana();

    // Borrarlo arrastraría campañas, banners y todo su historial de métricas.
    $this->deleteJson("/v1/admin/marketing/advertisers/{$a->id}")
        ->assertStatus(422);

    expect(Advertiser::find($a->id))->not->toBeNull();
});

/* ----------------------------- CAMPAÑAS ------------------------------ */

test('rechaza una campaña que termina antes de empezar', function () {
    comoAdmin();
    $a = Advertiser::create(['name' => 'Marca Y']);

    // Una campaña invertida no falla sola: simplemente no entrega nada, y eso
    // se descubre tarde y sin mensaje.
    $this->postJson('/v1/admin/marketing/campaigns', [
        'advertiser_id' => $a->id,
        'name'          => 'Invertida',
        'starts_at'     => now()->addDays(10)->toDateString(),
        'ends_at'       => now()->addDay()->toDateString(),
    ])->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
});

test('no deja borrar una campaña con piezas', function () {
    comoAdmin();
    [, $c] = anuncianteConCampana();

    Banner::create([
        'campaign_id' => $c->id,
        'title'       => 'Pieza',
        'placement'   => 'home_hero',
    ]);

    $this->deleteJson("/v1/admin/marketing/campaigns/{$c->id}")
        ->assertStatus(422);
});

/* ------------------------------ BANNERS ------------------------------ */

test('crea un banner con segmentación y la devuelve como arreglo', function () {
    comoAdmin();
    [, $c] = anuncianteConCampana();

    $r = $this->postJson('/v1/admin/marketing/banners', [
        'campaign_id'           => $c->id,
        'title'                 => 'Pieza segmentada',
        'placement'             => 'home_strip',
        'platform'              => 'app',
        'target_municipalities' => [1, 2, 3],
        'priority'              => 10,
    ])->assertCreated();

    $id = $r->json('id');

    $this->getJson("/v1/admin/marketing/banners/{$id}")
        ->assertOk()
        ->assertJsonPath('title', 'Pieza segmentada')
        ->assertJsonPath('target_municipalities', [1, 2, 3])
        // Sin imagen subida todavía: el panel lo avisa con esto.
        ->assertJsonPath('image_url', null);
});

test('las métricas de un banner nuevo salen en cero y sin dividir por cero', function () {
    comoAdmin();
    [, $c] = anuncianteConCampana();

    $b = Banner::create([
        'campaign_id' => $c->id,
        'title'       => 'Sin eventos',
        'placement'   => 'home_hero',
    ]);

    $this->getJson("/v1/admin/marketing/banners/{$b->id}/metrics")
        ->assertOk()
        ->assertJsonPath('impressions', 0)
        ->assertJsonPath('clicks', 0)
        ->assertJsonPath('ctr', 0);
});

/* ------------------------------ CUPONES ------------------------------ */

test('el código del cupón se guarda en mayúsculas', function () {
    comoAdmin();

    $this->postJson('/v1/admin/marketing/coupons', [
        'code'      => '  verano25 ',
        'value'     => 20,
        'starts_at' => now()->toDateString(),
        'ends_at'   => now()->addDays(10)->toDateString(),
    ])->assertCreated()->assertJsonPath('code', 'VERANO25');
});

test('no admite dos cupones con el mismo código', function () {
    comoAdmin();

    $datos = [
        'code'      => 'REPETIDO',
        'value'     => 10,
        'starts_at' => now()->toDateString(),
        'ends_at'   => now()->addDays(10)->toDateString(),
    ];

    $this->postJson('/v1/admin/marketing/coupons', $datos)->assertCreated();
    $this->postJson('/v1/admin/marketing/coupons', $datos)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
});

test('no deja borrar un cupón que ya se canjeó', function () {
    comoAdmin();

    $c = Coupon::create([
        'code'      => 'USADO',
        'value'     => 10,
        'starts_at' => now()->toDateString(),
        'ends_at'   => now()->addDays(10)->toDateString(),
    ]);

    DB::table('coupons')->where('id', $c->id)->update(['uses_count' => 3]);

    // Borrarlo dejaría pedidos con un descuento que ya nadie puede explicar.
    $this->deleteJson("/v1/admin/marketing/coupons/{$c->id}")->assertStatus(422);
});

/* ---------------------------- DESTACADOS ----------------------------- */

test('rechaza destacar el mismo negocio dos veces en el mismo sitio y periodo', function () {
    comoAdmin();

    $negocio = DB::table('business')->insertGetId([
        'name'          => 'Tienda Test',
        'type'          => null,
        'qualification' => 0,
    ]);

    $datos = [
        'business_id' => $negocio,
        'placement'   => 'home_top',
        'starts_at'   => now()->toDateString(),
        'ends_at'     => now()->addDays(30)->toDateString(),
        'paid_amount' => 150000,
    ];

    $this->postJson('/v1/admin/marketing/featured', $datos)->assertCreated();

    // Se pagaría dos veces por el mismo puesto y el listado lo duplicaría.
    $this->postJson('/v1/admin/marketing/featured', array_merge($datos, [
        'starts_at' => now()->addDays(10)->toDateString(),
        'ends_at'   => now()->addDays(40)->toDateString(),
    ]))->assertStatus(422);

    // Un periodo que NO se pisa sí debe pasar.
    $this->postJson('/v1/admin/marketing/featured', array_merge($datos, [
        'starts_at' => now()->addDays(31)->toDateString(),
        'ends_at'   => now()->addDays(60)->toDateString(),
    ]))->assertCreated();
});

/* -------------------------- NOTIFICACIONES --------------------------- */

test('la vista previa cuenta los usuarios del segmento', function () {
    comoAdmin(); // ya crea un usuario activo con rol 4

    User::factory()->count(3)->create(); // rol 1 por defecto

    // Sin criterios: todos los usuarios activos, incluido el admin.
    $this->postJson('/v1/admin/marketing/push/preview', ['segment' => []])
        ->assertOk()
        ->assertJsonPath('recipients', 4);

    // Filtrando por rol de comprador quedan solo los tres.
    $this->postJson('/v1/admin/marketing/push/preview', ['segment' => ['roles' => [1]]])
        ->assertOk()
        ->assertJsonPath('recipients', 3);
});

test('una notificación enviada no se puede editar ni reenviar', function () {
    comoAdmin();

    $id = $this->postJson('/v1/admin/marketing/push', [
        'title' => 'Aviso',
        'body'  => 'Cuerpo del aviso',
    ])->assertCreated()->json('id');

    $this->postJson("/v1/admin/marketing/push/{$id}/send")
        ->assertOk()
        // Se declara explícitamente que NO llegó a los dispositivos: falta el
        // registro de tokens de push y el panel no debe afirmar lo contrario.
        ->assertJsonPath('delivered', false);

    $this->putJson("/v1/admin/marketing/push/{$id}", ['title' => 'Otro'])
        ->assertStatus(422);

    $this->postJson("/v1/admin/marketing/push/{$id}/send")->assertStatus(422);
});
