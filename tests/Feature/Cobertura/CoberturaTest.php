<?php

use App\Models\Business;
use App\Models\Business\CategoryBusiness;
use App\Models\Buyer\ResidentialComplex;
use App\Models\Maps\Country;
use App\Models\Maps\Department;
use App\Models\Maps\Municipality;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * EL MAPA DE COBERTURA DE LA WEB PÚBLICA
 *
 * Lo que se comprueba acá no es tanto que devuelva datos —eso se ve a simple
 * vista— como que NO devuelva de más: es un endpoint sin autenticación, y lo
 * que se cuele en él lo puede leer cualquiera.
 */

beforeEach(function () {
    // La respuesta se cachea cinco minutos. Sin limpiar, la primera prueba le
    // dejaría su resultado a la siguiente y las demás pasarían por casualidad.
    Cache::flush();

    // La cadena completa: el municipio tiene clave foránea al departamento
    // y este al país. Crear solo el municipio revienta contra la restricción.
    $pais = Country::forceCreate(['name' => 'Colombia', 'iso_code' => 'CO']);
    $departamento = Department::forceCreate([
        'name'       => 'Atlántico',
        'country_id' => $pais->id,
    ]);

    // `business.type` apunta a `category_business`. Son los cuatro tipos
    // reales del producto, en el orden en que los siembra el proyecto.
    foreach (['Tienda', 'Farmacia', 'Restaurante', 'Tienda de repuesto'] as $tipo) {
        CategoryBusiness::forceCreate(['name' => $tipo]);
    }

    $this->municipio = Municipality::forceCreate([
        'name'          => 'Barranquilla',
        'department_id' => $departamento->id,
    ]);
});

/** Crea un negocio con lo justo; los parámetros son lo que cambia por caso. */
function negocioEn(array $atributos = []): Business
{
    return Business::forceCreate(array_merge([
        'name'          => 'Tienda de prueba',
        'address'       => 'Calle 79 #42-15',
        'latitude'      => 10.9985,
        'longitude'     => -74.8032,
        'state'         => 1,
        'type'          => 1,
        'qualification' => 0,
    ], $atributos));
}

test('el mapa se consulta sin cuenta', function () {
    negocioEn(['municipality_id' => $this->municipio->id]);

    $this->getJson('/v1/cobertura-free')->assertOk();
});

test('devuelve los comercios publicados con su punto', function () {
    negocioEn([
        'name'            => 'Droguería Vida Sana',
        'municipality_id' => $this->municipio->id,
        'latitude'        => 11.0071,
        'longitude'       => -74.8121,
        'type'            => 2,
    ]);

    $this->getJson('/v1/cobertura-free')
        ->assertOk()
        ->assertJsonPath('data.0.tipo', 'negocio')
        ->assertJsonPath('data.0.nombre', 'Droguería Vida Sana')
        ->assertJsonPath('data.0.latitude', 11.0071)
        ->assertJsonPath('data.0.longitude', -74.8121)
        ->assertJsonPath('data.0.categoria', 2)
        ->assertJsonPath('data.0.municipio', 'Barranquilla');
});

test('un comercio oculto no sale en el mapa', function () {
    // `state = 0` es "no publicado" en el panel. Si saliera acá, ocultarlo
    // no serviría de nada: seguiría anunciado en la web.
    negocioEn(['name' => 'Panadería Doña Nubia', 'state' => 0]);

    $this->getJson('/v1/cobertura-free')->assertOk()->assertJsonCount(0, 'data');
});

test('un comercio sin coordenadas no sale en el mapa', function () {
    negocioEn(['name' => 'Sin ubicar', 'latitude' => null, 'longitude' => null]);

    $this->getJson('/v1/cobertura-free')->assertOk()->assertJsonCount(0, 'data');
});

test('el (0,0) se descarta como si no hubiera coordenada', function () {
    // Es lo que deja un formulario guardado en blanco, y cae en el Atlántico
    // medio: un pin ahí se ve como un error, porque lo es.
    negocioEn(['name' => 'Cero cero', 'latitude' => 0, 'longitude' => 0]);

    $this->getJson('/v1/cobertura-free')->assertOk()->assertJsonCount(0, 'data');
});

test('incluye los conjuntos activos y omite los inactivos', function () {
    ResidentialComplex::forceCreate([
        'name'         => 'Conjunto Villa Carolina',
        'address'      => 'Calle 79 #42-15',
        'state'        => 1,
        'people_count' => 480,
    ]);
    DB::table('residential_complexes')
        ->where('name', 'Conjunto Villa Carolina')
        ->update([
            'latitude'        => 10.9962,
            'longitude'       => -74.8070,
            'municipality_id' => $this->municipio->id,
        ]);

    ResidentialComplex::forceCreate([
        'name'         => 'Altos de Malambo',
        'state'        => 0,
        'people_count' => 140,
    ]);

    $respuesta = $this->getJson('/v1/cobertura-free')->assertOk();

    $nombres = collect($respuesta->json('data'))->pluck('nombre');
    expect($nombres)->toContain('Conjunto Villa Carolina')
        ->and($nombres)->not->toContain('Altos de Malambo');
});

test('se pueden dejar los conjuntos fuera del mapa', function () {
    // La ubicación de un conjunto dice dónde vive gente. Que se publique es
    // una decisión de producto, y tiene que poder revertirse sin tocar código.
    config()->set('services.cobertura.incluir_conjuntos', false);

    ResidentialComplex::forceCreate(['name' => 'Portal de Alameda', 'state' => 1]);
    DB::table('residential_complexes')
        ->where('name', 'Portal de Alameda')
        ->update([
            'latitude'        => 11.0119,
            'longitude'       => -74.8158,
            'municipality_id' => $this->municipio->id,
        ]);

    $this->getJson('/v1/cobertura-free')->assertOk()->assertJsonCount(0, 'data');
});

test('no publica nada que no haga falta para pintar un pin', function () {
    negocioEn([
        'municipality_id' => $this->municipio->id,
        'NIT'             => '9001234567',
        'phone'           => '3002464966',
    ]);

    ResidentialComplex::forceCreate([
        'name'         => 'Conjunto Miramar',
        'state'        => 1,
        'people_count' => 210,
    ]);
    DB::table('residential_complexes')
        ->where('name', 'Conjunto Miramar')
        ->update([
            'latitude'        => 11.0044,
            'longitude'       => -74.8093,
            'municipality_id' => $this->municipio->id,
        ]);

    $respuesta = $this->getJson('/v1/cobertura-free')->assertOk();

    // Cuántas personas viven en un conjunto es información de negocio, y el
    // teléfono y el NIT del comercio no le hacen falta a un mapa.
    $crudo = $respuesta->getContent();
    expect($crudo)->not->toContain('people_count')
        ->and($crudo)->not->toContain('9001234567')
        ->and($crudo)->not->toContain('3002464966')
        ->and($crudo)->not->toContain('owner');

    $claves = array_keys($respuesta->json('data.0'));
    sort($claves);
    expect($claves)->toBe([
        'categoria',
        'direccion',
        'id',
        'latitude',
        'longitude',
        'municipality_id',
        'municipio',
        'nombre',
        'tipo',
    ]);
});
