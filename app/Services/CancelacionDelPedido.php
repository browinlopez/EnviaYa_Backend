<?php

namespace App\Services;

use App\Events\OrderStatusUpdated;
use App\Models\Order\OrdersSales;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DESHACER UN PEDIDO, QUE ES BASTANTE MÁS QUE PONERLE ESTADO 5.
 *
 * Esto vivía entero dentro de `OrderController::cancel`, y por eso solo podía
 * hacerlo el comprador: ciento cincuenta líneas de método de controlador
 * detrás de una comprobación de «¿es tuyo este pedido?». La tienda NO tenía
 * cómo cancelar. Si al tendero se le acabó el producto después de aceptar, no
 * había puerta: ni para el estado, ni para el inventario, ni para el cupo de
 * fiado, ni para la devolución del dinero.
 *
 * SEIS COSAS SE DESHACEN, Y O SE DESHACEN TODAS O NINGUNA:
 *
 *  1. El estado pasa a 5.
 *  2. Las unidades vuelven al inventario. Un pedido cancelado que siguiera
 *     restando dejaría a la tienda creyendo que vendió lo que no vendió.
 *  3. El cupo de fiado queda otra vez disponible.
 *  4. Los usos de las promociones vuelven a su cupo: una promoción de «los
 *     primeros 50» no puede gastarse en pedidos que acabaron no existiendo.
 *  5. El uso del cupón, igual —y esto faltaba—.
 *  6. Queda constancia en `status_history` de quién, cuándo y por qué.
 *
 * LA PLATA VA FUERA DE LA TRANSACCIÓN, a propósito: habla con Bold por red, y
 * una llamada lenta no puede tener abierta una transacción que bloquea el
 * inventario. Si falla, el pago queda apuntado como pendiente y la cancelación
 * sigue en pie —lo contrario dejaría al cliente sin pedido Y sin cancelación—.
 *
 * NO SE CANCELA UN PEDIDO YA DESPACHADO, y no por falta de ganas: a esas
 * alturas hay alguien en la calle con la bolsa encima, y quién le paga ese
 * viaje es una decisión del negocio que nadie ha tomado. Mientras no esté
 * tomada, el camino no existe. Por lo mismo, acá no hay aviso para el
 * domiciliario: el pedido solo recibe domiciliario al despacharse, así que en
 * los estados que esto admite no hay ninguno a quien avisar.
 */
class CancelacionDelPedido
{
    /** Quién lo canceló. Cambia lo que se le dice al comprador. */
    public const COMPRADOR = 'comprador';
    public const TIENDA    = 'tienda';

    /** El estado de un pedido cancelado. */
    public const CANCELADO = 5;

    public function __construct(
        private readonly ExistenciasDelPedido $existencias,
        private readonly CreditoDeTienda $credito,
        private readonly RedencionDePromociones $promociones,
        private readonly CouponService $cupones,
        private readonly DevolucionDelPedido $devoluciones,
    ) {
    }

    /** El motivo que se guarda cuando nadie dio uno. */
    public function motivoPorDefecto(string $origen): string
    {
        return $origen === self::TIENDA
            ? 'Cancelado por la tienda'
            : 'Cancelado por el comprador';
    }

    /**
     * Cancela el pedido y deshace todo lo que hay que deshacer.
     *
     * Quién puede cancelar y desde qué estado lo decide quien llama: el
     * comprador solo antes de que la tienda lo acepte, la tienda también
     * mientras lo prepara. Acá se da por hecho que eso ya se comprobó, y lo
     * único que se vuelve a mirar —con candado— es que no esté cancelado ya.
     *
     * @return array{ya_estaba: bool, motivo: string, devolucion: ?string, aviso: ?string}
     */
    public function cancelar(
        OrdersSales $order,
        int $porQuien,
        string $origen,
        ?string $motivo = null,
    ): array {
        $motivo = trim((string) $motivo) ?: $this->motivoPorDefecto($origen);

        $hecho = DB::transaction(function () use ($order, $porQuien, $motivo) {
            /*
             * CON CANDADO Y RELEYENDO, igual que al mover el estado.
             *
             * Dos cancelaciones a la vez —el comprador en su teléfono y el
             * tendero en el panel, que es un caso de todos los días— pasarían
             * las dos la comprobación de estado y las dos devolverían el
             * inventario. La segunda encuentra el 5 ya escrito y no hace nada.
             */
            $fresco = OrdersSales::where('orderSales_id', $order->orderSales_id)
                ->lockForUpdate()
                ->first();

            if (!$fresco || (int) $fresco->state === self::CANCELADO) {
                return false;
            }

            $fresco->state = self::CANCELADO;
            $fresco->save();

            $this->existencias->devolver($fresco);
            $this->credito->reversar($fresco, $porQuien);
            $this->promociones->devolver($fresco);
            $this->cupones->devolver((int) $fresco->orderSales_id);

            DB::table('status_history')->insert([
                'order_id'       => $fresco->orderSales_id,
                'status_history' => self::CANCELADO,
                'motivo'         => $motivo,
                'state'          => 1,
                'created_by'     => $porQuien,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            return true;
        });

        if (!$hecho) {
            return ['ya_estaba' => true, 'motivo' => $motivo, 'devolucion' => null, 'aviso' => null];
        }

        $order->refresh();

        $devolucion = $this->devoluciones->devolver($order, $motivo);
        $aviso = DevolucionDelPedido::queDecirle($devolucion, (float) $order->total);

        $this->avisar($order, $origen, $motivo, $devolucion, $aviso);

        // Igual que cualquier otro cambio de estado: la pantalla que esté
        // abierta tiene que enterarse de que ya no lo prepare.
        try {
            broadcast(new OrderStatusUpdated($order));
        } catch (\Throwable $e) {
            Log::warning('No se pudo anunciar la cancelación', [
                'order_id' => $order->orderSales_id,
                'error'    => $e->getMessage(),
            ]);
        }

        return ['ya_estaba' => false, 'motivo' => $motivo, 'devolucion' => $devolucion, 'aviso' => $aviso];
    }

    /**
     * A quién se le cuenta, y qué.
     *
     * Al comprador SIEMPRE, y si fue la tienda la que canceló tiene que saber
     * por qué: él no hizo nada y su pedido desapareció, así que el motivo deja
     * de ser un dato de informe y pasa a ser lo único que le explica el día.
     *
     * A la tienda, solo cuando no fue ella. Mandarle una notificación a quien
     * acaba de pulsar el botón es ruido, y la constancia ya quedó en
     * `status_history`.
     */
    private function avisar(
        OrdersSales $order,
        string $origen,
        string $motivo,
        ?string $devolucion,
        ?string $aviso,
    ): void {
        $datos = [
            'order_id'   => $order->orderSales_id,
            'motivo'     => $motivo,
            'devolucion' => $devolucion,
        ];

        $compradorId = $order->buyer?->user_id;

        if ($compradorId) {
            $mensaje = $origen === self::TIENDA
                ? 'La tienda canceló tu pedido #' . $order->orderSales_id . ': ' . $motivo
                : 'Tu pedido #' . $order->orderSales_id . ' fue cancelado.';

            Avisos::para(
                $compradorId,
                'pedido_cancelado',
                $mensaje . ($aviso ? ' ' . $aviso : ''),
                $datos,
            );
        }

        if ($origen === self::TIENDA) {
            return;
        }

        $duenoDeLaTienda = DB::table('owner_busines')
            ->join('owner', 'owner.owner_id', '=', 'owner_busines.owner_id')
            ->where('owner_busines.busines_id', $order->busines_id)
            ->value('owner.user_id');

        if ($duenoDeLaTienda) {
            Avisos::para(
                $duenoDeLaTienda,
                'pedido_cancelado',
                'El pedido #' . $order->orderSales_id . ' fue cancelado. No lo prepares.',
                $datos,
            );
        }
    }
}
