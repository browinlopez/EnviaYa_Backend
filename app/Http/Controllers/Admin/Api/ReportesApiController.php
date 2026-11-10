<?php

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Services\GeneradorDeReportes;
use App\Support\Consultas\AyudasDeAdmin;
use Illuminate\Http\Request;

class ReportesApiController extends Controller
{
    public function __construct(
        private readonly GeneradorDeReportes $reportes,
    ) {}

    use AyudasDeAdmin;

    /**
     * EL DESGLOSE POR NEGOCIO DE UNA CIFRA.
     *
     * Las tarjetas del panel eran callejones sin salida: decían "ingresos
     * $3,3 M" y la única forma de saber de dónde salían era cambiar de pestaña
     * y volver a leerlo todo. Ahora se pulsan y esto responde qué negocio
     * aporta cuánto, con los MISMOS filtros y la misma ventana — si no, el
     * desglose no sumaría lo que dice la tarjeta y sería peor que no tenerlo.
     *
     * Reutiliza `reportePorNegocio`, que ya calcula todo esto: lo único que
     * cambia es por qué columna se ordena.
     */
    public function desglose(Request $request)
    {
        $ventana = $this->reportes->ventanaDelReporte($request);
        $filtros = $this->reportes->filtrosDelReporte($request);

        $metrica = $request->query('metric', 'revenue');

        $columnas = [
            'revenue' => 'revenue',
            'orders' => 'orders',
            'delivered' => 'delivered',
            'cancelled' => 'cancelled',
            'avg_ticket' => 'avg_ticket',
            'buyers' => 'buyers',
        ];

        if (! isset($columnas[$metrica])) {
            return response()->json([
                'message' => 'Esa cifra no tiene desglose por negocio.',
                'metrics' => array_keys($columnas),
            ], 404);
        }

        $datos = $this->reportes->reportePorNegocio($ventana, $filtros);

        return response()->json([
            'metric' => $metrica,
            'period' => [
                'from' => $ventana['desde']->toDateString(),
                'to' => $ventana['hasta']->toDateString(),
            ],
            'filters' => $this->reportes->filtrosLegibles($filtros),
            'businesses' => collect($datos['businesses'])
                ->sortByDesc($columnas[$metrica])
                ->values(),
        ]);
    }

    public function report(Request $request, string $kind)
    {
        $ventana = $this->reportes->ventanaDelReporte($request);
        $filtros = $this->reportes->filtrosDelReporte($request);

        $datos = match ($kind) {
            'financial' => $this->reportes->reporteFinanciero($ventana, $filtros),
            'commercial' => $this->reportes->reporteComercial($ventana, $filtros),
            'operational' => $this->reportes->reporteOperacional($ventana, $filtros),
            'businesses' => $this->reportes->reportePorNegocio($ventana, $filtros),
            default => null,
        };

        if ($datos === null) {
            return response()->json(['message' => 'Tipo de reporte no válido.'], 404);
        }

        return response()->json($datos + [
            'period' => [
                'from' => $ventana['desde']->toDateString(),
                'to' => $ventana['hasta']->toDateString(),
                'days' => $ventana['dias'],
                'previous' => [
                    'from' => $ventana['desde_antes']->toDateString(),
                    'to' => $ventana['hasta_antes']->toDateString(),
                ],
            ],
            'filters' => $this->reportes->filtrosLegibles($filtros),
        ]);
    }
}
