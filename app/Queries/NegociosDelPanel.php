<?php

declare(strict_types=1);

namespace App\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * LAS LECTURAS DE NEGOCIOS DEL PANEL INTERNO.
 *
 * §12 de `AGENTS.md` lo pide con estas palabras: «relaciones y scopes breves
 * en `Models/`, **consultas complejas en `Queries/`**». Y §21 concreta cuándo:
 * un listado con varios filtros o relaciones, un reporte con agregados, una
 * búsqueda por criterios especiales. Lo de aquí eran tres uniones y CUATRO
 * subconsultas de agregado escritas dentro de `NegociosApiController`.
 *
 * NO SE CAMBIA NI UNA COMA DE LAS CONSULTAS. Se mueven, con sus comentarios,
 * que es lo único que las hace mantenibles: cada uno cuenta un error que ya
 * se cometió.
 *
 * Y §21 §4 DICE CUÁNDO **NO** HACER ESTO, que importa igual: «no crear
 * `FindOrderQuery`, `OrderRepository`, `OrderReader` y `OrderFinder` para una
 * única instrucción». Por eso esta clase agrupa las TRES lecturas del mismo
 * recurso en vez de ser tres clases de un método, y por eso no se tocaron los
 * controladores cuyas consultas son una sola instrucción: ahí un `Queries/`
 * sería la ceremonia que §05 prohíbe.
 *
 * DEVUELVE DATOS, NO RESPUESTAS. El controlador sigue siendo quien responde,
 * quien decide el código HTTP y quien pasa las filas por el presentador de
 * imágenes. Una Query que devolviera un `JsonResponse` sería un controlador
 * con otro nombre.
 */
class NegociosDelPanel
{
    /** Estado «entregado» de un pedido, para la columna de ventas. */
    public function __construct(private readonly int $entregado) {}

    /**
     * El listado con su municipio, su departamento, su tipo, su dueño y tres
     * agregados.
     *
     * LOS AGREGADOS VAN EN SUBCONSULTAS Y NO EN JOINS.
     *
     * Unir `products_business` y `orderssales` a la vez produce un producto
     * cartesiano: cada pedido se repite una vez por producto del negocio. Los
     * COUNT(DISTINCT) sobrevivían, pero SUM() no tiene DISTINCT y las ventas
     * salían multiplicadas por el número de productos (con 186 productos,
     * $53.000 se mostraban como $9.858.000).
     *
     * `stdClass` y no `object`: los genericos de `Collection` son
     * INVARIANTES, asi que `Collection<int, object>` NO acepta un
     * `Collection<int, stdClass>` aunque un stdClass sea un object. Es la
     * misma trampa que `TorreDeControlController::ultimaPosicion()`.
     *
     * @return Collection<int, stdClass>
     */
    public function listado(): Collection
    {
        return DB::table('business as b')
            ->orderBy('b.name')
            ->leftJoin('municipalities as m', 'm.id', '=', 'b.municipality_id')
            ->leftJoin('departments as dp', 'dp.id', '=', 'm.department_id')
            // `business.type` guarda el ID de la categoría de negocio.
            ->leftJoin('category_business as cb', 'cb.id', '=', 'b.type')
            ->select([
                'b.busines_id', 'b.name', 'b.phone', 'b.address', 'b.qualification',
                'b.razonSocial_DCD', 'b.NIT', 'b.logo', 'b.description',
                'b.latitude', 'b.longitude', 'b.type', 'b.state',
                // El editor se siembra con esta fila: sin el tope, guardar
                // cualquier otro cambio lo mandaba vacío y lo borraba.
                'b.credit_debt_cap',
                'b.municipality_id', 'm.name as municipality_name',
                'dp.id as department_id', 'dp.name as department_name',
                'cb.name as type_name',
            ])
            // El dueño en subconsulta: unir `owner_busines` repetiría la
            // fila del negocio si alguna vez tuviera dos vínculos.
            ->selectSub(
                DB::table('owner_busines as ob')
                    ->join('owner as o', 'o.owner_id', '=', 'ob.owner_id')
                    ->join('user as u', 'u.user_id', '=', 'o.user_id')
                    ->selectRaw('u.name')
                    ->whereColumn('ob.busines_id', 'b.busines_id')
                    ->limit(1),
                'owner_name',
            )
            ->selectSub(
                DB::table('products_business')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('products_business.busines_id', 'b.busines_id'),
                'products_count',
            )
            ->selectSub(
                DB::table('orderssales')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('orderssales.busines_id', 'b.busines_id'),
                'orders_count',
            )
            ->selectSub(
                DB::table('orderssales')
                    ->selectRaw('COALESCE(SUM(total), 0)')
                    ->whereColumn('orderssales.busines_id', 'b.busines_id')
                    ->where('state', $this->entregado),
                'revenue',
            )
            ->get();
    }

    /** Un negocio con todas sus columnas, más los nombres de sus catálogos. */
    public function ficha(int|string $id): ?object
    {
        return DB::table('business as b')
            ->leftJoin('municipalities as m', 'm.id', '=', 'b.municipality_id')
            ->leftJoin('departments as dp', 'dp.id', '=', 'm.department_id')
            ->leftJoin('category_business as cb', 'cb.id', '=', 'b.type')
            ->where('b.busines_id', $id)
            ->first([
                'b.*',
                'm.name as municipality_name',
                'dp.id as department_id',
                'dp.name as department_name',
                'cb.name as type_name',
            ]);
    }

    /**
     * Catálogo de departamentos con sus municipios.
     *
     * Va en una sola respuesta (32 departamentos, 110 municipios) porque el
     * formulario necesita ambos niveles a la vez para encadenar los
     * selectores, y pedirlos por separado obligaría a una segunda petición
     * cada vez que se cambia de departamento.
     *
     * `stdClass` y no `object`: los genericos de `Collection` son
     * INVARIANTES, asi que `Collection<int, object>` NO acepta un
     * `Collection<int, stdClass>` aunque un stdClass sea un object. Es la
     * misma trampa que `TorreDeControlController::ultimaPosicion()`.
     *
     * @return Collection<int, stdClass>
     */
    public function departamentosConMunicipios(): Collection
    {
        $municipios = DB::table('municipalities')
            ->orderBy('name')
            ->get(['id', 'name', 'department_id'])
            ->groupBy('department_id');

        return DB::table('departments')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function ($d) use ($municipios) {
                $d->municipalities = ($municipios[$d->id] ?? collect())
                    ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])
                    ->values();

                return $d;
            })
            // Un departamento sin municipios cargados no sirve para elegir.
            ->filter(fn ($d) => count($d->municipalities) > 0)
            ->values();
    }
}
