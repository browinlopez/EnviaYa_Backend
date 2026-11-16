<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActualizarLiquidacionRequest;
use App\Http\Requests\Admin\FiltrarLiquidacionesRequest;
use App\Http\Requests\Admin\GenerarLiquidacionRequest;
use App\Models\Operacion\Settlement;
use App\Services\LiquidacionService;
use App\Support\FiltroDeFechas;
use RuntimeException;

class OperacionApiController extends Controller
{
    public function liquidaciones(FiltrarLiquidacionesRequest $request)
    {
        $q = Settlement::query()
            ->leftJoin('business as b', 'b.busines_id', '=', 'settlements.business_id')
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'settlements.domiciliary_id')
            ->leftJoin('user as du', 'du.user_id', '=', 'd.user_id')
            ->orderByDesc('settlements.id');

        if ($request->filled('type')) {
            $q->where('settlements.type', $request->query('type'));
        }

        if ($request->filled('state')) {
            $q->where('settlements.state', (int) $request->query('state'));
        }

        // El join a `business` ya estaba para el nombre: filtrar por negocio
        // no cuesta nada más.
        if ($request->filled('business_id')) {
            $q->where('settlements.business_id', (int) $request->query('business_id'));
        }

        return response()->json([
            'data' => $q->get([
                'settlements.*',
                'b.name as business_name',
                'du.name as domiciliary_name',
            ]),
            'summary' => [
                'por_pagar' => (float) Settlement::whereIn('state', [Settlement::BORRADOR, Settlement::APROBADA])
                    ->sum('net_payable'),
                'pagado_mes' => (float) FiltroDeFechas::elMes(
                    Settlement::where('state', Settlement::PAGADA),
                    'paid_at', (int) now()->year, (int) now()->month,
                )->sum('net_payable'),
                'borradores' => Settlement::where('state', Settlement::BORRADOR)->count(),
            ],
        ]);
    }

    public function showLiquidacion($id)
    {
        $s = Settlement::with('items')->findOr($id, fn () => abort(404, 'La liquidación no existe.'));

        return response()->json($s);
    }

    public function generarLiquidacion(GenerarLiquidacionRequest $request, LiquidacionService $servicio)
    {
        $datos = $request->validated();

        try {
            $liq = $servicio->generar(
                $datos['type'],
                (int) $datos['target_id'],
                $datos['period_start'],
                $datos['period_end'],
                $request->user()?->user_id,
            );
        } catch (RuntimeException $e) {
            // "No hay pedidos sin liquidar" es información útil, no un fallo:
            // llega tal cual para que quien genera sepa qué pasó.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Liquidación generada con {$liq->orders_count} pedido(s).",
            'id' => $liq->id,
        ], 201);
    }

    public function updateLiquidacion(ActualizarLiquidacionRequest $request, $id)
    {
        $s = Settlement::findOr($id, fn () => abort(404, 'La liquidación no existe.'));

        $datos = $request->validated();

        $nuevo = (int) ($datos['state'] ?? $s->state);

        // Una vez aprobada, los montos son constancia. Solo se admite avanzar
        // en el ciclo o anular; volver a borrador reabriría lo ya facturado.
        if (! $s->editable() && $nuevo < $s->state && $nuevo !== Settlement::ANULADA) {
            return response()->json([
                'message' => 'Una liquidación aprobada no vuelve a borrador. Anúlala y genera una nueva.',
            ], 422);
        }

        if ($nuevo === Settlement::PAGADA && empty($datos['payment_reference'] ?? $s->payment_reference)) {
            return response()->json([
                'message' => 'Para marcarla como pagada hace falta la referencia de la transferencia.',
            ], 422);
        }

        if ($nuevo === Settlement::APROBADA && $s->state === Settlement::BORRADOR) {
            $datos['approved_at'] = now();
        }

        if ($nuevo === Settlement::PAGADA && $s->state !== Settlement::PAGADA) {
            $datos['paid_at'] = now();
        }

        $s->forceFill($datos)->save();

        return response()->json(['message' => 'Liquidación actualizada.']);
    }

    public function deleteLiquidacion($id)
    {
        $s = Settlement::findOr($id, fn () => abort(404, 'La liquidación no existe.'));

        if (! $s->editable()) {
            return response()->json([
                'message' => 'Solo se puede eliminar una liquidación en borrador. Si ya se aprobó, anúlala.',
            ], 422);
        }

        $s->delete();

        return response()->json(['message' => 'Liquidación eliminada.']);
    }
}
