<?php

declare(strict_types=1);

namespace App\Http\Resources\Domiciliario;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UN DOMICILIARIO EN LA FLOTA DE UNA TIENDA. Quince claves.
 *
 * Era el cierre `->map()` de
 * `DomiciliaryController::listDomiciliariesByBusiness()`: la lista que el
 * tendero mira para decidir a quien le despacha.
 *
 * ES LA SEXTA FORMA DEL MISMO DOMICILIARIO, y conviene verlas juntas porque
 * entre las seis hay VEINTIOCHO claves distintas y NI UNA SOLA esta en las
 * seis:
 *
 *   esta, la flota de la tienda             15  con efectivo y papeles
 *   DomiciliarioEnElTableroResource         13  las mismas cifras en castellano
 *   DomiciliarioEnLaPorteriaResource         8  con su DOCUMENTO
 *   dentro del pedido del comprador          8  con su correo
 *   DomiciliarioDeLaResenaResource           5  solo para calificar
 *   Usuario\DomiciliarioResource (login)    11  con su contrato
 *
 * No es un diseno: es lo que pasa cuando cada pantalla se arma su forma. No
 * se unifican --seis respuestas publicadas-- pero ahora cada una tiene nombre
 * y hay una prueba que mide en que se diferencian.
 *
 * `can_take` SE MANDA RESUELTO y no se deja al cliente, y es la clave que
 * justifica las otras tres que la acompanan: son «las mismas condiciones que
 * valida `orders/update` al despachar». Si la app las evaluara por su cuenta,
 * ensenaria un domiciliario como disponible y el despacho lo rechazaria.
 *
 * `cash_room` igual: la resta la hace el servidor una vez, en vez de la app y
 * el panel cada uno por su lado. El dia que el tope cambie de forma, solo hay
 * un sitio que arreglar.
 */
class DomiciliarioDeLaFlotaResource extends JsonResource
{
    /**
     * @param  array<int, mixed>  $papelesFaltantes
     */
    public function __construct(
        object $domiciliario,
        private readonly int $enCurso,
        private readonly int $maxSimultaneos,
        private readonly bool $puedeTomar,
        private readonly array $papelesFaltantes,
        private readonly float $efectivo,
        private readonly ?float $tope,
    ) {
        parent::__construct($domiciliario);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $d = $this->resource;

        return [
            'domiciliary_id' => $d->domiciliary_id,
            // user_id del domiciliario: requerido para despachar una orden
            // (transicion 2 -> 3 en orders/update).
            'user_id' => $d->user_id,
            'name' => $d->user ? $d->user->name : null,
            'email' => $d->user ? $d->user->email : null,
            'phone' => $d->user ? $d->user->phone : null,
            'available' => $d->available,
            'qualification' => $d->qualification,
            'state' => $d->state,
            'active_orders' => $this->enCurso,
            /*
             * De esos, los de ESTA tienda en camino. `active_orders` cuenta
             * los de todas las tiendas (el cupo es uno solo); este es el que
             * cuadra con la Torre de control y el tablero de Pedidos.
             */
            'en_camino_tuyos' => (int) ($d->en_camino_tuyos ?? 0),
            'max_active_orders' => $this->maxSimultaneos,
            // Mismas condiciones que valida orders/update al despachar.
            'can_take' => $this->puedeTomar,
            'papeles_faltantes' => $this->papelesFaltantes,
            'cash_on_hand' => $this->efectivo,
            'cash_limit' => $this->tope,
            /*
             * Cuanto le cabe todavia en efectivo. Se manda calculado y no se
             * deja al cliente: la app y el panel harian la misma resta cada
             * uno por su lado, y el dia que el tope cambie de forma habria que
             * acordarse de los dos.
             */
            'cash_room' => $this->tope === null ? null : max(0, round($this->tope - $this->efectivo, 2)),
        ];
    }
}
