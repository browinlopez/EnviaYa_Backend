<?php

use App\Models\Area;
use App\Models\Rol;
use App\Models\User;
use App\Services\ResumenDelPanel;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * EL TABLERO DEL PANEL NO PREGUNTA DOS VECES LO MISMO
 *
 * `/admin/overview` hacía 48 consultas y cuatro salían repetidas. Tres eran
 * el mismo agregado pedido por dos partes de la misma pantalla: las cifras
 * generales de arriba (`businesses`, `products`, `pending_payment`) y los
 * bloques por área de abajo (`businesses_total`, `products_total`,
 * `unpaid_orders`). La cuarta era la fila del área del usuario, que buscaban
 * el middleware de permisos y el servicio por separado.
 *
 * No era un N+1 —las otras 40 son 40 agregados distintos, y eso está
 * medido—, pero sí eran cuatro consultas pagadas por nada.
 *
 * LO QUE SE PRUEBA ACÁ es lo que el arreglo asume, no el número de consultas
 * por sí mismo:
 *
 *  1. que las dos etiquetas de cada cifra siguen dando el MISMO número, que
 *     es lo que autoriza a contarla una sola vez;
 *  2. que no se consulta dos veces;
 *  3. y que el contenedor NO comparte `ResumenDelPanel`, porque su memoria de
 *     conteos vive en la instancia. Si alguien lo registrara como `singleton`,
 *     el panel enseñaría cifras congeladas de una petición anterior y nadie lo
 *     notaría: el síntoma sería «creé un negocio y el tablero sigue diciendo
 *     7», sin error en ningún log.
 */

/*
 * Nombre propio a propósito: `comoArea()` ya existe en
 * `tests/Feature/Areas/PermisosPorAreaTest.php`, y Pest carga todos los
 * archivos en el mismo proceso, así que repetirlo es un `Cannot redeclare`.
 */
function comoGerencia(): User
{
    Rol::firstOrCreate(['rol_id' => 4], ['name' => 'admin', 'guard_name' => 'web']);

    // Gerencia porque es la única que ve los cinco bloques a la vez: con un
    // área más estrecha, el bloque de Comercial no se calcularía y la prueba
    // pasaría sin haber comparado nada.
    $area = Area::where('code', 'gerencia')->firstOrFail();
    $user = User::factory()->create([
        'rol' => 4, 'area_id' => $area->id, 'access_level' => 'gestor',
    ]);

    Sanctum::actingAs($user);

    return $user;
}

/** Las consultas de una petición, en texto plano y de una sola línea. */
function consultasDe(callable $hacer): array
{
    $vistas = [];
    DB::listen(function ($q) use (&$vistas) {
        $vistas[] = preg_replace('/\s+/', ' ', $q->sql);
    });

    $hacer();

    return $vistas;
}

it('los dos nombres de cada cifra dan el mismo numero', function () {
    /*
     * ESTA ES LA PRUEBA QUE AUTORIZA EL ARREGLO. Compartir un conteo entre
     * dos campos solo es correcto si los dos campos son la misma pregunta. Si
     * mañana alguien decide que `businesses_total` debe contar solo las
     * tiendas visibles, esto se pone rojo y le avisa de que no puede cambiarlo
     * sin separar las consultas otra vez.
     */
    comoGerencia();

    $r = $this->getJson('/v1/admin/overview')->assertOk();

    expect($r->json('blocks.comercial.businesses_total'))
        ->toBe($r->json('totals.businesses'));
    expect($r->json('blocks.comercial.products_total'))
        ->toBe($r->json('totals.products'));
    expect($r->json('blocks.contabilidad.unpaid_orders'))
        ->toBe($r->json('totals.pending_payment'));

    // Y que de verdad llegaron: un `null` en los dos lados también sería
    // «iguales», y no probaría nada.
    expect($r->json('totals.businesses'))->toBeInt();
    expect($r->json('totals.products'))->toBeInt();
    expect($r->json('totals.pending_payment'))->toBeInt();
});

it('cada una de esas cifras se cuenta UNA vez', function () {
    comoGerencia();

    $consultas = consultasDe(fn () => $this->getJson('/v1/admin/overview')->assertOk());

    /*
     * EXACTO, NO «QUE CONTENGA», y esto costó una vuelta: con
     * `str_contains('count(*) as aggregate from "business"')` salían CINCO,
     * porque esa cadena es el prefijo de otras cuatro consultas del bloque de
     * Comercial —las ocultas, las sin ubicación, las sin tipo, las sin
     * propietario—. Un filtro por prefijo en una comprobación de duplicados
     * cuenta de más y la prueba no se puede creer.
     */
    $exactas = function (string $sql) use ($consultas) {
        return count(array_filter($consultas, fn (string $s) => $s === $sql));
    };

    expect($exactas('select count(*) as aggregate from "business"'))->toBe(1);
    expect($exactas('select count(*) as aggregate from "products"'))->toBe(1);

    // El área del usuario: la pedían el middleware de permisos y el servicio.
    expect($exactas('select * from "areas" where "areas"."id" = ? limit 1'))->toBe(1);

    /*
     * La de los pedidos sin pago aprobado sí va por subcadena, porque su SQL
     * es larga y la arma `pagoAprobado()`; pero la subcadena elegida —el
     * `not exists` contra `payments`— no es prefijo de ninguna otra: la otra
     * consulta de `payments` de esta pantalla es un `count` llano.
     */
    $conSubcadena = count(array_filter(
        $consultas,
        fn (string $s) => str_contains($s, 'not exists (select 1 from "payments"'),
    ));
    expect($conSubcadena)->toBe(1);
});

it('el contenedor NO comparte ResumenDelPanel, que es lo que hace segura su memoria', function () {
    /*
     * La memoria de conteos de `ResumenDelPanel` es un campo de la instancia,
     * y eso solo está bien mientras la instancia dure una petición. El
     * contenedor de Laravel resuelve una nueva cada vez salvo que algo la
     * registre como compartida; esto fija ese «salvo que».
     */
    expect(app(ResumenDelPanel::class))->not->toBe(app(ResumenDelPanel::class));
    expect(app()->isShared(ResumenDelPanel::class))->toBeFalse();
});
