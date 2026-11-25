<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Los mensajes de una conversación, pedida de una de dos formas:
 *
 *   · con `chat_id`: ese chat tiene que existir;
 *   · sin él: hacen falta las DOS personas, porque entonces el chat se busca
 *     por la pareja.
 *
 * Eran dos validaciones en línea, una por rama. `required_without` dice lo
 * mismo en una sola: las personas solo se exigen cuando no viene el chat. Los
 * errores salen en los mismos campos que antes (ver
 * `EntradaDeChatYFichasTest`).
 */
class MensajesDelChatRequest extends FormRequest
{
    /** La puerta la cuida la ruta; quién participa lo mira el controlador. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'chat_id' => 'nullable|exists:chats,chat_id',
            'user_id' => 'required_without:chat_id|exists:user,user_id',
            'recipient_id' => 'required_without:chat_id|exists:user,user_id',
        ];
    }
}
