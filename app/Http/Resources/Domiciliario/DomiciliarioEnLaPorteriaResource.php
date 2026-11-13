<?php

declare(strict_types=1);

namespace App\Http\Resources\Domiciliario;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * QUIEN ESTA EN LA PUERTA DEL CONJUNTO. Ocho claves.
 *
 * Era el metodo `ficha()` de `PorteriaController`, el que ve el celador
 * cuando un domiciliario llega a la porteria.
 *
 * ES LA UNICA DE LAS SEIS FORMAS DEL DOMICILIARIO QUE PUBLICA SU DOCUMENTO,
 * y tiene motivo: el celador compara la cedula con la persona que tiene
 * delante. Las otras cinco no lo llevan --ni la del tendero, ni la del
 * tablero, ni la que va dentro de un pedido-- y asi debe seguir: fuera de la
 * puerta, el documento no sirve para nada y es el dato mas sensible que tiene
 * un domiciliario en el sistema.
 *
 * LLEVA MAS DE LO QUE HACE FALTA PARA DECIDIR SI PASA --eso ya lo decidio la
 * consulta de pedidos-- porque la decision no siempre es automatica. Un
 * celador que ve «primera vez que entra» mira con mas cuidado que uno que ve
 * «lleva 40 entradas», y sin el dato las dos situaciones se ven igual.
 *
 * La calificacion y el historial son del DOMICILIARIO, no del comprador: aca
 * no se expone nada de quien pidio.
 *
 * `available` NO impide entrar --ya tiene los pedidos encima-- pero explica
 * por que a veces no aparece en la lista del tendero.
 */
class DomiciliarioEnLaPorteriaResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $historial
     */
    public function __construct(
        object $domiciliario,
        private readonly array $historial,
    ) {
        parent::__construct($domiciliario);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $d = $this->resource;

        return [
            'domiciliary_id' => $d->domiciliary_id,
            'name' => $d->user->name ?? null,
            // El unico sitio donde sale: ver la cabecera.
            'document' => $d->document,
            'phone' => $d->user->phone ?? null,
            'qualification' => $d->qualification !== null ? (float) $d->qualification : null,
            'available' => (bool) $d->available,
            'activo' => (bool) $d->state,
            'historial' => $this->historial,
        ];
    }
}
