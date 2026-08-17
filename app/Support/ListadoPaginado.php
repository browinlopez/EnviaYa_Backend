<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * PAGINACIÓN, BÚSQUEDA Y ORDEN EN EL SERVIDOR
 *
 * Hasta ahora los listados del panel devolvían la tabla ENTERA y el navegador
 * paginaba lo que le llegaba. Con nueve pedidos eso funciona; con cincuenta mil
 * el panel descarga cincuenta mil filas con sus joins en cada visita. No se
 * degrada poco a poco: pasa de ir bien a ser inusable, y justo cuando el
 * negocio empieza a funcionar.
 *
 * Solo se aplica a lo que crece sin techo —pedidos, pagos, productos, usuarios,
 * auditoría—. Las tablas de catálogo acotado (categorías, conjuntos, áreas) se
 * siguen sirviendo completas: paginar veinte filas es complicar la pantalla sin
 * ganar nada.
 *
 * La respuesta lleva `data` y `meta` en vez de un arreglo suelto, así que el
 * panel distingue "no hay más" de "no cargó".
 */
class ListadoPaginado
{
    /** Techo duro. Aunque el cliente pida 100000, no se sirve más que esto. */
    private const MAXIMO = 200;

    /**
     * La misma consulta, pero devolviendo SOLO los agregados que se le pidan.
     *
     * Hace falta porque `selectRaw` AÑADE columnas en vez de reemplazarlas: el
     * resumen salía con el `SELECT` entero del listado delante y MySQL lo
     * rechazaba —columnas sin agrupar en una consulta agregada—, que es un
     * error críptico para lo que en realidad era "te olvidaste de limpiar el
     * select".
     *
     * También hay que vaciar los ligados de la sección `select`: el listado de
     * pedidos lleva un EXISTS con parámetros, y dejarlos ahí desplaza todos los
     * demás ligados una posición. Eso no falla con un error: devuelve números
     * equivocados, que es peor.
     */
    public static function soloAgregados(Builder $q, string $sql, array $ligados = []): ?object
    {
        $base = clone $q;
        $base->columns = null;
        $base->bindings['select'] = [];

        return $base->selectRaw($sql, $ligados)->first();
    }

    /**
     * @param Builder $q                consulta ya construida, sin orden ni límite
     * @param list<string> $buscables   columnas calificadas donde busca `search`
     * @param array<string,string> $ordenables  alias público => columna real
     * @param ?callable $resumen        recibe la consulta YA FILTRADA y devuelve
     *                                  los indicadores de la pantalla
     */
    public static function responder(
        Request $request,
        Builder $q,
        array $buscables = [],
        array $ordenables = [],
        string $ordenPorDefecto = '',
        string $direccionPorDefecto = 'desc',
        ?callable $resumen = null,
    ): array {
        /*
         * OPCIONAL A PROPÓSITO.
         *
         * Si el cliente no pide página, se responde el listado completo como
         * siempre. La razón no es comodidad: pantallas como Órdenes filtran por
         * negocio, domiciliario y rango en el navegador, y calculan sus
         * indicadores sobre lo filtrado. Servirles una sola página sin haber
         * movido antes esos filtros y esas sumas al servidor dejaría los KPIs
         * mostrando el total de 25 pedidos como si fuera el del mes — en una
         * pantalla de dinero.
         *
         * Así la capacidad queda disponible y cada pantalla se pasa cuando se
         * migren también su filtrado y sus totales, sin romper ninguna hoy.
         */
        if (!$request->has('page') && !$request->has('per_page')) {
            if ($ordenPorDefecto && ($ordenables[$ordenPorDefecto] ?? null)) {
                $q->orderBy($ordenables[$ordenPorDefecto], $direccionPorDefecto);
            }

            return [
                'data'    => $q->get(),
                'meta'    => null,
                'summary' => $resumen ? $resumen(clone $q) : null,
            ];
        }

        $porPagina = min(self::MAXIMO, max(1, (int) $request->query('per_page', 25)));
        $pagina    = max(1, (int) $request->query('page', 1));

        /*
         * La búsqueda va antes de contar: el total tiene que ser el de lo
         * filtrado, no el de la tabla. Con el total sin filtrar, el panel
         * dibujaría paginación para filas que su búsqueda ya descartó.
         */
        $termino = trim((string) $request->query('search', ''));

        if ($termino !== '' && $buscables) {
            $q->where(function (Builder $sub) use ($buscables, $termino) {
                foreach ($buscables as $columna) {
                    $sub->orWhere($columna, 'like', "%{$termino}%");
                }
            });
        }

        // Se cuenta sobre una copia: `count()` consume la consulta y después no
        // se podría seguir encadenando el orden y el límite.
        $total = (clone $q)->count();

        // Solo se admite ordenar por lo declarado. Pasar la columna del cliente
        // directo a `orderBy` es inyección: llega como identificador, no como
        // valor, así que no lo protege el ligado de parámetros.
        $orden = $request->query('sort', '');
        $columna = $ordenables[$orden] ?? ($ordenables[$ordenPorDefecto] ?? $ordenPorDefecto);

        $direccion = strtolower((string) $request->query('dir', $direccionPorDefecto)) === 'asc'
            ? 'asc'
            : 'desc';

        if ($columna) {
            $q->orderBy($columna, $direccion);
        }

        /*
         * El resumen se calcula sobre TODO lo filtrado, no sobre la página.
         *
         * Es lo que faltaba para poder paginar estas pantallas. Sumar en el
         * navegador lo que llegó significaba, con una página de 25, mostrar
         * "ingresos del periodo: $310.000" cuando el periodo llevaba tres
         * millones. En una pantalla de dinero, un total que en realidad es el
         * de la página visible no es un dato incompleto: es un dato falso.
         *
         * Va antes del `forPage` y sobre una copia, porque `forPage` muta la
         * consulta.
         */
        $indicadores = $resumen ? $resumen(clone $q) : null;

        $filas = $q->forPage($pagina, $porPagina)->get();

        return [
            'data'    => $filas,
            'summary' => $indicadores,
            'meta' => [
                'page'      => $pagina,
                'per_page'  => $porPagina,
                'total'     => $total,
                'last_page' => max(1, (int) ceil($total / $porPagina)),
                'search'    => $termino,
                'sort'      => $orden,
                'dir'       => $direccion,
            ],
        ];
    }
}
