<?php

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Support\ListadoPaginado;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * LO QUE SE ROMPIÓ EN EL TELÉFONO DE ALGUIEN.
 *
 * La app manda aquí toda excepción que tumba una pantalla (`ErrorBoundary` →
 * `POST /v1/app/errores`). Antes no había NADA: ni Sentry, ni Crashlytics, ni
 * registro propio, y lo único que se veía de un fallo era una desinstalación.
 *
 * Guardarlo servía de poco sin dónde mirarlo: la tabla existía y solo se podía
 * consultar entrando al contenedor. Esta es esa pantalla.
 *
 * LO QUE DE VERDAD SE PREGUNTA no es «qué fallos hubo» sino «QUÉ SE ESTÁ
 * ROMPIENDO», que es otra cosa: el mismo fallo en cien teléfonos es UN
 * problema, no cien. Por eso el resumen agrupa por mensaje y pantalla, y ordena
 * por cuánta gente lo sufrió.
 */
class FallosDeLaAppApiController extends Controller
{
    /** El listado, uno por uno, para mirar un caso concreto. */
    public function fallos(Request $request)
    {
        $q = DB::table('client_errors as e')
            ->leftJoin('user as u', 'u.user_id', '=', 'e.user_id')
            ->select([
                'e.id', 'e.platform', 'e.app_version', 'e.pantalla',
                'e.mensaje', 'e.traza', 'e.created_at',
                'u.name as user_name', 'u.email as user_email',
            ]);

        if ($plataforma = trim((string) $request->query('platform', ''))) {
            $q->where('e.platform', $plataforma);
        }

        if ($version = trim((string) $request->query('app_version', ''))) {
            $q->where('e.app_version', $version);
        }

        if ($dias = (int) $request->query('days')) {
            $q->where('e.created_at', '>=', Carbon::now()->subDays($dias));
        }

        return response()->json(ListadoPaginado::responder(
            $request,
            $q,
            buscables: ['e.mensaje', 'e.pantalla', 'u.name', 'u.email'],
            ordenables: [
                'id'          => 'e.id',
                'created_at'  => 'e.created_at',
                'pantalla'    => 'e.pantalla',
                'app_version' => 'e.app_version',
            ],
            ordenPorDefecto: 'created_at',
        ));
    }

    /**
     * El resumen: qué se está rompiendo, agrupado.
     *
     * `personas` y no solo `veces`: veinte fallos de una misma persona que
     * insiste son un caso; veinte de veinte personas distintas es una avería.
     * La diferencia decide qué se arregla primero.
     */
    public function resumen(Request $request)
    {
        $desde = Carbon::now()->subDays((int) $request->query('days', 7));

        $filas = DB::table('client_errors')
            ->select([
                'mensaje',
                'pantalla',
                DB::raw('COUNT(*) as veces'),
                DB::raw('COUNT(DISTINCT user_id) as personas'),
                DB::raw('MAX(created_at) as ultimo'),
                DB::raw('MIN(created_at) as primero'),
            ])
            ->where('created_at', '>=', $desde)
            ->groupBy('mensaje', 'pantalla')
            ->orderByDesc('veces')
            ->limit(50)
            ->get();

        return response()->json([
            'desde'   => $desde->toIso8601String(),
            'total'   => DB::table('client_errors')->where('created_at', '>=', $desde)->count(),
            'grupos'  => $filas,
            /*
             * Por version, para responder «¿esto lo trajo la ultima entrega?».
             * Es la primera pregunta despues de publicar.
             */
            'por_version' => DB::table('client_errors')
                ->select('app_version', DB::raw('COUNT(*) as veces'))
                ->where('created_at', '>=', $desde)
                ->groupBy('app_version')
                ->orderByDesc('veces')
                ->get(),
        ]);
    }
}
