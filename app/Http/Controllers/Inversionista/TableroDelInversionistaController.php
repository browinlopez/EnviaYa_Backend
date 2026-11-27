<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inversionista;

use App\Exports\Inversionista\InformeDelInversionistaExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inversionista\TableroDelInversionistaRequest;
use App\Services\Inversionista\ExpansionDelInversionista;
use App\Services\Inversionista\InformeDelInversionista;
use App\Services\Inversionista\IngresosDelInversionista;
use App\Services\Inversionista\NegociosDelInversionista;
use App\Services\Inversionista\OperacionDelInversionista;
use App\Services\Inversionista\ResumenDelInversionista;
use App\Services\Inversionista\UsuariosDelInversionista;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Los módulos del inversionista, en el panel de aliados.
 *
 * Solo lectura y solo cifras agregadas. La puerta es `EnsureInvestor`; lo que
 * se devuelve lo arma cada servicio de `App\Services\Inversionista`, que no
 * selecciona hacia la respuesta ni un dato de una persona ni de un negocio.
 *
 * Todos los módulos reciben los MISMOS filtros (`desde`, `hasta`,
 * `municipality_id`, `tipo`) y devuelven `periodo` y `filtros` además de lo
 * suyo, para que la barra de filtros del panel sea una sola.
 */
class TableroDelInversionistaController extends Controller
{
    /** Los módulos, en el orden del menú. Cada uno es un permiso de `me`. */
    public const MODULOS = ['resumen', 'negocios', 'expansion', 'usuarios', 'operacion', 'ingresos', 'informes'];

    /**
     * Con la MISMA forma que `/conjunto/me` y `/negocio/me`: el panel arma el
     * menú, el guardia de rutas y la barra con `permissions`, sin saber de qué
     * ámbito vienen. El «área» del inversionista es la empresa entera.
     */
    public function mio(): JsonResponse
    {
        return response()->json([
            'area' => ['name' => "VeciPa'Ya"],
            'role' => 'inversionista',
            'permissions' => array_fill_keys(self::MODULOS, ['view' => true, 'manage' => false]),
            'stats' => new \stdClass,
        ]);
    }

    /** Lo que ofrece la barra de filtros: ciudades y tipos de negocio. */
    public function opciones(ResumenDelInversionista $resumen): JsonResponse
    {
        return response()->json($resumen->opciones());
    }

    public function resumen(TableroDelInversionistaRequest $request, ResumenDelInversionista $s): JsonResponse
    {
        return response()->json($s->para($request->validated()));
    }

    public function negocios(TableroDelInversionistaRequest $request, NegociosDelInversionista $s): JsonResponse
    {
        return response()->json($s->para($request->validated()));
    }

    public function expansion(TableroDelInversionistaRequest $request, ExpansionDelInversionista $s): JsonResponse
    {
        return response()->json($s->para($request->validated()));
    }

    public function usuarios(TableroDelInversionistaRequest $request, UsuariosDelInversionista $s): JsonResponse
    {
        return response()->json($s->para($request->validated()));
    }

    public function operacion(TableroDelInversionistaRequest $request, OperacionDelInversionista $s): JsonResponse
    {
        return response()->json($s->para($request->validated()));
    }

    public function ingresos(TableroDelInversionistaRequest $request, IngresosDelInversionista $s): JsonResponse
    {
        return response()->json($s->para($request->validated()));
    }

    /**
     * El informe para descargar: Excel (por defecto) o PDF, con las cifras de
     * los seis módulos para el periodo y los filtros pedidos.
     */
    public function informe(TableroDelInversionistaRequest $request, InformeDelInversionista $informe): Response
    {
        $datos = $informe->datos($request->validated());

        if ($request->validated('formato') === 'pdf') {
            return response($informe->pdf($datos), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$informe->nombre($datos, 'pdf').'"',
            ]);
        }

        return Excel::download(new InformeDelInversionistaExport($datos), $informe->nombre($datos, 'xlsx'));
    }
}
