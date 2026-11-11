<?php

use App\Support\FiltroDeFechas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * FILTRAR POR DÍAS SIN ENVOLVER LA COLUMNA EN UNA FUNCIÓN
 *
 * `whereDate('created_at', $dia)` genera `date(created_at) = ?`, y eso deja el
 * índice inservible para esa parte: el motor abre cada fila candidata para
 * calcular `date()`. Medido con `EXPLAIN` sobre la base de desarrollo, en las
 * dos tablas donde dolía:
 *
 *   complex_entries, un día      con date(): ref, 1388 filas  ->  range, 1
 *   orderssales, ventas de hoy   con date(): ref,  507 filas  ->  range, 21
 *
 * Y las dos crecen para siempre: una con cada visitante de la portería, la
 * otra con cada pedido de la tienda.
 *
 * ESTO SE PRUEBA EN UN SITIO Y NO EN CADA CONTROLADOR porque lo que hay que
 * fijar es el criterio de los BORDES, y porque `hasta` tiene una trampa que
 * una reescritura apresurada se come en silencio. Ahí va la prueba que más
 * vale de este archivo.
 */
function conFechas(array $momentos): string
{
    $tabla = 'filtro_de_fechas_prueba';

    Schema::dropIfExists($tabla);
    Schema::create($tabla, function ($t) {
        $t->id();
        $t->dateTime('cuando')->nullable();
    });

    foreach ($momentos as $m) {
        DB::table($tabla)->insert(['cuando' => $m]);
    }

    return $tabla;
}

it('un dia va de su medianoche a la siguiente, sin incluirla', function () {
    $hoy = CarbonImmutable::today();

    $t = conFechas([
        $hoy->subSecond(),                                   // 23:59:59 de ayer
        $hoy,                                                // 00:00:00 de hoy
        $hoy->addHours(13),                                  // mediodía
        $hoy->addHours(23)->addMinutes(59)->addSeconds(59),  // 23:59:59 de hoy
        $hoy->addDay(),                                      // 00:00:00 de mañana
    ]);

    $n = FiltroDeFechas::unDia(DB::table($t), 'cuando', $hoy)->count();

    expect($n)->toBe(3);

    // Y exactamente lo mismo que decía la forma vieja, que es lo que sustituye.
    $comoAntes = DB::table($t)->whereDate('cuando', $hoy->toDateString())->count();
    expect($n)->toBe($comoAntes);
});

it('HASTA incluye el dia entero, que es donde se pierde un dia sin avisar', function () {
    /*
     * LA PRUEBA QUE JUSTIFICA LA PIEZA.
     *
     * `whereDate('cuando', '<=', '2026-11-05')` incluye todo el día 5, hasta
     * las 23:59:59. La traducción que se escribe sin pensar —`<= '2026-11-05'`
     * a secas— lo convierte en «hasta las 00:00:00 del 5» y se queda con un
     * solo segundo de ese día.
     *
     * Y no da error: da un número más pequeño. Un filtro de fechas que calla
     * un día de movimientos de portería o de ventas no se parece a un fallo,
     * se parece a un día flojo.
     */
    $dia = CarbonImmutable::parse('2026-11-05');

    $t = conFechas([
        $dia->subDay()->addHours(10),   // el 4           -> entra
        $dia,                           // el 5 a las 00  -> entra
        $dia->addHours(15),             // el 5 por la tarde -> ENTRA
        $dia->addHours(23)->addMinutes(59)->addSeconds(59), // el 5 al final -> ENTRA
        $dia->addDay(),                 // el 6 a las 00  -> no
    ]);

    $n = FiltroDeFechas::hasta(DB::table($t), 'cuando', '2026-11-05')->count();

    expect($n)->toBe(4);

    // La forma vieja, de referencia.
    expect($n)->toBe(
        DB::table($t)->whereDate('cuando', '<=', '2026-11-05')->count()
    );

    /*
     * Y la traducción ingenua, escrita aquí para que se vea el tamaño del
     * error. No es una afirmación sobre el código de producción —ahí ya no
     * existe— sino sobre por qué esta clase hace falta.
     *
     * SE AFIRMA «pierde filas» Y NO UN NÚMERO, a propósito: cuántas pierde
     * depende del motor. En MySQL la comparación es de `datetime` y se queda
     * con las de antes del día 5 más la de las 00:00:00; en SQLite es LÉXICA
     * —'2026-11-05 00:00:00' es mayor que '2026-11-05' por ser más larga— y
     * pierde también esa. Fijar un número aquí ataría la prueba al motor y la
     * haría roja en el flujo de MySQL por el motivo equivocado.
     */
    $ingenua = DB::table($t)->where('cuando', '<=', '2026-11-05')->count();
    expect($ingenua)->toBeLessThan($n);
});

it('DESDE si es la traduccion directa', function () {
    $dia = CarbonImmutable::parse('2026-11-05');

    $t = conFechas([
        $dia->subSecond(),      // el 4 al final   -> no
        $dia,                   // el 5 a las 00   -> sí
        $dia->addHours(20),     // el 5 de noche   -> sí
        $dia->addDay(),         // el 6            -> sí
    ]);

    $n = FiltroDeFechas::desde(DB::table($t), 'cuando', '2026-11-05')->count();

    expect($n)->toBe(3);
    expect($n)->toBe(
        DB::table($t)->whereDate('cuando', '>=', '2026-11-05')->count()
    );
});

it('acepta el dia como texto o como fecha, que es como llegan', function () {
    $hoy = CarbonImmutable::today();
    $t = conFechas([$hoy->addHours(10), $hoy->addDay()->addHours(10)]);

    /*
     * Los filtros de las pantallas llegan como `'2026-11-05'` y el código de
     * dentro usa Carbon. Normalizarlo en un solo sitio es lo que impide que
     * alguien compare un texto contra un `datetime` en un controlador.
     */
    $conTexto = FiltroDeFechas::unDia(DB::table($t), 'cuando', $hoy->toDateString())->count();
    $conCarbon = FiltroDeFechas::unDia(DB::table($t), 'cuando', $hoy)->count();
    $conNow = FiltroDeFechas::unDia(DB::table($t), 'cuando', now())->count();

    expect($conTexto)->toBe(1);
    expect($conCarbon)->toBe(1);
    expect($conNow)->toBe(1);
});

it('la hora del dia no se cuela en el filtro', function () {
    /*
     * El caso que un `startOfDay()` olvidado deja pasar: si se filtra «hoy»
     * usando `now()` tal cual —a media tarde— se perdería toda la mañana. La
     * pieza normaliza a medianoche, y esto lo fija.
     */
    $hoy = CarbonImmutable::today();
    $t = conFechas([$hoy->addHours(2), $hoy->addHours(22)]);

    $mediaTarde = $hoy->addHours(16);

    expect(FiltroDeFechas::unDia(DB::table($t), 'cuando', $mediaTarde)->count())
        ->toBe(2);
});
