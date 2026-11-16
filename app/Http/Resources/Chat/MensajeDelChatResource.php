<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UN MENSAJE DE UNA CONVERSACION.
 *
 * Era el `map` de `ChatController::formatChatResponse()`. Lleva el nombre de
 * quien escribe junto al mensaje —y no solo su id— porque la pantalla del
 * chat lo pinta en cada burbuja y resolverlo en la app pediria una segunda
 * consulta por cada participante.
 */
class MensajeDelChatResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $message = $this->resource;

        return [
            'message_id' => $message->message_id,
            'user_id' => $message->user->user_id,
            'name' => $message->user->name,
            'role_id' => $message->role_id,
            'content' => $message->content,
            'created_at' => $message->created_at,
        ];
    }
}
