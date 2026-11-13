<?php

declare(strict_types=1);

namespace App\Http\Resources\Domiciliario;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * UN DOMICILIARIO EN LA TORRE DE CONTROL. Trece claves, en castellano.
 *
 * Era el cierre `->map()` de `TorreDeControlController::index()`.
 *
 * LAS MISMAS CIFRAS QUE `DomiciliarioDeLaFlotaResource`, CON OTROS NOMBRES, y
 * esto no es una observacion de estilo: son dos contratos publicados que
 * dicen lo mismo de dos maneras.
 *
 *   aqui              alla
 *   disponible        available
 *   efectivo          cash_on_hand
 *   tope_efectivo     cash_limit
 *   le_cabe           cash_room
 *   a_bordo           active_orders
 *
 * El grupo de rutas `negocio` renombra a castellano en la frontera a
 * proposito --lo dice un comentario del propio controlador-- y el otro no.
 * Unificarlos pide renombrar claves de una respuesta que ya lee un panel
 * publicado, asi que se quedan las dos y la equivalencia esta escrita aqui.
 *
 * `sobre_el_tope` es lo que `cash_room` no dice: esa se queda en cero cuando
 * se pasa, y el tendero necesita distinguir «justo» de «pasado» para decidir
 * si le despacha otro contra entrega.
 *
 * `desde` es el despacho del pedido MAS VIEJO que carga, no el ultimo. Es la
 * cifra que hace saltar al tendero --«lleva hora y media con ese pedido»--, y
 * tomando el ultimo se esconderia justo ese caso cada vez que se le despacha
 * uno nuevo.
 */
class DomiciliarioEnElTableroResource extends JsonResource
{
    /**
     * @param  Collection<int, mixed>  $suyos  Los pedidos que lleva encima.
     */
    public function __construct(
        object $domiciliario,
        private readonly int $id,
        private readonly Collection $suyos,
        private readonly float $efectivo,
        private readonly ?float $tope,
        private readonly mixed $posicion,
    ) {
        parent::__construct($domiciliario);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $d = $this->resource;

        return [
            'domiciliary_id' => $this->id,
            'user_id' => (int) $d->user_id,
            'name' => $d->user?->name,
            'phone' => $d->user?->phone,
            'disponible' => (bool) $d->available,

            'pedidos' => $this->suyos->values(),
            'a_bordo' => $this->suyos->count(),
            // El despacho del pedido MAS VIEJO: ver la cabecera.
            'desde' => $this->suyos->pluck('despachado_el')->filter()->sort()->first(),

            'efectivo' => $this->efectivo,
            'tope_efectivo' => $this->tope,
            'le_cabe' => $this->tope === null ? null : max(0, round($this->tope - $this->efectivo, 2)),
            'sobre_el_tope' => $this->tope !== null && $this->efectivo > $this->tope,

            'posicion' => $this->posicion,
        ];
    }
}
