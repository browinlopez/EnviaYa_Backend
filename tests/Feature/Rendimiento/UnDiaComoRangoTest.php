<?php

use App\Models\Conjunto\ComplexStaff;
use App\Models\Rol;
use App\Models\User;
use App\Services\PulsoDelConjunto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * UN DÍA SE FILTRA COMO RANGO, NUNCA CON `whereDate`
 *
 * `whereDate('created_at', $dia)` genera `date(created_at) = ?`, y una función
 * sobre la columna filtrada deja el índice inservible para esa parte: MySQL lo
 * usa para `complex_id` y después tiene que abrir TODAS las filas de ese
 * conjunto para calcular `date()` de cada una.
 *
 * Medido sobre `complex_entries` en la base de desarrollo, con el índice
 * `(complex_id, created_at)` ya puesto:
 *
 *   con `date(...)`  ->  type=ref    filas examinadas: 1388
 *   como rango       ->  type=range  filas examinadas: 1
 *
 * `GET /v1/conjunto/resumen` hacía SEIS consultas así —una `whereDate` que se
 * clonaba cinco veces con filtros distintos, más la de pedidos—, y esa tabla
 * crece con cada visitante y cada domiciliario que entra por la portería.
 *
 * EL RIESGO DE ESTE CAMBIO ESTÁ ENTERO EN LOS BORDES, y es lo que se fija
 * aquí: un día va de su medianoche a la siguiente, sin incluirla. Se comprobó
 * además contra la base de desarrollo, sobre los 31 días que tienen entradas:
 * las dos formas dan el mismo número en los 31.
 */
function conjuntoConEntradas(array $momentos): array
{
    $complexId = DB::table('residential_complexes')->insertGetId([
        'name' => 'Los Almendros', 'state' => 1, 'people_count' => 0,
        'towers_count' => 4, 'apartments_per_tower' => 20,
    ], 'complex_id');

    Rol::firstOrCreate(['rol_id' => 5], ['name' => 'dueno_conjunto', 'guard_name' => 'web']);
    $u = User::factory()->create(['rol' => 5]);

    ComplexStaff::create([
        'user_id' => $u->user_id, 'complex_id' => $complexId,
        'role' => ComplexStaff::DUENO, 'state' => true,
    ]);

    foreach ($momentos as $cuando) {
        DB::table('complex_entries')->insert([
            'complex_id' => $complexId,
            'kind' => 'visitante',
            'method' => 'manual',
            'visitor_name' => 'Visita de prueba',
            'created_at' => $cuando,
            'updated_at' => $cuando,
        ]);
    }

    return ['complexId' => (int) $complexId, 'usuario' => $u];
}

it('un dia incluye su medianoche y excluye la siguiente', function () {
    $hoy = CarbonImmutable::today();

    /*
     * LOS CUATRO MOMENTOS QUE IMPORTAN. Dos tienen que contar y dos no, y son
     * exactamente los que un `between` con `endOfDay()` o un `<=` mal puesto
     * se come o se deja fuera.
     */
    $e = conjuntoConEntradas([
        $hoy->subSecond(),                  // 23:59:59 de AYER     -> no
        $hoy,                               // 00:00:00 de hoy      -> sí
        $hoy->addHours(23)->addMinutes(59)->addSeconds(59), // 23:59:59 hoy -> sí
        $hoy->addDay(),                     // 00:00:00 de MAÑANA   -> no
    ]);

    $pulso = app(PulsoDelConjunto::class);
    $resumen = $pulso->resumen($e['complexId']);

    expect($resumen['hoy']['entradas'])->toBe(2);
});

it('da el mismo numero que `whereDate`, que es lo que sustituye', function () {
    $hoy = CarbonImmutable::today();

    $e = conjuntoConEntradas([
        $hoy->subSecond(),
        $hoy,
        $hoy->addHours(8),
        $hoy->addHours(23)->addMinutes(59)->addSeconds(59),
        $hoy->addDay(),
        $hoy->addDays(2),
    ]);

    /*
     * La forma vieja, escrita a mano aquí: es la referencia. Si el rango deja
     * de coincidir con ella, esta prueba lo dice — y es el único sitio donde
     * `whereDate` debe seguir apareciendo en este repositorio.
     */
    $comoAntes = DB::table('complex_entries')
        ->where('complex_id', $e['complexId'])
        ->whereDate('created_at', $hoy->toDateString())
        ->count();

    $resumen = app(PulsoDelConjunto::class)->resumen($e['complexId']);

    expect($resumen['hoy']['entradas'])->toBe($comoAntes);

    // Y que no sea cero: dos ceros iguales no son una medida.
    expect($comoAntes)->toBeGreaterThan(0);
});

it('el filtro del dia ya puede usar el indice', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped(
            'Un plan de ejecución sobre SQLite no dice nada de MySQL: §25 lo '
            .'advierte. Corre en el flujo de MySQL.'
        );
    }

    $hoy = CarbonImmutable::today();
    $e = conjuntoConEntradas([$hoy, $hoy->addHours(5)]);

    $plan = fn (string $sql, array $b) => DB::select('explain '.$sql, $b)[0];

    $conFuncion = $plan(
        'select count(*) from complex_entries where complex_id = ? and date(created_at) = ?',
        [$e['complexId'], $hoy->toDateString()]
    );

    $comoRango = $plan(
        'select count(*) from complex_entries where complex_id = ? '
        .'and created_at >= ? and created_at < ?',
        [$e['complexId'], $hoy->startOfDay(), $hoy->addDay()->startOfDay()]
    );

    /*
     * `type` y no `rows`: el número de filas examinadas depende del volumen y
     * en una base de pruebas casi vacía no distingue nada. Lo que distingue a
     * cualquier tamaño es CÓMO entra: `range` recorre un tramo del índice;
     * `ref` se queda en la igualdad de `complex_id` y abre el resto.
     */
    expect($comoRango->type)->toBe('range');
    expect($conFuncion->type)->not->toBe('range');
});

it('la pantalla del conjunto sigue devolviendo lo mismo', function () {
    $hoy = CarbonImmutable::today();
    $e = conjuntoConEntradas([$hoy, $hoy->addHours(3), $hoy->subDay()]);

    Sanctum::actingAs($e['usuario']);

    $r = $this->getJson('/v1/conjunto/resumen')->assertOk();

    // Las claves del bloque de hoy, que es lo que lee la pantalla.
    expect(array_keys($r->json('hoy')))->toContain('pedidos', 'entradas');
    expect($r->json('hoy.entradas'))->toBe(2);
});

it('el pulso del conjunto no vuelve a usar whereDate', function () {
    /*
     * UN GUARDIA SOBRE EL CÓDIGO, Y HACE FALTA.
     *
     * Las pruebas de arriba fijan la SEMÁNTICA —que el rango cuenta lo mismo
     * que `whereDate` contaba— y eso es justo lo que las hace ciegas a una
     * vuelta atrás: en SQLite las dos formas dan el mismo número, así que
     * devolver `whereDate` no pondría roja ninguna. Lo que se perdería es el
     * índice, y eso solo se ve en el plan de ejecución de MySQL, que aquí se
     * salta.
     *
     * Así que este mira el código. Es crudo y lo sabe: no entiende de qué
     * tabla habla cada consulta, solo vigila el fichero donde se midió el
     * problema. No es una regla para todo el repositorio —hay 43 `whereDate`
     * en 20 archivos y la mayoría están sobre tablas pequeñas, donde da
     * igual— sino una red donde ya se pagó por aprenderlo.
     */
    $fuente = file_get_contents(app_path('Services/PulsoDelConjunto.php'));

    $codigo = '';
    foreach (token_get_all($fuente) as $pieza) {
        if (is_array($pieza) && in_array($pieza[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $codigo .= is_array($pieza) ? $pieza[1] : $pieza;
    }

    expect($codigo)->not->toContain('whereDate(');
});
