<?php

declare(strict_types=1);

namespace App\Http\Controllers\Domiciliary;

use App\Events\OrderStatusUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Domiciliary\ReportarNovedadRequest;
use App\Http\Requests\Domiciliary\SoltarPedidoRequest;
use App\Models\Order\OrdersSales;
use App\Services\Avisos;
use App\Services\NovedadesDeEntrega;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * LO QUE EL DOMICILIARIO HACE MIENTRAS LLEVA UN PEDIDO, aparte de entregarlo
 * (eso sigue en `OrderController::updateStatus`, con el PIN).
 *
 *  · «Llegué»: avisa al cliente que está en la puerta.
 *  · Soltar: no lo puede llevar; vuelve a «Para tomar».
 *  · Novedad: no se pudo entregar; vuelve al negocio.
 *
 * Un pedido que no es suyo responde 404 y no 403, como la evidencia: que
 * exista pero sea de otro ya dice algo.
 */
class EnCaminoController extends Controller
{
    public function __construct(private readonly NovedadesDeEntrega $novedades) {}

    /** Las listas de motivos, para que la app no las tenga copiadas. */
    public function motivos()
    {
        return response()->json(['data' => [
            'novedad' => $this->lista(NovedadesDeEntrega::MOTIVOS),
            'soltar' => $this->lista(NovedadesDeEntrega::MOTIVOS_PARA_SOLTAR),
            'con_nota' => NovedadesDeEntrega::CON_NOTA,
        ]]);
    }

    public function llegue(Request $request, int $id)
    {
        [$pedido] = $this->suyo($request, $id);

        if (! $pedido) {
            return response()->json(['message' => 'Ese pedido no es tuyo.'], 404);
        }
        if ((int) $pedido->state !== OrdersSales::EN_CAMINO) {
            return response()->json(['message' => 'Ese pedido ya no está en camino.'], 422);
        }

        /* Dos toques a «Llegué» no son dos avisos: el cliente ya lo sabe. */
        if (! $pedido->llego_at) {
            $pedido->llego_at = now();
            $pedido->save();

            $pedido->loadMissing('buyer');
            if ($pedido->buyer?->user_id) {
                Avisos::para(
                    $pedido->buyer->user_id,
                    'domiciliario_llego',
                    "Tu domiciliario llegó con el pedido #{$pedido->orderSales_id}. Ten a mano tu PIN de entrega.",
                    ['order_id' => $pedido->orderSales_id],
                    titulo: 'Tu pedido está en la puerta',
                );
            }

            /* El seguimiento del comprador escucha el canal del pedido: así
               «Tu domiciliario llegó» sale sin que tenga que recargar. */
            try {
                broadcast(new OrderStatusUpdated($pedido));
            } catch (\Throwable $e) {
                Log::warning('No se pudo anunciar la llegada', [
                    'order_id' => $pedido->orderSales_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['data' => ['llego_at' => $pedido->llego_at]]);
    }

    public function soltar(SoltarPedidoRequest $request, int $id)
    {
        $datos = $request->validated();

        [$pedido, $domiciliarioId] = $this->suyo($request, $id);

        if (! $pedido) {
            return response()->json(['message' => 'Ese pedido no es tuyo.'], 404);
        }

        try {
            $this->novedades->soltar($pedido, $domiciliarioId, (int) $request->user()->user_id, $datos['motivo'], $datos['nota'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Listo, lo soltaste. El negocio ya sabe.']);
    }

    public function novedad(ReportarNovedadRequest $request, int $id)
    {
        $datos = $request->validated();

        [$pedido, $domiciliarioId] = $this->suyo($request, $id);

        if (! $pedido) {
            return response()->json(['message' => 'Ese pedido no es tuyo.'], 404);
        }

        try {
            $hecho = $this->novedades->reportar(
                $pedido,
                $domiciliarioId,
                (int) $request->user()->user_id,
                $datos['motivo'],
                $datos['nota'] ?? null,
                isset($datos['latitude']) ? (float) $datos['latitude'] : null,
                isset($datos['longitude']) ? (float) $datos['longitude'] : null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Quedó reportado. Lleva el pedido y la plata de vuelta al negocio.',
            'data' => ['novedad_id' => $hecho['novedad_id'], 'state' => OrdersSales::DEVOLVIENDO],
        ], 201);
    }

    /** @return array{0: ?OrdersSales, 1: int} */
    private function suyo(Request $request, int $orderId): array
    {
        $domiciliarioId = (int) DB::table('domiciliary')
            ->where('user_id', $request->user()->user_id)
            ->value('domiciliary_id');

        if (! $domiciliarioId) {
            return [null, 0];
        }

        $pedido = OrdersSales::where('orderSales_id', $orderId)
            ->where('domiciliary_id', $domiciliarioId)
            ->first();

        return [$pedido, $domiciliarioId];
    }

    private function lista(array $motivos): array
    {
        return array_map(
            fn ($clave, $texto) => ['clave' => $clave, 'texto' => $texto],
            array_keys($motivos),
            $motivos,
        );
    }
}
