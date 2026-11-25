<?php

declare(strict_types=1);

namespace App\Http\Controllers\Order;

use App\Events\OrderStatusUpdated;
use App\Http\Controllers\Controller;
use App\Models\Order\OrdersSales;
use App\Services\Avisos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * «YA ESTÁ LISTO, PASA A RECOGERLO».
 *
 * Un pedido para recoger no tiene domiciliario que avise que salió: el
 * cliente no sabía cuándo pasar y llegaba a preguntar. La tienda toca «Ya
 * está listo», se marca `ready_at` y al cliente le llega el aviso.
 *
 * Después, cuando el cliente pasa, la tienda lo entrega con el cambio de
 * estado de siempre (2 → 4), que para los de recoger es de la tienda.
 */
class ListoParaRecogerController extends Controller
{
    public function __invoke(Request $request, int $id): JsonResponse
    {
        $pedido = OrdersSales::with('business', 'buyer')->find($id);

        if (! $pedido) {
            return response()->json(['message' => 'Pedido no encontrado.'], 404);
        }

        $esDueno = DB::table('owner_busines as ob')
            ->join('owner as o', 'o.owner_id', '=', 'ob.owner_id')
            ->where('o.user_id', $request->user()?->user_id)
            ->where('ob.busines_id', $pedido->busines_id)
            ->exists();

        if (! $esDueno) {
            return response()->json(['message' => 'Este pedido no es de tu negocio.'], 403);
        }

        if (! $pedido->pickup) {
            return response()->json(['message' => 'Este pedido va a domicilio: sale con el domiciliario.'], 422);
        }

        if ((int) $pedido->state !== OrdersSales::PREPARANDO) {
            return response()->json(['message' => 'Primero acepta el pedido.'], 422);
        }

        if (! $pedido->ready_at) {
            $pedido->ready_at = now();
            $pedido->save();

            Avisos::para(
                $pedido->buyer->user_id,
                'pedido_listo_para_recoger',
                "Tu pedido de {$pedido->business->name} está listo. Ya puedes pasar a recogerlo."
                    .($pedido->pin_entrega ? " Tu código para recogerlo es {$pedido->pin_entrega}." : ''),
                ['order_id' => $pedido->orderSales_id, 'negocio' => $pedido->business->name],
            );

            try {
                broadcast(new OrderStatusUpdated($pedido));
            } catch (\Throwable $e) {
                Log::warning('No se pudo anunciar que el pedido está listo', [
                    'order_id' => $pedido->orderSales_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Le avisamos al cliente que ya puede pasar.',
            'ready_at' => $pedido->ready_at,
        ]);
    }
}
