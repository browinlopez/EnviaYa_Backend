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

it('ANTES DE no incluye el dia, que es como se pregunta si algo vencio', function () {
    $dia = CarbonImmutable::parse('2026-11-05');

    $t = conFechas([
        $dia->subDay()->addHours(10),   // el 4           -> entra
        $dia->subSecond(),              // el 4 al final  -> entra
        $dia,                           // el 5 a las 00  -> NO
        $dia->addHours(15),             // el 5           -> no
    ]);

    $n = FiltroDeFechas::antesDe(DB::table($t), 'cuando', '2026-11-05')->count();

    expect($n)->toBe(2);
    expect($n)->toBe(
        DB::table($t)->whereDate('cuando', '<', '2026-11-05')->count()
    );
});

/*
 * LOS ANIOS DE ESTAS PRUEBAS NO SON EL ACTUAL, Y ESO ES DELIBERADO.
 *
 * La primera version usaba 2026, que es el anio en curso. Al probar la red
 * rompiendola —cambiando `$anio` por `now()->year` dentro de `elMes()`— NO se
 * puso roja ninguna: el parametro y el valor por omision coincidian por
 * casualidad del calendario, asi que la prueba no estaba comprobando que el
 * parametro se usara. Habria empezado a fallar sola el 1 de enero.
 *
 * Con 2031 y 2030, usar el anio actual en vez del parametro da cero filas y
 * la prueba lo dice.
 */
it('EL ANIO va del 1 de enero al 1 de enero siguiente', function () {
    $t = conFechas([
        '2030-12-31 23:59:59',  // el ultimo instante del anterior: fuera
        '2031-01-01 00:00:00',  // el primero del bueno: dentro
        '2031-07-15 12:00:00',  // en medio: dentro
        '2031-12-31 23:59:59',  // el ultimo del bueno: dentro
        '2032-01-01 00:00:00',  // el primero del siguiente: fuera
    ]);

    expect(FiltroDeFechas::elAnio(DB::table($t), 'cuando', 2031)->count())->toBe(3);

    // Y da LO MISMO que la forma vieja, que es lo que autoriza el cambio: no
    // se esta arreglando un numero, se esta quitando una funcion de encima de
    // la columna.
    expect(FiltroDeFechas::elAnio(DB::table($t), 'cuando', 2031)->count())
        ->toBe(DB::table($t)->whereYear('cuando', 2031)->count());
});

it('EL MES exige el anio, y ahi esta la trampa que tenia el codigo viejo', function () {
    /*
     * LA PRUEBA QUE JUSTIFICA LA FIRMA DEL METODO.
     *
     * `whereMonth($col, 11)` a secas no es «noviembre de este anio»: es
     * noviembre de TODOS los anios. Los cuatro controladores que se
     * convirtieron lo sabian y escribian `whereMonth(...)` y `whereYear(...)`
     * en dos lineas seguidas — dos lineas que hay que acordarse de poner
     * juntas, y la que falta no da error: da un numero mas grande, y crece un
     * anio cada enero.
     *
     * `elMes()` pide el anio como parametro para que no se pueda olvidar.
     */
    $t = conFechas([
        '2030-11-10 10:00:00',  // noviembre, pero de otro anio
        '2031-10-31 23:59:59',  // el ultimo instante de octubre: fuera
        '2031-11-01 00:00:00',  // dentro
        '2031-11-30 23:59:59',  // dentro
        '2031-12-01 00:00:00',  // el primero de diciembre: fuera
    ]);

    expect(FiltroDeFechas::elMes(DB::table($t), 'cuando', 2031, 11)->count())->toBe(2);

    // La forma vieja HECHA BIEN —mes y anio— da lo mismo.
    expect(FiltroDeFechas::elMes(DB::table($t), 'cuando', 2031, 11)->count())
        ->toBe(DB::table($t)->whereMonth('cuando', 11)->whereYear('cuando', 2031)->count());

    /*
     * Y la forma vieja HECHA MAL —solo el mes— da DOS de un anio que no es el
     * pedido mas el del pedido: tres. Esto no prueba `elMes`; prueba por que
     * `elMes` no acepta un mes sin su anio. Si alguien le pusiera un valor por
     * omision, este numero seria el que acabaria en el panel.
     */
    expect(DB::table($t)->whereMonth('cuando', 11)->count())->toBe(3);
});

it('diciembre no se sale del anio al sumar un mes', function () {
    /*
     * El borde que un `addMonth()` mal escrito se come: el mes 12 tiene que
     * acabar el 1 de enero del anio SIGUIENTE, no volver al 1 de enero del
     * mismo.
     */
    $t = conFechas([
        '2031-11-30 23:59:59',
        '2031-12-01 00:00:00',
        '2031-12-31 23:59:59',
        '2032-01-01 00:00:00',
    ]);

    expect(FiltroDeFechas::elMes(DB::table($t), 'cuando', 2031, 12)->count())->toBe(2);
});

it('en app/ no queda NINGUNA de las funciones de fecha, y este guardia lo mantiene', function () {
    /*
     * EL PATRON SIN EXCEPCIONES, y es el motivo de haber convertido tambien
     * los sitios donde hoy no se nota.
     *
     * Mientras quedara un `whereDate` «de los que dan igual», cada vez que
     * alguien escribiera uno nuevo habria que decidir si el suyo es de los que
     * importan — y esa decision se toma mal, porque el coste no se ve hasta
     * que la tabla crece. Con cero, la regla es una: los dias se filtran con
     * `FiltroDeFechas`.
     *
     * Se descartan los comentarios: varios EXPLICAN la forma vieja —incluida
     * esta prueba citandola arriba— y un detector que confunde la prosa con el
     * codigo castiga el haberlo dejado dicho. Es la misma leccion que el
     * guardia de las direcciones de correo de pega.
     *
     * VIGILA LA FAMILIA ENTERA, y no solo `whereDate`. Al principio solo
     * miraba `whereDate` y por eso una auditoria posterior encontro SIETE
     * `whereYear`/`whereMonth` vivos en cuatro controladores del panel: el
     * mismo `año(columna) = ?` sobre la columna filtrada, el mismo indice
     * inservible, y pasando en verde porque el guardia miraba el nombre de
     * una de las cuatro funciones en vez del patron.
     *
     * Un guardia que cubre una variante de un patron da la sensacion de
     * cubrirlo entero. `whereMonth` es ademas la mas peligrosa de las cuatro:
     * a secas significa «ese mes de TODOS los años».
     */
    $prohibidas = ['whereDate(', 'whereYear(', 'whereMonth(', 'whereDay(', 'whereTime('];
    $conWhereDate = [];

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($it as $fichero) {
        if ($fichero->getExtension() !== 'php') {
            continue;
        }

        $codigo = '';

        foreach (token_get_all(file_get_contents($fichero->getPathname())) as $pieza) {
            if (is_array($pieza) && in_array($pieza[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $codigo .= is_array($pieza) ? $pieza[1] : $pieza;
        }

        foreach ($prohibidas as $prohibida) {
            if (str_contains($codigo, $prohibida)) {
                $conWhereDate[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $fichero->getPathname())
                    .' -> '.$prohibida;
            }
        }
    }

    expect($conWhereDate)->toBe([]);
});

it('el guardia encuentra un whereDate cuando lo hay', function () {
    /*
     * LA PASADA DE CONTROL del guardia de arriba. Sin esto, el dia que el
     * recorrido deje de funcionar —una ruta mal construida, una extension
     * distinta— pasaria en verde sobre una lista vacia y diria que todo esta
     * bien. Un detector que no encuentra nada no es una prueba de que no haya
     * nada.
     */
    $falso = sys_get_temp_dir().'/guardia_'.uniqid().'.php';
    file_put_contents($falso, "<?php\n\$q->whereDate('x', now());\n");

    $codigo = '';
    foreach (token_get_all(file_get_contents($falso)) as $pieza) {
        if (is_array($pieza) && in_array($pieza[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $codigo .= is_array($pieza) ? $pieza[1] : $pieza;
    }

    expect(str_contains($codigo, 'whereDate('))->toBeTrue();

    // Y que SI descarte los comentarios, que es la otra mitad del criterio.
    file_put_contents($falso, "<?php\n// esto decia \$q->whereDate('x', now());\n");

    $soloComentario = '';
    foreach (token_get_all(file_get_contents($falso)) as $pieza) {
        if (is_array($pieza) && in_array($pieza[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $soloComentario .= is_array($pieza) ? $pieza[1] : $pieza;
    }

    expect(str_contains($soloComentario, 'whereDate('))->toBeFalse();

    @unlink($falso);
});
