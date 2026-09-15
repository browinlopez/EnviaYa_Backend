<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Operacion\StoreCredit;
use App\Services\CreditoDeTienda;
use Illuminate\Http\Request;

/**
 * EL CRÉDITO DEL COMPRADOR, VISTO POR ÉL.
 *
 * Siempre de la sesión: nadie puede preguntar por el cupo de otra persona.
 */
class MiCreditoController extends Controller
{
    public function __construct(private readonly CreditoDeTienda $creditos)
    {
    }

    /**
     * Cuánto le queda en UNA tienda y si hoy lo puede usar.
     *
     * Es lo que pide la pantalla de pago: con `puede_usar` en falso el método
     * se enseña apagado y con el motivo, en vez de dejar elegirlo y fallar al
     * confirmar.
     */
    public function enTienda(Request $request, int $negocioId)
    {
        $userId = (int) $request->user()->user_id;
        $cupo   = $this->creditos->cupo($negocioId, $userId);

        $motivo = null;

        if ($cupo['limite'] <= 0) {
            $motivo = 'Esta tienda no te ha dado crédito.';
        } elseif (!$this->creditos->estaAfiliado($negocioId, $userId)) {
            $motivo = 'Para usar tu crédito tienes que estar afiliado a esta tienda.';
        } elseif (!$this->creditos->resumenDeLaTienda($negocioId)['puede_vender']) {
            $motivo = 'Por ahora esta tienda no está vendiendo a crédito.';
        } elseif ($cupo['disponible'] <= 0) {
            $motivo = 'Ya usaste todo tu crédito en esta tienda. Cuando le abones, se vuelve a liberar.';
        }

        return response()->json([
            'busines_id' => $negocioId,
            'metodo'     => CreditoDeTienda::METODO,
            'limite'     => $cupo['limite'],
            'usado'      => $cupo['usado'],
            'disponible' => $cupo['disponible'],
            'puede_usar' => $motivo === null,
            'motivo'     => $motivo,
        ]);
    }

    /** Todos sus cupos, tienda por tienda. */
    public function index(Request $request)
    {
        $userId = (int) $request->user()->user_id;

        $creditos = StoreCredit::where('user_id', $userId)->get(['busines_id']);
        $nombres  = Business::whereIn('busines_id', $creditos->pluck('busines_id'))
            ->pluck('name', 'busines_id');

        return response()->json([
            'creditos' => $creditos->map(fn ($c) => ['busines_id' => (int) $c->busines_id, 'tienda' => $nombres[$c->busines_id] ?? null]
                + $this->creditos->cupo((int) $c->busines_id, $userId))
                ->values(),
        ]);
    }
}
