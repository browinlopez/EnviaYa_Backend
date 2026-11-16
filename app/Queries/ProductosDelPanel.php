<?php

declare(strict_types=1);

namespace App\Queries;

use App\Support\ListadoPaginado;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * LAS LECTURAS DEL CATÁLOGO DEL PANEL INTERNO.
 *
 * §12 pide las consultas complejas en `Queries/` y §21 nombra este caso
 * exacto: «listado con 3+ filtros, paginación o múltiples relaciones». Esto
 * eran tres uniones, una subconsulta de agregado, dos filtros opcionales y un
 * bloque de indicadores, dentro de `ProductosApiController`.
 *
 * RECIBE LOS FILTROS, NO LA PETICIÓN. Pasar el `Request` aquí metería HTTP en
 * la capa de datos y ataría la consulta a los nombres de los parámetros de
 * una pantalla. §21 lo dice al revés: «Queries del módulo filtran en SQL por
 * tenant/usuario». Quien traduce la petición a filtros sigue siendo el
 * controlador.
 *
 * Y DEVUELVE EL CONSTRUCTOR, no las filas, en el caso del listado: la
 * paginación, la búsqueda y el orden los resuelve `ListadoPaginado`, que ya
 * existía y es infraestructura compartida. Ejecutar aquí obligaría a duplicar
 * eso o a devolver todo el catálogo.
 */
class ProductosDelPanel
{
    /**
     * El catálogo como OFERTAS: la misma referencia aparece una vez por tienda.
     *
     * El producto vive en `products` y su precio/existencias en
     * `products_business`: la misma referencia puede aparecer varias veces,
     * una por tienda, y cada fila es una oferta distinta.
     *
     * Las unidades vendidas van por subconsulta: unir el detalle de pedidos
     * aquí duplicaría cada renglón una vez por tienda que ofrece el mismo
     * producto.
     */
    public function listado(?string $categoria = null, ?int $negocio = null): Builder
    {
        $q = DB::table('products as p')
            ->leftJoin('products_business as pb', 'pb.products_id', '=', 'p.products_id')
            ->leftJoin('business as b', 'b.busines_id', '=', 'pb.busines_id')
            ->leftJoin('category as c', 'c.category_id', '=', 'p.category_id')
            ->select([
                'p.products_id', 'p.name', 'p.description', 'p.image', 'p.state',
                'pb.busines_products_id', 'pb.price', 'pb.amount', 'pb.qualification',
                'b.busines_id', 'b.name as business_name', 'c.name as category_name',
            ])
            ->selectSub(
                DB::table('orderssales_detail')
                    ->selectRaw('COALESCE(SUM(amount), 0)')
                    ->whereColumn('orderssales_detail.product_id', 'p.products_id'),
                'sold',
            );

        if ($categoria) {
            $q->where('c.name', $categoria);
        }

        // El join a `business` ya estaba —se usa para el nombre y para
        // ordenar—, así que filtrar por negocio no cuesta una consulta más.
        if ($negocio) {
            $q->where('b.busines_id', $negocio);
        }

        return $q;
    }

    /**
     * Indicadores del catálogo, sobre todo lo filtrado.
     *
     * "Referencias" cuenta OFERTAS y no productos distintos: la misma
     * referencia en tres tiendas son tres filas con tres precios y tres
     * existencias, y es lo que se está mirando en la tabla.
     */
    public function resumen($q): array
    {
        $r = ListadoPaginado::soloAgregados($q, "
            COUNT(*) as total,
            COALESCE(SUM(pb.price * pb.amount), 0) as inventario,
            SUM(CASE WHEN pb.amount = 0 THEN 1 ELSE 0 END) as agotados,
            SUM(CASE WHEN p.image IS NULL OR p.image = '' THEN 1 ELSE 0 END) as sin_imagen
        ");

        return [
            'total' => (int) ($r->total ?? 0),
            'inventario' => round((float) ($r->inventario ?? 0), 2),
            'agotados' => (int) ($r->agotados ?? 0),
            'sinImagen' => (int) ($r->sin_imagen ?? 0),
        ];
    }

    /**
     * Desglose por categoría, agregado en la base.
     *
     * La pantalla lo usa para su selector. Estaba resolviéndolo en el navegador
     * recorriendo el catálogo entero — es decir, pedía las 755 ofertas ADEMÁS
     * de la página, y con eso la paginación no ahorraba nada. Son unas decenas
     * de filas: se calculan con un GROUP BY y se traen aparte.
     *
     * @return Collection<int, stdClass>
     */
    public function porCategoria(): Collection
    {
        return DB::table('products as p')
            ->leftJoin('products_business as pb', 'pb.products_id', '=', 'p.products_id')
            ->join('category as c', 'c.category_id', '=', 'p.category_id')
            ->groupBy('c.category_id', 'c.name')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get([
                DB::raw('c.name as name'),
                DB::raw('COUNT(*) as items'),
                DB::raw('SUM(CASE WHEN pb.amount = 0 THEN 1 ELSE 0 END) as agotados'),
                DB::raw('COALESCE(SUM(pb.price * pb.amount), 0) as valor'),
            ]);
    }

    /** Un producto del catálogo, por su identificador. */
    public function ficha(int|string $id): ?object
    {
        return DB::table('products')->where('products_id', $id)->first();
    }
}
