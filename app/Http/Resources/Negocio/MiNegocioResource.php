<?php

declare(strict_types=1);

namespace App\Http\Resources\Negocio;

use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * LA TIENDA, COMO LA VE SU PROPIO TENDERO. Dieciocho claves.
 *
 * Era el metodo `ficha()` de `MiNegocioController`, y lo sirven cuatro rutas
 * del panel: ver la tienda, corregirla, cambiarle el logo y pausarla.
 *
 * LO QUE NO LLEVA, Y ES LO INTERESANTE: `credit_debt_cap`. Esta es la
 * respuesta de la tienda a su propio dueño y NO incluye el tope de credito,
 * que es exactamente lo que `NegocioRequest` documenta con estas palabras:
 * «solo lo pone el equipo; el panel del tendero no lo recibe».
 *
 * Aqui se cumple. Donde no se cumple es en el login: el bloque `businesses`
 * que cuelga de `owner` y de `domiciliary` lo manda --esta medido y escrito
 * en la cabecera de `Usuario\NegocioVinculadoResource`--. Asi que la regla
 * existe, esta implementada en un sitio y se incumple en otro, y tenerlo en
 * dos clases hermanas es lo que permite verlo.
 *
 * `max_courier_cash` SI va, y debe ir: es el tope de efectivo que el tendero
 * le pone a sus domiciliarios, y lo configura el. Sin el, la pantalla lo
 * mostraba vacio y al guardar lo borraba.
 *
 * `accepting_orders` ES EL ESTADO EFECTIVO, no la columna. Una pausa «hasta
 * las 3» ya no vale a las 4, y el panel tiene que pintar lo mismo que decide
 * el servidor cuando llega un pedido: de ahi `recibiendo_ahora` y no
 * `accepting_orders`. Va separado de `state`, que es el alta en la
 * plataforma: una tienda dada de alta puede estar hoy sin atender.
 */
class MiNegocioResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Business $b */
        $b = $this->resource;

        return [
            'busines_id' => (int) $b->busines_id,
            'name' => $b->name,
            'phone' => $b->phone,
            'address' => $b->address,
            'description' => $b->description,
            'NIT' => $b->NIT,
            'razonSocial_DCD' => $b->razonSocial_DCD,
            'logo' => $b->logo,
            // El tope de efectivo de sus domiciliarios. Sin el, la pantalla
            // de la app lo mostraba vacio y al guardar lo borraba.
            'max_courier_cash' => $b->max_courier_cash !== null ? (float) $b->max_courier_cash : null,
            'type' => (int) $b->type,
            'state' => (int) $b->state,
            /*
             * El interruptor del mostrador, y el estado EFECTIVO: ver la
             * cabecera. `Business::recibiendoAhora` es quien lo decide.
             */
            'accepting_orders' => (bool) $b->recibiendo_ahora,
            // Hasta cuando dura la pausa, para escribir «Pausada hasta las
            // 3:00 p. m.». Nula cuando no tiene hora o cuando ya paso.
            'accepting_until' => $b->recibiendo_ahora
                ? null
                : $b->accepting_until?->toIso8601String(),
            // Desde cuando esta asi, para poder avisar «llevas cuatro horas
            // sin recibir pedidos» a quien pauso sin hora y se le olvido.
            'accepting_changed_at' => $b->accepting_changed_at?->toIso8601String(),
            'qualification' => $b->qualification !== null ? (float) $b->qualification : null,
            'municipality_id' => $b->municipality_id !== null ? (int) $b->municipality_id : null,
            'latitude' => $b->latitude !== null ? (float) $b->latitude : null,
            'longitude' => $b->longitude !== null ? (float) $b->longitude : null,
        ];
    }
}
