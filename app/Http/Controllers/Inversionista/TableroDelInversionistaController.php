<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inversionista;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inversionista\TableroDelInversionistaRequest;
use App\Services\TableroDelInversionista;
use Illuminate\Http\JsonResponse;

/**
 * El tablero de seguimiento del inversionista, en el panel de aliados.
 *
 * Solo lectura y solo cifras agregadas. La puerta es `EnsureInvestor`; lo que
 * se devuelve lo arma `TableroDelInversionista`, que no selecciona ni un dato
 * de una persona.
 */
class TableroDelInversionistaController extends Controller
{
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
            'permissions' => [
                'tablero' => ['view' => true, 'manage' => false],
            ],
            'stats' => new \stdClass,
        ]);
    }

    public function tablero(TableroDelInversionistaRequest $request, TableroDelInversionista $tablero): JsonResponse
    {
        return response()->json($tablero->tablero($request->validated()));
    }
}
