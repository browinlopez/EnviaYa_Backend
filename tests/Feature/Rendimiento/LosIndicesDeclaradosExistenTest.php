<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * TODO ÍNDICE QUE UNA MIGRACIÓN CREA, EXISTE DESPUÉS DE MIGRAR.
 *
 * ESTO CIERRA LA MITAD DE UN HUECO CONOCIDO. Nueve de las doce pruebas
 * saltadas de la suite son de `EXPLAIN` y de `for update`, y se saltan con
 * razón: §25 avisa de que un plan de ejecución sobre SQLite no dice nada de
 * MySQL. Pero el hueco que eso deja no es «no sabemos si el plan es bueno»,
 * sino algo más básico y más tonto: **no sabemos si los índices están**. Decir
 * «1333 pruebas en verde» sin esta distinción es decir de más.
 *
 * Y las dos mitades se comprueban en sitios distintos:
 *
 *   que el índice EXISTA           cualquier motor, en cada corrida — acá
 *   que el planificador LO USE     solo MySQL, en `pruebas-mysql.yml` —
 *                                  `PlanDeLasConsultasCalientesTest`
 *
 * La regresión que esto caza y la otra no: un `dropIndex` suelto en una
 * migración nueva, o un `up()` que se queda a medias. En local, hoy, eso pasa
 * sin que nada chille, porque el flujo de MySQL corre aparte. Lo que
 * `possible_keys` caza y esto no es la pérdida de sargabilidad —envolver la
 * columna en una función—, que no cambia el esquema.
 *
 * LA LISTA NO SE ESCRIBE A MANO, Y ES LO IMPORTANTE DEL ARCHIVO. Se lee de
 * las migraciones. Una lista copiada aquí sería una segunda fuente de verdad
 * sobre los índices del proyecto, y la que se olvide de actualizar será la
 * que mande: exactamente el defecto contra el que existe
 * `LasTresListasDeEntidadesVanJuntasTest`.
 *
 * SOLO SE LEE EL `up()`. El `down()` de
 * `2026_08_28_090200_retirar_indices_redundantes` CREA dos índices —es el
 * camino de vuelta de una retirada—, así que mirar el fichero entero exigiría
 * que existieran justo los dos que se quitaron a propósito.
 *
 * Y SE SALTAN LAS MIGRACIONES QUE SOLO ACTÚAN EN MySQL. Esa misma lleva un
 * `if (DB::getDriverName() !== 'mysql') return;` arriba, porque esos índices
 * en SQLite nunca existieron: los crea MySQL solo al declarar la foránea.
 */

/**
 * Los índices con nombre que el `up()` de las migraciones deja PUESTOS.
 *
 * NO basta con recoger las creaciones, y esto costó un tropiezo: la primera
 * versión de este archivo habría puesto en rojo el flujo de MySQL.
 * `ix_pb_negocio` se crea en `2026_08_23_100100` y una migración posterior,
 * `2026_08_28_090200_retirar_indices_redundantes`, LO QUITA — porque duplicaba
 * el prefijo de otro compuesto. Exigir que esté es exigir que la retirada no
 * se haya hecho.
 *
 * Así que esto no hace una lista: REPRODUCE las migraciones en orden, poniendo
 * y quitando, y devuelve lo que queda al final. Y el filtro de motor se aplica
 * al reproducir, no al leer: esa misma migración solo actúa en MySQL —en
 * SQLite esos índices nunca existieron, los creaba MySQL al declarar la
 * foránea— así que en SQLite su retirada no cuenta y el índice sí tiene que
 * estar. Las dos cosas son verdad, cada una en su motor.
 *
 * @return array<string, string> "tabla.indice" => migración que lo puso
 */
function indicesDeclaradosEnLasMigraciones(string $motor): array
{
    $puestos = [];

    // `glob()` ya devuelve en orden alfabético, que en Laravel es el orden en
    // que se aplican: el nombre empieza por la fecha.
    foreach (glob(base_path('database/migrations/*.php')) as $ruta) {
        $fuente = file_get_contents($ruta);
        $corto = basename($ruta);

        $desde = strpos($fuente, 'function up(');

        if ($desde === false) {
            continue;
        }

        // Hasta el `down()`, o hasta el final si no hay. El `down()` de la
        // migración de retirada CREA los dos índices que su `up()` quita.
        $hasta = strpos($fuente, 'function down(');
        $arriba = substr($fuente, $desde, $hasta === false ? null : $hasta - $desde);

        $soloMysql = str_contains($arriba, "!== 'mysql'")
            || str_contains($arriba, '!== "mysql"');

        if ($soloMysql && $motor !== 'mysql') {
            continue;
        }

        /*
         * Cada `Schema::table('x', ...)` o `Schema::create('x', ...)` abre un
         * tramo, y los índices que vengan detrás son de ESA tabla hasta el
         * siguiente `Schema::`. Partir por ahí es más fiable que emparejar
         * llaves con una expresión regular.
         */
        $tramos = preg_split(
            "~Schema::(?:table|create)\(\s*'([a-z_0-9]+)'~",
            $arriba,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        // El primer tramo es lo que va ANTES del primer `Schema::`: sin tabla.
        for ($i = 1; $i < count($tramos); $i += 2) {
            $tabla = $tramos[$i];
            $cuerpo = $tramos[$i + 1] ?? '';

            preg_match_all(
                "~->(?:index|unique)\(\s*(?:\[[^\]]*\]|'[^']*')\s*,\s*'([a-z_0-9]+)'\s*\)~",
                $cuerpo,
                $m
            );

            foreach ($m[1] as $indice) {
                $puestos[$tabla.'.'.$indice] = $corto;
            }

            preg_match_all("~->drop(?:Index|Unique)\(\s*'([a-z_0-9]+)'\s*\)~", $cuerpo, $q);

            foreach ($q[1] as $indice) {
                unset($puestos[$tabla.'.'.$indice]);
            }
        }

        /*
         * Y las retiradas que llevan la tabla en el argumento, fuera de un
         * `Schema::table()`. Hoy es una sola —`quitarSiEsta()`, el ayudante de
         * la migración de retirada— y por eso va por su cuenta.
         */
        preg_match_all(
            "~quitarSiEsta\(\s*'([a-z_0-9]+)'\s*,\s*'([a-z_0-9]+)'\s*\)~",
            $arriba,
            $r,
            PREG_SET_ORDER
        );

        foreach ($r as $x) {
            unset($puestos[$x[1].'.'.$x[2]]);
        }
    }

    return $puestos;
}

it('cada indice con nombre que declara una migracion esta en el esquema', function () {
    $motor = Schema::getConnection()->getDriverName();
    $declarados = indicesDeclaradosEnLasMigraciones($motor);

    /*
     * CONTROL. Sin esto la prueba pasa en verde con la lista vacía, que es
     * justo lo que pasaría si alguien cambia el estilo con el que se declaran
     * los índices y el recorrido deja de reconocerlos. Eran 92 al escribirla;
     * se exige holgadamente menos para que no se ponga roja por retirar uno.
     */
    expect(count($declarados))->toBeGreaterThan(60);

    $faltan = [];
    $porTabla = [];

    foreach ($declarados as $clave => $migracion) {
        [$tabla, $indice] = explode('.', $clave, 2);

        if (! Schema::hasTable($tabla)) {
            $faltan[] = "{$tabla} (la tabla no existe)  <- {$migracion}";

            continue;
        }

        $porTabla[$tabla] ??= array_map(
            fn (array $i): string => strtolower((string) $i['name']),
            Schema::getIndexes($tabla)
        );

        if (! in_array(strtolower($indice), $porTabla[$tabla], true)) {
            $faltan[] = "{$tabla}.{$indice}  <- {$migracion}";
        }
    }

    expect($faltan)->toBe([]);
});

it('CONTROL: la prueba ve de verdad un indice que no esta', function () {
    /*
     * La mitad que hace que lo de arriba signifique algo. Si `getIndexes()`
     * devolviera cualquier cosa —o una lista que contuviera todo— la prueba
     * de arriba pasaría con el esquema vacío. Así que se pregunta por un
     * índice que seguro no existe y se exige que NO aparezca.
     */
    $indices = array_map(
        fn (array $i): string => strtolower((string) $i['name']),
        Schema::getIndexes('orderssales')
    );

    expect($indices)->not->toContain('ix_este_indice_no_existe');

    /* Y que sí vea los que están: uno medido, de la migración del tablero. */
    expect($indices)->toContain('ix_pedidos_negocio_estado_entrega');
});

it('los indices retirados a proposito NO vuelven', function () {
    /*
     * `2026_08_28_090200_retirar_indices_redundantes` quitó dos índices que
     * duplicaban el prefijo de otro compuesto. Su `down()` los recrea, así
     * que si alguien revierte esa migración y la vuelve a aplicar mal, o los
     * declara otra vez «por si acaso», esto lo dice.
     *
     * Solo aplica a MySQL: en SQLite nunca existieron, porque los creaba
     * MySQL por su cuenta al declarar la foránea. Acá se comprueba lo que se
     * puede comprobar en cada motor, en vez de saltarse la prueba entera.
     */
    if (Schema::getConnection()->getDriverName() !== 'mysql') {
        expect(Schema::hasTable('products_business'))->toBeTrue();

        return;
    }

    foreach ([['products_business', 'ix_pb_negocio'],
        ['orderssales', 'fk_orderssales_business']] as [$tabla, $indice]) {
        $enEsquema = array_map(
            fn (array $i): string => strtolower((string) $i['name']),
            Schema::getIndexes($tabla)
        );

        expect($enEsquema)->not->toContain(strtolower($indice));
    }
});
