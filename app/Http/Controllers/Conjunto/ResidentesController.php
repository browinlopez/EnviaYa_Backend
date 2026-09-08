<?php

namespace App\Http\Controllers\Conjunto;

use App\Http\Controllers\Controller;
use App\Models\Buyer\ResidentialComplex;
use App\Models\Conjunto\ComplexStaff;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Services\MediaService;
use Illuminate\Validation\Rules\Password;

class ResidentesController extends Controller
{
    /* --------------------------- RESIDENTES --------------------------- */

    /**
     * Quién vive en el conjunto, según lo que declararon al registrarse.
     *
     * Sin correo ni teléfono a propósito. El dueño necesita saber cuánta gente
     * de su edificio usa la plataforma y en qué torres, no una lista de
     * contactos de sus residentes: eso son datos personales de terceros y su
     * relación es con la plataforma, no con la administración.
     */
    /**
     * Quiénes son, no cuántos: el detalle por torre y apartamento.
     *
     * SIN CORREO NI TELÉFONO, y no es un olvido. Una administración lleva
     * legítimamente el registro de quién vive en su edificio —eso es lo que se
     * enseña acá—, pero los datos de CONTACTO de cada vecino son de su
     * relación con la plataforma, no con el conjunto. Un listado con teléfonos
     * de 2.000 hogares es una base de datos de mercadeo, no un registro de
     * residentes.
     *
     * Paginado desde el servidor: un conjunto de 2.000 unidades no cabe en una
     * respuesta, y traerlo entero para enseñar veinte filas es tráfico y
     * memoria por nada.
     */
    public function residentesDetalle(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        $porPagina = min(100, max(10, (int) $request->query('per_page', 20)));

        $consulta = DB::table('buyer_complex as bc')
            ->where('bc.complex_id', $complexId)
            ->join('buyer as b', 'b.buyer_id', '=', 'bc.buyer_id')
            ->join('user as u', 'u.user_id', '=', 'b.user_id')
            ->leftJoin('user_address as ua', function ($j) use ($complexId) {
                $j->on('ua.user_id', '=', 'b.user_id')
                    ->where('ua.complex_id', '=', $complexId);
            });

        if ($torre = $request->query('tower')) {
            // `sin` es la torre de quienes se registraron antes de que el
            // campo existiera: no es un valor, es su ausencia.
            if ($torre === 'sin') {
                $consulta->whereNull('ua.tower');
            } else {
                $consulta->where('ua.tower', $torre);
            }
        }

        if ($buscar = trim((string) $request->query('search'))) {
            $consulta->where(function ($q) use ($buscar) {
                $q->where('u.name', 'like', "%{$buscar}%")
                    ->orWhere('ua.apartment', 'like', "%{$buscar}%");
            });
        }

        $pagina = $consulta
            ->orderByRaw('ua.tower IS NULL, ua.tower')
            ->orderByRaw('CAST(ua.apartment AS UNSIGNED), ua.apartment')
            ->select([
                'b.buyer_id',
                'u.name',
                'ua.tower',
                'ua.apartment',
                // Desde cuándo usa la plataforma. Es lo que distingue a un
                // residente de siempre de uno que acaba de llegar.
                'b.state',
            ])
            ->distinct()
            ->paginate($porPagina);

        return response()->json([
            'data' => $pagina->items(),
            'meta' => [
                'page'      => $pagina->currentPage(),
                'per_page'  => $pagina->perPage(),
                'total'     => $pagina->total(),
                'last_page' => $pagina->lastPage(),
            ],
        ]);
    }

    public function residentes(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        $filas = DB::table('buyer_complex as bc')
            ->where('bc.complex_id', $complexId)
            ->join('buyer as b', 'b.buyer_id', '=', 'bc.buyer_id')
            ->leftJoin('user_address as ua', function ($j) use ($complexId) {
                $j->on('ua.user_id', '=', 'b.user_id')
                    ->where('ua.complex_id', '=', $complexId);
            })
            ->groupBy('ua.tower')
            ->orderByRaw('ua.tower IS NULL, ua.tower')
            ->get([
                'ua.tower',
                DB::raw('COUNT(DISTINCT b.buyer_id) as residentes'),
            ]);

        return response()->json([
            'data'  => $filas,
            'total' => (int) $filas->sum('residentes'),
        ]);
    }
}
