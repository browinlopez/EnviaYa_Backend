<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Order\OrdersSales;
use App\Models\Payment\Payment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * LAS LECTURAS DE PEDIDOS Y DE LA FLOTA QUE COMPARTE EL PANEL INTERNO.
 *
 * §12 pide las consultas complejas en `Queries/`. Estas vivían en un RASGO,
 * `App\Support\Consultas\AyudasDeAdmin`, del que tiraban nueve controladores
 * de administración y un servicio.
 *
 * POR QUÉ DEJA DE SER UN RASGO, que es el cambio de verdad:
 *
 *  1. `app/Support/Consultas/` era un segundo hogar para los objetos de
 *     consulta. Con `app/Queries/` ya creado, tener dos sitios donde buscar
 *     la misma cosa es el defecto que se cierra acá.
 *  2. Un rasgo mete sus métodos Y SUS CONSTANTES en cada clase que lo usa.
 *     Eso convertía dos decisiones --qué pedido está activo, qué pago entró--
 *     en constantes privadas de diez clases distintas. Ya pasó esta misma
 *     semana con los cinco estados del pedido.
 *  3. Un rasgo no se puede pedir, ni sustituir en una prueba, ni leer sin
 *     abrir a sus consumidores para saber quién lo usa.
 *
 * LAS DOS CONSTANTES SE FUERON A SUS MODELOS: `OrdersSales::ACTIVOS` y
 * `Payment::COBRADOS`. Describen columnas, y §07 dice que eso es lo que
 * describe un modelo. Arrastrarlas hasta acá habría sido mover el problema.
 *
 * Y DEVUELVE EL CONSTRUCTOR en `ordenesBase()`, no las filas: la paginación,
 * la búsqueda y el orden los resuelve `ListadoPaginado`, y los tres
 * consumidores le añaden filtros distintos antes de ejecutar.
 */
class PedidosDelPanel
{
    /**
     * Subconsulta: ¿esta orden tiene al menos un pago aprobado?
     *
     * Se correlaciona con el alias `o` de la consulta externa, así que quien
     * la use debe nombrar así la tabla `orderssales`.
     *
     * @param  Builder  $q
     */
    public function pagoAprobado($q): Builder
    {
        return $q->select(DB::raw(1))
            ->from('payments as pg')
            ->whereColumn('pg.orderSales_id', 'o.orderSales_id')
            ->whereIn('pg.status', Payment::COBRADOS);
    }

    /** Consulta base con los nombres ya resueltos, para lista y detalle. */
    public function ordenesBase(): Builder
    {
        return DB::table('orderssales as o')
            ->leftJoin('buyer as by', 'by.buyer_id', '=', 'o.buyer_id')
            ->leftJoin('user as bu', 'bu.user_id', '=', 'by.user_id')
            ->leftJoin('business as b', 'b.busines_id', '=', 'o.busines_id')
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'o.domiciliary_id')
            ->leftJoin('user as du', 'du.user_id', '=', 'd.user_id')
            ->leftJoin('user_address as ua', 'ua.address_id', '=', 'o.address_id')
            ->select([
                'o.orderSales_id', 'o.state', 'o.payment_state', 'o.total', 'o.subtotal',
                'o.domicilio', 'o.domiciliary_fee', 'o.sale_date', 'o.created_at',
                'o.delivery_date', 'o.dispatched_at', 'o.pickup', 'o.methods_id', 'o.forms_id',
                // Lo que el cliente dijo que va a pagar en efectivo, y en que
                // punto va el pesaje. El cambio NO se guarda: se calcula al
                // pintarlo, porque sigue al total.
                'o.cash_tendered', 'o.weighing_state', 'o.weighed_at',
                'bu.name as buyer_name', 'bu.phone as buyer_phone',
                'b.busines_id', 'b.name as business_name',
                'd.domiciliary_id', 'du.name as domiciliary_name',
                'ua.address as address',
            ])
            // Bandera derivada del registro de pagos. El panel muestra esto y
            // no `payment_state`, para que la lista de pedidos y la de pagos
            // no puedan contradecirse.
            ->selectRaw(
                'EXISTS (SELECT 1 FROM payments pg
                          WHERE pg.orderSales_id = o.orderSales_id
                            AND pg.status IN (?, ?)) as paid',
                Payment::COBRADOS,
            );
    }

    /**
     * La flota, con sus agregados: entregas, pedidos en curso y ganancias.
     *
     * `stdClass` y no `object`: los genéricos de `Collection` son INVARIANTES.
     *
     * @return Collection<int, stdClass>
     */
    public function resumenDomiciliarios(?int $negocioId = null): Collection
    {
        // Mismo criterio que en `NegociosDelPanel`: agregados por subconsulta.
        // Unir a la vez `orderssales` y `business_domiciliary` multiplicaba
        // las ganancias por la cantidad de negocios asignados al repartidor.
        $deOrdenes = fn (callable $extra) => $extra(
            DB::table('orderssales')->whereColumn('orderssales.domiciliary_id', 'd.domiciliary_id'),
        );

        return DB::table('domiciliary as d')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            /*
             * Los del negocio pedido, si se pide uno.
             *
             * Con EXISTS y no con un join: `business_domiciliary` admite que
             * un repartidor esté en varios negocios, y unirla multiplicaría su
             * fila una vez por cada uno. Ya pasó con las ganancias, y por eso
             * los agregados de acá van por subconsulta.
             */
            ->when($negocioId, fn ($q) => $q->whereExists(
                fn ($sub) => $sub->from('business_domiciliary as bd')
                    ->whereColumn('bd.domiciliary_id', 'd.domiciliary_id')
                    ->where('bd.busines_id', $negocioId)
                    ->selectRaw('1'),
            ))
            ->orderBy('u.name')
            ->select([
                'd.domiciliary_id', 'd.user_id', 'd.available', 'd.document',
                'd.qualification', 'd.state',
                'd.contract_media_id', 'd.contract_signed_at', 'd.contract_city',
                'u.name', 'u.email', 'u.phone',
                // `available` es el turno (hoy salgo / hoy no). `user.state`
                // es el acceso a la app. Son cosas distintas y la interfaz
                // tiene que poder mostrarlas por separado.
                'u.state as account_active',
            ])
            ->selectSub(
                $deOrdenes(fn ($q) => $q->selectRaw('COUNT(*)')->where('state', OrdersSales::ENTREGADO)),
                'deliveries',
            )
            ->selectSub(
                $deOrdenes(fn ($q) => $q->selectRaw('COUNT(*)')->whereIn('state', OrdersSales::ACTIVOS)),
                'active_deliveries',
            )
            ->selectSub(
                $deOrdenes(
                    fn ($q) => $q->selectRaw('COALESCE(SUM(domiciliary_fee), 0)')
                        ->where('state', OrdersSales::ENTREGADO),
                ),
                'earnings',
            )
            ->selectSub(
                DB::table('business_domiciliary')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('business_domiciliary.domiciliary_id', 'd.domiciliary_id'),
                'businesses_count',
            )
            // Los nombres, no solo cuántos: en la lista "1" no dice para quién
            // trabaja, que es lo que se quiere saber de un vistazo.
            ->selectSub(
                DB::table('business_domiciliary as bd2')
                    ->join('business as b2', 'b2.busines_id', '=', 'bd2.busines_id')
                    /*
                     * `ORDER BY ... SEPARATOR` dentro de GROUP_CONCAT es de
                     * MySQL. En SQLite —donde corren las pruebas— es un error
                     * de sintaxis que hacía fallar el endpoint entero con un
                     * 500, así que esta pantalla no se podía probar.
                     *
                     * El separador se deja igual en ambos ('|') porque el panel
                     * parte por él; lo que se pierde en SQLite es el orden
                     * alfabético, que solo afecta a cómo se lee la lista y no a
                     * lo que se está probando.
                     */
                    ->selectRaw(
                        in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                            ? "GROUP_CONCAT(b2.name ORDER BY b2.name SEPARATOR '|')"
                            : "GROUP_CONCAT(b2.name, '|')"
                    )
                    ->whereColumn('bd2.domiciliary_id', 'd.domiciliary_id'),
                'businesses_names',
            )
            ->get();
    }
}
