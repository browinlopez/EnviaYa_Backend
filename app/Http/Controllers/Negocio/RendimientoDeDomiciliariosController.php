<?php

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Services\GeneradorDeReportes;
use Illuminate\Http\Request;

/**
 * CÓMO LE VA A CADA DOMICILIARIO DE ESTA TIENDA.
 *
 * Hasta ahora el tendero solo podía ver una foto del AHORA —cuántos pedidos
 * lleva encima cada uno y cuánto efectivo— y ni una sola cifra de histórico.
 * Para decidir a quién llamar primero mañana eso no sirve: hace falta saber
 * quién entrega, cuánto tarda y quién cumple el plazo.
 *
 * LA CUENTA YA ESTABA HECHA. `GeneradorDeReportes::reporteOperacional()`
 * agrupa por domiciliario desde hace tiempo —entregas, minutos medios,
 * cumplimiento del plazo contra `promised_minutes` congelado— pero vivía
 * detrás del panel de administración, donde el tendero no entra. Acá se le
 * abre la misma consulta, recortada a su tienda. Ni una columna nueva: las
 * marcas de tiempo (`dispatched_at`, `delivery_date`) y los accessors de
 * `OrdersSales` ya existían.
 */
class RendimientoDeDomiciliariosController extends Controller
{
    public function __construct(private readonly GeneradorDeReportes $reportes)
    {
    }

    public function index(Request $request)
    {
        $ventana = $this->reportes->ventanaDelReporte($request);

        /*
         * EL NEGOCIO LO PONE LA SESIÓN, NO EL CLIENTE.
         *
         * `filtrosDelReporte` lee `business_id` de la petición, que es lo
         * correcto para un administrador y lo peligroso acá: un tendero
         * escribiendo otro número vería el rendimiento de los domiciliarios de
         * la tienda de enfrente. Se pisa con el de la sesión, igual que hace
         * el middleware `negocio` con el resto.
         *
         * Los otros tres filtros se anulan por lo mismo: recortan por
         * municipio, categoría o conjunto, y cruzados con una sola tienda solo
         * sirven para sacar cifras raras.
         */
        $filtros = [
            'business_id'       => (int) $request->attributes->get('busines_id'),
            'municipality_id'   => null,
            'business_category' => null,
            'complex_id'        => null,
        ];

        $datos = $this->reportes->reporteOperacional($ventana, $filtros);

        return response()->json([
            'message' => 'Rendimiento de los domiciliarios',
            // Se renombra a castellano en la frontera, como el resto de este
            // grupo de rutas; la clave interna del reporte es `couriers`.
            'domiciliarios' => $datos['couriers'],
            'totales'       => $datos['totals'],
            'anterior'      => $datos['previous'],
            'serie'         => $datos['series'],
            'periodo'       => [
                'desde' => $ventana['desde']->toDateString(),
                'hasta' => $ventana['hasta']->toDateString(),
            ],
        ]);
    }
}
