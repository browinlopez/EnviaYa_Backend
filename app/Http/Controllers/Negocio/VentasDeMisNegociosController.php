<?php

declare(strict_types=1);

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Http\Requests\Negocio\VentasDeMisNegociosRequest;
use App\Models\Business;
use App\Queries\VentasDelNegocio;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * TUS VENTAS POR NEGOCIO: lo que vendió cada uno de los negocios del dueño,
 * y la suma.
 *
 * Quien tiene una tienda, una droguería y un restaurante tenía que cambiar de
 * negocio en el selector y abrir Ingresos tres veces, y sumar a mano. Esto lo
 * pone en una sola vista, en «Mi cuenta», que es de la persona y no de un
 * local.
 *
 * NO MIRA EL NEGOCIO ACTIVO (`X-Negocio`). Junta TODOS los del dueño, y esa
 * lista no sale de la petición: la escribió el middleware `negocio` a partir
 * de la sesión (`NegocioDelUsuario::negociosDe`, por la cadena de
 * propiedad). No hay ningún parámetro con el que pedir los de otro.
 *
 * LA MISMA VENTA QUE INGRESOS (`VentasDelNegocio::entre`): pedidos de la app
 * entregados y con el pago confirmado, por `sale_date`, sin el domicilio; y
 * ventas del mostrador cobradas, por `vendida_at`. Así la cifra de cada fila
 * cuadra con la que ese negocio ve en sus propias pantallas.
 */
class VentasDeMisNegociosController extends Controller
{
    public function index(VentasDeMisNegociosRequest $request): JsonResponse
    {
        /** @var Collection<int, Business> $negocios */
        $negocios = $request->attributes->get('negocios', collect());

        [$desde, $hasta] = $request->rango() ?? [
            CarbonImmutable::now()->startOfMonth(),
            CarbonImmutable::now()->endOfDay(),
        ];

        $filas = $negocios->map(function (Business $n) use ($desde, $hasta) {
            $v = VentasDelNegocio::entre((int) $n->busines_id, $desde, $hasta);

            return [
                'busines_id' => (int) $n->busines_id,
                'nombre' => (string) $n->name,
                // `category_business`: 1 tienda, 2 droguería, 3 restaurante…
                'tipo' => $n->type === null ? null : (int) $n->type,
                'ventas_app' => $v['ventas_app'],
                'ventas_mostrador' => $v['ventas_mostrador'],
                'total' => $v['ventas'],
                // Ventas, no solo pedidos de la app: las del mostrador también.
                'pedidos' => $v['cuantas'],
            ];
        })->values();

        return response()->json([
            'periodo' => [
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
            ],
            'negocios' => $filas,
            'total' => [
                'ventas_app' => round((float) $filas->sum('ventas_app'), 2),
                'ventas_mostrador' => round((float) $filas->sum('ventas_mostrador'), 2),
                'total' => round((float) $filas->sum('total'), 2),
                'pedidos' => (int) $filas->sum('pedidos'),
            ],
        ]);
    }
}
