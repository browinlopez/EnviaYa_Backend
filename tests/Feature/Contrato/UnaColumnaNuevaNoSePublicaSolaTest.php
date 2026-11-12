<?php

declare(strict_types=1);

use App\Http\Resources\Marketing\BannerResource;
use App\Http\Resources\Sst\DocumentoDelDomiciliarioResource;
use App\Models\Marketing\AdCampaign;
use App\Models\Marketing\Advertiser;
use App\Models\Marketing\Banner;
use App\Models\Operacion\DomiciliaryDocument;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AÑADIR UNA COLUMNA A LA TABLA NO LA PUBLICA EN LA API.
 *
 * Tres respuestas del panel se armaban con `array_merge($modelo->toArray(),
 * [...])`, y eso significa que la forma de la respuesta era «las columnas que
 * tenga la tabla». Una migración que añadiera un campo —una nota interna, un
 * identificador de otro sistema, una marca de proceso— lo convertía en un
 * campo público de la API sin que nadie lo decidiera y sin que nada avisara.
 *
 * Y no era teórico en una de las tres: `domiciliary_documents` guarda los
 * papeles de una persona —licencia, seguro, antecedentes— y es justo la clase
 * de tabla a la que se le añade una columna sin pensar en quién la recibe.
 *
 * ESTA PRUEBA LO COMPRUEBA HACIÉNDOLO: añade una columna de verdad a la tabla
 * en la base de pruebas, pide la forma, y afirma que la columna NO aparece.
 * No mira la lista de campos del Resource —eso sería comprobar que una
 * constante es igual a sí misma— sino el efecto.
 *
 * Las columnas se añaden y se quitan dentro de cada prueba. La suite corre
 * sobre SQLite en memoria, así que no hay nada que limpiar fuera.
 */
function unBannerConCampana(): Banner
{
    $anunciante = Advertiser::create(['name' => 'Panadería del centro', 'state' => 1]);

    $campana = AdCampaign::create([
        'advertiser_id' => $anunciante->id,
        'name' => 'Pan de la tarde',
        'objective' => 'traffic',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(7),
        'state' => 1,
    ]);

    $banner = Banner::create([
        'campaign_id' => $campana->id,
        'title' => 'Pan caliente a las 4',
        // Los valores salen de las reglas de `BannerRequest`: las columnas
        // son `enum` y SQLite las hace cumplir con un CHECK, asi que un
        // 'home' inventado revienta la insercion.
        'placement' => 'home_hero',
        'platform' => 'app',
        'link_type' => 'none',
        'priority' => 1,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(7),
        'state' => 1,
    ]);

    return $banner->load('campaign.advertiser');
}

it('una columna nueva en banners NO sale en la respuesta', function () {
    $banner = unBannerConCampana();

    Schema::table('banners', function ($t) {
        // El nombre lo dice: es la clase de columna que no debe salir.
        $t->string('nota_interna_del_equipo')->nullable();
    });

    DB::table('banners')->where('id', $banner->id)
        ->update(['nota_interna_del_equipo' => 'este cliente paga tarde']);

    $forma = (new BannerResource(
        Banner::with('campaign.advertiser')->find($banner->id),
        [],
    ))->toArray(Request::create('/v1/admin/marketing/banners', 'GET'));

    expect($forma)->not->toHaveKey('nota_interna_del_equipo');

    // Y la contrapartida: la columna SÍ está en la tabla y el modelo la ve.
    // Sin esto, la prueba pasaría si la migración hubiera fallado en silencio.
    expect(Schema::hasColumn('banners', 'nota_interna_del_equipo'))->toBeTrue();
    expect(Banner::find($banner->id)->nota_interna_del_equipo)
        ->toBe('este cliente paga tarde');

    Schema::table('banners', fn ($t) => $t->dropColumn('nota_interna_del_equipo'));
});

it('una columna nueva en los documentos del domiciliario NO sale en la respuesta', function () {
    /*
     * El que más importa de los tres: aquí viven los papeles de una persona.
     */
    Rol::firstOrCreate(['rol_id' => 3], ['name' => 'domiciliario', 'guard_name' => 'web']);

    $repartidor = User::factory()->create(['rol' => 3]);
    $domi = DB::table('domiciliary')->insertGetId([
        'user_id' => $repartidor->user_id, 'available' => 1,
        'qualification' => 0, 'state' => 1,
    ], 'domiciliary_id');

    $doc = DomiciliaryDocument::create([
        'domiciliary_id' => $domi,
        'type' => DomiciliaryDocument::OBLIGATORIOS[0],
        'number' => 'ABC-123',
        'issued_at' => now()->subMonths(2),
        'expires_at' => now()->addMonths(6),
        'state' => 1,
    ]);

    Schema::table('domiciliary_documents', function ($t) {
        $t->string('observacion_privada')->nullable();
    });

    DB::table('domiciliary_documents')->where('id', $doc->id)
        ->update(['observacion_privada' => 'pendiente de verificar con la ARL']);

    $forma = (new DocumentoDelDomiciliarioResource(
        DomiciliaryDocument::find($doc->id),
    ))->toArray(Request::create('/v1/admin/sst/documentos', 'GET'));

    expect($forma)->not->toHaveKey('observacion_privada');

    expect(DomiciliaryDocument::find($doc->id)->observacion_privada)
        ->toBe('pendiente de verificar con la ARL');

    Schema::table('domiciliary_documents', fn ($t) => $t->dropColumn('observacion_privada'));
});

it('y lo que SI tiene que salir sigue saliendo, que es la otra mitad', function () {
    /*
     * Una lista blanca que se quedara corta pasaría las dos pruebas de arriba
     * y rompería el panel. Así que se afirma también el conjunto exacto de
     * claves, sacado de la respuesta real del endpoint antes de migrar.
     */
    $banner = unBannerConCampana();

    $forma = (new BannerResource(
        Banner::with('campaign.advertiser')->find($banner->id),
        [$banner->id => 'https://medios/ejemplo.png'],
    ))->toArray(Request::create('/v1/admin/marketing/banners', 'GET'));

    $claves = array_keys($forma);
    sort($claves);

    expect($claves)->toBe([
        'advertiser_name', 'campaign', 'campaign_id', 'campaign_name',
        'clicks_count', 'created_at', 'ctr', 'ends_at', 'id', 'image_url',
        'impressions_count', 'link_type', 'link_value', 'placement',
        'platform', 'priority', 'starts_at', 'state', 'subtitle',
        'target_business_categories', 'target_complexes',
        'target_municipalities', 'target_roles', 'title', 'updated_at',
    ]);

    // Y que los cuatro calculados llevan valor, no solo la clave.
    expect($forma['image_url'])->toBe('https://medios/ejemplo.png');
    expect($forma['campaign_name'])->toBe('Pan de la tarde');
    expect($forma['advertiser_name'])->toBe('Panadería del centro');
    expect($forma['ctr'])->toBe(0);
});
