<?php

namespace App\Support\Consultas;

use App\Services\Ajustes;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Conjunto\ComplexStaff;
use App\Models\Operacion\DomiciliaryDocument;
use App\Models\Reviews\BusinessReview;
use App\Models\Reviews\DomiciliaryReview;
use App\Models\User;
use App\Services\BusinessMediaService;
use App\Services\ContratoService;
use App\Services\MediaService;
use App\Services\VinculosDelRol;
use App\Support\ListadoPaginado;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Consultas base que comparten los controladores del panel y los servicios.
 *
 * Vivia en el espacio de los controladores, y por eso un servicio no podia
 * usarlo sin depender de ellos. Lo que hay aca no es logica de HTTP: son las
 * consultas que definen que cuenta como pedido y que como pago aprobado, y
 * duplicarlas es como se acaba con dos tableros que dan cifras distintas.
 *
 * Estaban sueltos dentro del controlador gordo. Aca no se duplican:
 * quien los necesite usa el trait.
 */
trait AyudasDeAdmin
{
    private const ENTREGADO = 4;

    private const ACTIVOS = [1, 2, 3];

    /** Estados de `payments.status` que significan "la plata entró". */
    private const PAGO_OK = ['approved', 'paid'];

    /**
     * Subconsulta: ¿esta orden tiene al menos un pago aprobado?
     *
     * Se correlaciona con el alias `o` de la consulta externa, así que quien
     * la use debe nombrar así la tabla `orderssales`.
     */
    private function pagoAprobado($q)
    {
        return $q->select(DB::raw(1))
            ->from('payments as pg')
            ->whereColumn('pg.orderSales_id', 'o.orderSales_id')
            ->whereIn('pg.status', self::PAGO_OK);
    }

    /* ==================================================================
       PANEL
       ================================================================== */

    /**
     * Resuelve la imagen principal de una colección desde `media_files`.
     *
     * El campo `logo`/`image` del registro solo se rellena cuando hay dominio
     * público configurado, porque una URL firmada caduca y no se puede
     * persistir. Para que el panel muestre la imagen igualmente, se resuelve
     * al vuelo desde la tabla de archivos.
     *
     * Se hace en UNA consulta para toda la colección: pedir la imagen fila a
     * fila sería el problema N+1 de manual.
     */
    private function conImagenPrincipal($filas, string $entidad, string $campoId, string $campoImagen, MediaService $medios)
    {
        $ids = $filas->pluck($campoId)->filter()->all();

        if (!$ids) {
            return $filas;
        }

        $principales = DB::table('media_files')
            ->where('entity_type', $entidad)
            ->whereIn('entity_id', $ids)
            ->where('is_primary', true)
            ->pluck('object_key', 'entity_id');

        return $filas->map(function ($fila) use ($principales, $campoId, $campoImagen, $medios) {
            $key = $principales[$fila->{$campoId}] ?? null;

            /*
             * El archivo marcado como principal MANDA sobre la columna.
             *
             * La columna suele arrastrar la URL con la que se sembró el
             * registro (una imagen de Google, por ejemplo). Si alguien sube un
             * logo desde el panel, ese es el logo actual: dejar ganar a la
             * columna haría que la subida no se viera y pareciera que falló.
             */
            if ($key) {
                $fila->{$campoImagen} = $medios->url($key);
            }

            return $fila;
        });
    }

    /* ==================================================================
       MEDIOS DE CUALQUIER ENTIDAD (Cloudflare R2)
       ================================================================== */


    /** Consulta base con los nombres ya resueltos, para lista y detalle. */
    private function ordenesBase()
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
                self::PAGO_OK,
            );
    }


    private function resumenDomiciliarios(?int $negocioId = null)
    {
        // Mismo criterio que en `businesses()`: agregados por subconsulta.
        // Unir a la vez `orderssales` y `business_domiciliary` multiplicaba
        // las ganancias por la cantidad de negocios asignados al repartidor.
        $deOrdenes = fn(callable $extra) => $extra(
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
                $deOrdenes(fn($q) => $q->selectRaw('COUNT(*)')->where('state', self::ENTREGADO)),
                'deliveries',
            )
            ->selectSub(
                $deOrdenes(fn($q) => $q->selectRaw('COUNT(*)')->whereIn('state', self::ACTIVOS)),
                'active_deliveries',
            )
            ->selectSub(
                $deOrdenes(
                    fn($q) => $q->selectRaw('COALESCE(SUM(domiciliary_fee), 0)')
                        ->where('state', self::ENTREGADO),
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
