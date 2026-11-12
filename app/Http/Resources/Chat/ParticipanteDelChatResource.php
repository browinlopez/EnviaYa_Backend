<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * QUIEN ESTA EN UNA CONVERSACION: nombre y nada mas.
 *
 * Era el `map` de `ChatController::formatChatResponse()`. Cuatro campos:
 * `user_id`, `name`, `role_id` y `joined_at`.
 *
 * NO ES LA MISMA FORMA que `ParticipanteDeLaListaResource`, que sirve
 * `POST /chats/user-chats` y manda NUEVE —anade email, telefono, direccion,
 * calificacion y estado—. La diferencia esta medida y escrita en la cabecera
 * de esa otra clase, y no es un detalle de estilo.
 */
class ParticipanteDelChatResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $participant = $this->resource;

        return [
            'user_id' => $participant->user->user_id,
            'name' => $participant->user->name,
            'role_id' => $participant->role_id,
            'joined_at' => $participant->joined_at,
        ];
    }
}
