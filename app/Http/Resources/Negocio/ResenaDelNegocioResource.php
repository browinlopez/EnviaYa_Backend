<?php

declare(strict_types=1);

namespace App\Http\Resources\Negocio;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UNA RESENA DEL NEGOCIO, SIN LOS DATOS DE QUIEN LA ESCRIBIO.
 *
 * Era `NegocioParaLaApp::resenas()`, movida aqui sin tocar un campo. Viaja el
 * `buyer_id` y nada mas de la persona: la vitrina es publica.
 *
 * Los `?? null` y `?? ''` se conservan tal cual. `comment` cae a cadena vacia
 * y no a nulo porque la app publicada lo concatena, y `rating` a 0.0 por lo
 * mismo: cambiar un valor por omision aqui es cambiar lo que pinta una
 * pantalla que ya esta en los telefonos.
 */
class ResenaDelNegocioResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $review = $this->resource;

        return [
            'review_id' => $review->reviews_id ?? null,
            'buyer_id' => $review->buyer_id,
            'rating' => (float) ($review->qualification ?? 0),
            'comment' => $review->comment ?? '',
            'created_at' => $review->created_at ?? null,
        ];
    }
}
