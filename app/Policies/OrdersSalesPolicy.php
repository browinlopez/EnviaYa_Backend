<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order\OrdersSales;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;

/**
 * QUIÉN PUEDE TOCAR UN PEDIDO.
 *
 * §08 pide Policy o Gate por recurso. Estas dos reglas vivían en métodos
 * privados de dos controladores:
 *
 *   OrderController::puedeMoverPedido()      el cambio de estado
 *   RutaDelPedidoController::puedeVerlo()    ver por dónde va
 *
 * NO CAMBIA NI UNA COMA DE LAS REGLAS. Y la de mover pedido tenía algo que
 * obligaba a pensar: no devuelve sí o no, devuelve EL MOTIVO DEL RECHAZO,
 * porque la app lo enseña. Una Policy puede hacer exactamente eso con
 * `Response::deny($motivo)`, y `Gate::inspect()` lo devuelve entero. Si no se
 * pudiera, esta regla no habría venido aquí: convertir tres mensajes distintos
 * en un «no tienes permiso» genérico sería perder información por cumplir una
 * lista.
 *
 * EL MIDDLEWARE SIGUE DONDE ESTÁ, y esto es la segunda capa. `EXCEPCIONES.md`
 * razona que el middleware **falla cerrado** y una Policy olvidada falla
 * abierta; §35 lo llama defensa en profundidad. Esto no lo sustituye.
 */
class OrdersSalesPolicy
{
    /**
     * Mover el pedido al estado `$destino`. Devuelve permiso o el motivo.
     *
     * Viene de `OrderController::puedeMoverPedido()`, tal cual, y lo que
     * cerró en su día merece quedar escrito: `updateStatus` solo exigía estar
     * autenticado. Como el identificador es un entero correlativo, cualquier
     * sesión válida —la de un comprador recién registrado— podía aceptar,
     * despachar y dar por entregado el pedido de otra persona probando
     * números. Y por esta ruta pasan las tres transiciones de la operación:
     * no es un rincón, es el camino principal.
     *
     * @param  int|string|null  $userIdDomiciliario  a quién se asigna, al despachar
     */
    public function moverA(
        ?User $usuario,
        OrdersSales $order,
        int $destino,
        $userIdDomiciliario = null,
    ): Response {
        $userId = (int) ($usuario->user_id ?? 0);

        $motivo = match ($destino) {
            // Aceptar el pedido es decisión de la tienda que lo va a preparar.
            2 => $this->esDuenoDelNegocio($userId, $order->busines_id)
                ? null
                : 'Solo el negocio del pedido puede aceptarlo.',

            /*
             * Despachar tiene dos caminos legítimos y los dos pasan por acá: el
             * tendero asignándole el pedido a alguien, y el domiciliario
             * tomándolo de la lista. En el segundo caso, quien pide tiene que
             * ser el mismo que se está asignando — si no, cualquiera podría
             * cargarle pedidos a otro y llenarle el cupo.
             */
            3 => ($this->esDuenoDelNegocio($userId, $order->busines_id)
                    || (int) $userIdDomiciliario === $userId)
                ? null
                : 'Solo el negocio o el domiciliario que lo toma pueden despacharlo.',

            /*
             * Entregar solo lo puede declarar quien lo lleva. Es la transición
             * que mueve dinero —registra el cobro en efectivo y la ganancia del
             * domiciliario—, así que acá no se admite ni el tendero.
             */
            // Lo entrega quien lo lleva; si lo recoge el cliente, la tienda.
            4 => $order->pickup
                ? ($this->esDuenoDelNegocio($userId, $order->busines_id)
                    ? null
                    : 'Solo el negocio puede entregar un pedido para recoger.')
                : (($order->domiciliary && (int) $order->domiciliary->user_id === $userId)
                    ? null
                    : 'Solo el domiciliario asignado puede marcar la entrega.'),

            default => 'Transición no reconocida.',
        };

        return $motivo === null ? Response::allow() : Response::deny($motivo);
    }

    /** Ver por dónde va: el comprador, su domiciliario, la tienda y el equipo. */
    public function verRuta(?User $usuario, OrdersSales $pedido): bool
    {
        if (! $usuario) {
            return false;
        }

        if ((int) $usuario->rol === 4) {
            return true;
        }

        $suyo = (int) $usuario->user_id;

        if ((int) ($pedido->buyer?->user_id) === $suyo) {
            return true;
        }

        if ($pedido->domiciliary_id && (int) ($pedido->domiciliary?->user_id) === $suyo) {
            return true;
        }

        return DB::table('owner_busines as ob')
            ->join('owner as o', 'o.owner_id', '=', 'ob.owner_id')
            ->where('ob.busines_id', $pedido->busines_id)
            ->where('o.user_id', $suyo)
            ->exists();
    }

    /**
     * Si ese usuario es dueño del negocio.
     *
     * Consulta directa en vez de cargar relaciones porque acá solo interesa el
     * sí o el no, y esto se llama en el camino caliente de la operación.
     */
    private function esDuenoDelNegocio(?int $userId, $businessId): bool
    {
        if (! $userId || ! $businessId) {
            return false;
        }

        return DB::table('owner_busines as ob')
            ->join('owner as o', 'o.owner_id', '=', 'ob.owner_id')
            ->where('o.user_id', $userId)
            ->where('ob.busines_id', $businessId)
            ->exists();
    }
}
