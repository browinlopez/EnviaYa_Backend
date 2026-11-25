<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use App\Models\Chat\Chat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UNA CONVERSACION EN LA LISTA DE CHATS. Siete claves.
 *
 * Era el cierre `->map()` de `ChatController::getUserChats()`.
 *
 * NO ES `ChatResource`, que es la conversacion ABIERTA. La diferencia es de
 * proposito y se ve en las claves: esta lleva el ultimo mensaje aplastado en
 * tres campos --`last_message`, `last_message_at`, `last_message_user_id`--
 * para pintar la vista previa de la lista, y NO lleva `messages`, que seria
 * traerse el historial completo de cada conversacion para dibujar una lista.
 *
 * OJO CON `participants`: va por `ParticipanteDeLaListaResource`, que publica
 * NUEVE campos --con correo, telefono y direccion-- mientras que
 * `ParticipanteDelChatResource`, el de la conversacion abierta, publica
 * cuatro. Esta medido y escrito en la cabecera de aquella clase; es un asunto
 * sin decidir, y es al reves de lo que uno esperaria: la LISTA lleva mas
 * datos personales que la conversacion.
 */
class ChatDeLaListaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /* Tipado a proposito: sin esto el recurso es `mixed` y los avisos
           de relacion no declarada no se arreglan, se esconden. */
        /** @var Chat $chat */
        $chat = $this->resource;
        $ultimo = $chat->messages->first();

        return [
            'chat_id' => $chat->chat_id,
            'type' => $chat->type,
            /*
             * El NOMBRE DEL NEGOCIO, si es un chat con una tienda: el comprador
             * reconoce «Tienda Lupe», no el nombre de quien la atiende.
             */
            'negocio' => $chat->negocio?->name,
            'business_id' => $chat->busines_id,
            'created_at' => $chat->created_at,
            'last_message' => $ultimo?->content,
            'last_message_at' => $ultimo?->created_at,
            'last_message_user_id' => $ultimo?->user_id,
            'participants' => ParticipanteDeLaListaResource::collection($chat->participants)->resolve(),
        ];
    }
}
