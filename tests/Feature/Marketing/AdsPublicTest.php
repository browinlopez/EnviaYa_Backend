<?php

use App\Models\Marketing\AdCampaign;
use App\Models\Marketing\Advertiser;
use App\Models\Marketing\Banner;
use App\Models\Marketing\Coupon;
use App\Models\Marketing\FeaturedBusiness;
use Illuminate\Support\Facades\DB;

/**
 * La superficie pública de publicidad: lo que consultan la app y la web.
 *
 * Se prueba acá y no en el panel porque es la parte que se equivoca en
 * silencio: un banner mal segmentado no lanza ningún error, simplemente le
 * aparece a quien no debía o no le aparece a quien pagó por verlo.
 */

/** Campaña vigente con un banner encendido, que es el escenario base. */
function bannerDePrueba(array $atributos = []): Banner
{
    $anunciante = Advertiser::create(['name' => 'Marca de prueba']);

    $campana = AdCampaign::create([
        'advertiser_id' => $anunciante->id,
        'name'          => 'Campaña vigente',
        'starts_at'     => now()->subDays(5)->toDateString(),
        'ends_at'       => now()->addDays(5)->toDateString(),
        'state'         => AdCampaign::ACTIVA,
    ]);

    return Banner::create(array_merge([
        'campaign_id' => $campana->id,
        'title'       => 'Banner de prueba',
        'placement'   => 'home_hero',
        'platform'    => 'both',
        'state'       => 1,
    ], $atributos));
}

test('entrega los banners vigentes del sitio pedido', function () {
    $banner = bannerDePrueba();

    $this->getJson('/v1/ads/banners?placement=home_hero&platform=app')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $banner->id)
        ->assertJsonPath('0.title', 'Banner de prueba');
});

test('no entrega banners de una campaña que no está activa', function () {
    $banner = bannerDePrueba();
    AdCampaign::where('id', $banner->campaign_id)->update(['state' => AdCampaign::PAUSADA]);

    $this->getJson('/v1/ads/banners?placement=home_hero')
        ->assertOk()
        ->assertJsonCount(0);
});

test('no entrega banners cuya vigencia propia ya venció', function () {
    bannerDePrueba([
        'starts_at' => now()->subDays(10)->toDateString(),
        'ends_at'   => now()->subDay()->toDateString(),
    ]);

    $this->getJson('/v1/ads/banners?placement=home_hero')
        ->assertOk()
        ->assertJsonCount(0);
});

test('un banner de otra plataforma no se entrega', function () {
    bannerDePrueba(['platform' => 'web']);

    $this->getJson('/v1/ads/banners?placement=home_hero&platform=app')
        ->assertOk()
        ->assertJsonCount(0);

    $this->getJson('/v1/ads/banners?placement=home_hero&platform=web')
        ->assertOk()
        ->assertJsonCount(1);
});

test('la segmentación por municipio excluye a quien no coincide', function () {
    bannerDePrueba(['target_municipalities' => [7]]);

    // Municipio distinto: no aplica.
    $this->getJson('/v1/ads/banners?placement=home_hero&municipality_id=9')
        ->assertOk()
        ->assertJsonCount(0);

    // El municipio pactado: sí aplica.
    $this->getJson('/v1/ads/banners?placement=home_hero&municipality_id=7')
        ->assertOk()
        ->assertJsonCount(1);
});

test('con segmentación declarada y sin dato del cliente no se entrega', function () {
    // El anunciante pagó por un municipio concreto; sin saber dónde está quien
    // mira, mostrarlo sería cobrarle una impresión que no pidió.
    bannerDePrueba(['target_municipalities' => [7]]);

    $this->getJson('/v1/ads/banners?placement=home_hero')
        ->assertOk()
        ->assertJsonCount(0);
});

test('un banner sin segmentación se entrega a cualquiera', function () {
    bannerDePrueba();

    $this->getJson('/v1/ads/banners?placement=home_hero&municipality_id=999')
        ->assertOk()
        ->assertJsonCount(1);
});

test('respeta la prioridad al ordenar', function () {
    $bajo = bannerDePrueba(['title' => 'Bajo', 'priority' => 1]);
    $alto = bannerDePrueba(['title' => 'Alto', 'priority' => 50]);

    $this->getJson('/v1/ads/banners?placement=home_hero')
        ->assertOk()
        ->assertJsonPath('0.id', $alto->id)
        ->assertJsonPath('1.id', $bajo->id);
});

test('registra impresiones y clics y sube los contadores', function () {
    $banner = bannerDePrueba();

    $this->postJson("/v1/ads/banners/{$banner->id}/track", ['type' => 'impression'])
        ->assertNoContent();
    $this->postJson("/v1/ads/banners/{$banner->id}/track", ['type' => 'impression'])
        ->assertNoContent();
    $this->postJson("/v1/ads/banners/{$banner->id}/track", ['type' => 'click'])
        ->assertNoContent();

    $banner->refresh();

    expect($banner->impressions_count)->toBe(2)
        ->and($banner->clicks_count)->toBe(1);

    expect(DB::table('banner_events')->where('banner_id', $banner->id)->count())->toBe(3);
});

test('rastrear un banner inexistente no revienta', function () {
    // Pasa cuando el banner se apaga mientras el cliente lo tenía en pantalla.
    $this->postJson('/v1/ads/banners/99999/track', ['type' => 'impression'])
        ->assertNoContent();
});

/* ------------------------- NEGOCIOS DESTACADOS ------------------------ */

test('entrega los negocios destacados vigentes', function () {
    /*
     * Este caso existe por un fallo real: el scope `vigente()` filtraba por
     * `state` sin calificar la tabla, y como la consulta une `business` —que
     * también tiene `state`— MySQL respondía "Column 'state' in where clause
     * is ambiguous" y el endpoint entero se caía. No se había detectado porque
     * ninguna prueba lo ejercitaba CON un negocio unido de verdad.
     */
    $negocio = DB::table('business')->insertGetId([
        'name'          => 'Tienda Destacada',
        'qualification' => 0,
        'state'         => 1,
    ]);

    FeaturedBusiness::create([
        'business_id' => $negocio,
        'placement'   => 'home_top',
        'priority'    => 5,
        'starts_at'   => now()->subDay()->toDateString(),
        'ends_at'     => now()->addDays(10)->toDateString(),
        'paid_amount' => 150000,
    ]);

    $this->getJson('/v1/ads/featured?placement=home_top')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.name', 'Tienda Destacada');
});

test('no entrega un destaque cuyo negocio está inactivo', function () {
    // El destaque compra posición, no visibilidad de algo cerrado.
    $negocio = DB::table('business')->insertGetId([
        'name'          => 'Tienda Cerrada',
        'qualification' => 0,
        'state'         => 0,
    ]);

    FeaturedBusiness::create([
        'business_id' => $negocio,
        'placement'   => 'home_top',
        'starts_at'   => now()->subDay()->toDateString(),
        'ends_at'     => now()->addDays(10)->toDateString(),
    ]);

    $this->getJson('/v1/ads/featured?placement=home_top')
        ->assertOk()
        ->assertJsonCount(0);
});

test('no entrega un destaque fuera de su periodo', function () {
    $negocio = DB::table('business')->insertGetId([
        'name'          => 'Tienda Vencida',
        'qualification' => 0,
        'state'         => 1,
    ]);

    FeaturedBusiness::create([
        'business_id' => $negocio,
        'placement'   => 'home_top',
        'starts_at'   => now()->subDays(30)->toDateString(),
        'ends_at'     => now()->subDay()->toDateString(),
    ]);

    $this->getJson('/v1/ads/featured?placement=home_top')
        ->assertOk()
        ->assertJsonCount(0);
});

/* ---------------------------------------------------------------------- */

test('valida un cupón porcentual y devuelve el descuento', function () {
    Coupon::create([
        'code'      => 'verano25',
        'type'      => 'percent',
        'value'     => 25,
        'starts_at' => now()->subDay()->toDateString(),
        'ends_at'   => now()->addDay()->toDateString(),
    ]);

    // Se envía en minúscula a propósito: el usuario lo escribe como quiere.
    $this->postJson('/v1/ads/coupons/validate', ['code' => 'verano25', 'subtotal' => 20000])
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('discount', 5000)
        ->assertJsonPath('total', 15000);
});

test('el tope máximo limita el descuento porcentual', function () {
    Coupon::create([
        'code'         => 'TOPE',
        'type'         => 'percent',
        'value'        => 50,
        'max_discount' => 3000,
        'starts_at'    => now()->subDay()->toDateString(),
        'ends_at'      => now()->addDay()->toDateString(),
    ]);

    $this->postJson('/v1/ads/coupons/validate', ['code' => 'TOPE', 'subtotal' => 40000])
        ->assertOk()
        ->assertJsonPath('discount', 3000);
});

test('un cupón fijo nunca deja el total en negativo', function () {
    Coupon::create([
        'code'      => 'FIJO',
        'type'      => 'fixed',
        'value'     => 10000,
        'starts_at' => now()->subDay()->toDateString(),
        'ends_at'   => now()->addDay()->toDateString(),
    ]);

    $this->postJson('/v1/ads/coupons/validate', ['code' => 'FIJO', 'subtotal' => 8000])
        ->assertOk()
        ->assertJsonPath('discount', 8000)
        ->assertJsonPath('total', 0);
});

test('rechaza un cupón vencido', function () {
    Coupon::create([
        'code'      => 'VIEJO',
        'type'      => 'percent',
        'value'     => 10,
        'starts_at' => now()->subDays(10)->toDateString(),
        'ends_at'   => now()->subDays(2)->toDateString(),
    ]);

    $this->postJson('/v1/ads/coupons/validate', ['code' => 'VIEJO', 'subtotal' => 20000])
        ->assertOk()
        ->assertJsonPath('valid', false);
});

test('rechaza un cupón agotado', function () {
    $c = Coupon::create([
        'code'      => 'AGOTADO',
        'type'      => 'percent',
        'value'     => 10,
        'max_uses'  => 2,
        'starts_at' => now()->subDay()->toDateString(),
        'ends_at'   => now()->addDay()->toDateString(),
    ]);
    // Se actualiza por la tabla y no con `update()`: `uses_count` queda fuera
    // de $fillable a propósito, porque solo debe moverlo el canje real. Que
    // esta línea sea incómoda es justamente la señal de que la protección está.
    DB::table('coupons')->where('id', $c->id)->update(['uses_count' => 2]);

    $this->postJson('/v1/ads/coupons/validate', ['code' => 'AGOTADO', 'subtotal' => 20000])
        ->assertOk()
        ->assertJsonPath('valid', false);
});

test('respeta el pedido mínimo', function () {
    Coupon::create([
        'code'      => 'MINIMO',
        'type'      => 'fixed',
        'value'     => 2000,
        'min_order' => 30000,
        'starts_at' => now()->subDay()->toDateString(),
        'ends_at'   => now()->addDay()->toDateString(),
    ]);

    $this->postJson('/v1/ads/coupons/validate', ['code' => 'MINIMO', 'subtotal' => 10000])
        ->assertOk()
        ->assertJsonPath('valid', false);

    $this->postJson('/v1/ads/coupons/validate', ['code' => 'MINIMO', 'subtotal' => 30000])
        ->assertOk()
        ->assertJsonPath('valid', true);
});
