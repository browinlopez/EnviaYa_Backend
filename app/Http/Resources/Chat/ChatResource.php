<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UNA CONVERSACION ENTERA: la cabecera, sus participantes y sus mensajes.
 *
 * Era `ChatController::formatChatResponse()`. Los dos hijos ya viajaban por
 * su Resource —`ParticipanteDelChatResource` y `MensajeDelChatResource`, de la
 * quinta tanda— y la envoltura se habia quedado como array a mano, asi que la
 * forma del chat vivia en un controlador y la de sus partes en
 * `app/Http/Resources`. Media migracion es peor que ninguna: quien busque la
 * forma del chat encuentra las piezas y no el conjunto.
 *
 * `->resolve()` en los dos hijos y no `collection(...)` a secas, igual que
 * antes: devolver el Resource sin resolver mete la envoltura `data` de
 * Laravel, y eso cambiaria la respuesta de las apps publicadas.
 */
class ChatResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $chat = $this->resource;

        return [
            'chat_id' => $chat->chat_id,
            'type' => $chat->type,
            'created_at' => $chat->created_at,
            'participants' => ParticipanteDelChatResource::collection($chat->participants)->resolve(),
            'messages' => MensajeDelChatResource::collection($chat->messages)->resolve(),
        ];
    }
}
