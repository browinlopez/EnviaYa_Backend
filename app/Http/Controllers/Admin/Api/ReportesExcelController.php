<?php

namespace App\Http\Controllers\Admin\Api;

use App\Exports\Comercials\ReportGeneralComercial;
use App\Exports\Financial\ReportGeneralExport;
use App\Exports\operational\ReportesOperativosExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

/**
 * LOS REPORTES EN EXCEL
 *
 * Los tres generadores multi-hoja —financiero, comercial y operacional— ya
 * existían en `app/Exports`, pero colgaban del panel anterior en Blade: se
 * llegaba a ellos por `/admin/reportes/.../export`, con sesión web y sin pasar
 * por la autorización por área. Al retirar ese panel se habrían perdido, y son
 * varias hojas de detalle que nadie va a volver a escribir.
 *
 * Acá se sirven desde el API del panel nuevo, detrás de `modulo:reportes`, con
 * la MISMA ventana de fechas que la pantalla está mostrando. Es la diferencia
 * entre un botón que descarga lo que se está viendo y uno que descarga otra
 * cosa parecida.
 *
 * El CSV de la pantalla no se va: son dos herramientas distintas. El CSV es la
 * tabla que se tiene delante; esto es el libro con el detalle por hojas para
 * trabajarlo en Excel.
 */
class ReportesExcelController extends Controller
{
    /** Rótulo del archivo por tipo de reporte. */
    private const ARCHIVOS = [
        'financial'   => 'reporte-financiero',
        'commercial'  => 'reporte-comercial',
        'operational' => 'reporte-operacional',
    ];

    public function __invoke(Request $request, string $kind)
    {
        if (!isset(self::ARCHIVOS[$kind])) {
            /*
             * `businesses` existe como reporte de pantalla pero no tiene libro:
             * su tabla ya se exporta a CSV y armar un Excel de una sola hoja
             * para lo mismo sería dar dos caminos al mismo sitio.
             */
            return response()->json([
                'message' => 'Ese reporte no se entrega en Excel.',
            ], 404);
        }

        [$desde, $hasta] = $this->ventana($request);

        $negocio      = $this->entero($request->query('business_id'));
        $domiciliario = $this->entero($request->query('domiciliary_id'));

        $libro = match ($kind) {
            'financial' => new ReportGeneralExport($negocio, $domiciliario, $desde, $hasta),
            // Los otros dos generadores no admiten filtro de negocio: resumen
            // de toda la operación. Pasarlo y que lo ignoraran en silencio sería
            // peor que no ofrecerlo.
            'commercial'  => new ReportGeneralComercial($desde, $hasta),
            'operational' => new ReportesOperativosExport($desde, $hasta),
        };

        $nombre = self::ARCHIVOS[$kind]
            . '-' . $desde->toDateString()
            . '-a-' . $hasta->toDateString()
            . '.xlsx';

        return Excel::download($libro, $nombre);
    }

    /**
     * La misma ventana que `AdminApiController::report()`.
     *
     * Se repite acá y no se comparte porque aquélla devuelve además el periodo
     * anterior para comparar, que en un libro de Excel no se usa. Lo que SÍ
     * tiene que coincidir es el recorte: si la pantalla dice "últimos 30 días"
     * y el libro trae 90, el número que alguien copie a un correo va a estar mal.
     */
    private function ventana(Request $request): array
    {
        $desde = $this->fecha($request->query('from'));
        $hasta = $this->fecha($request->query('to'));

        if (!$desde || !$hasta) {
            $dias  = max(1, min((int) $request->query('range', 30), 365));
            $hasta = Carbon::today();
            $desde = $hasta->copy()->subDays($dias - 1);
        } elseif ($desde->gt($hasta)) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        // Tope de un año, igual que en la pantalla: pedir cinco años de golpe
        // tumba la consulta y casi siempre es un cero de más en el formulario.
        if ($desde->diffInDays($hasta) + 1 > 366) {
            $desde = $hasta->copy()->subDays(365);
        }

        return [$desde->startOfDay(), $hasta->endOfDay()];
    }

    private function fecha(?string $valor): ?Carbon
    {
        if (!$valor) {
            return null;
        }

        try {
            return Carbon::parse($valor);
        } catch (\Throwable) {
            return null;
        }
    }

    private function entero($valor): ?int
    {
        return ($valor === null || $valor === '' || (int) $valor <= 0) ? null : (int) $valor;
    }
}
