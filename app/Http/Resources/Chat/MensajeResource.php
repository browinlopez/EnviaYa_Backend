<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * EL MENSAJE QUE DEVUELVE `updateMessage()`.
 *
 * `ChatController::updateMessage()` devolvia `'message' => $message`: el
 * modelo entero, sin lista de claves. Lo que se publicaba era «las columnas
 * que tenga la tabla `messages`», asi que una migracion que añadiera un campo
 * lo sacaba sola.
 *
 * Y estaba a diez lineas de `formatChatResponse()`, que para el MISMO modelo
 * ya usa `MensajeDelChatResource`. Esa no sirve aqui: publica `name` —el
 * nombre de quien escribe, que la burbuja del chat necesita— y no publica
 * `chat_id`. Cambiar una por otra habria añadido un campo y quitado otro a
 * una respuesta que ya usan las apps publicadas, asi que son dos listas
 * distintas a proposito, y cada una dice cual es la suya.
 */
class MensajeResource extends JsonResource
{
    /**
     * Lo que esta respuesta publica HOY, medido contra una fila real.
     *
     * Seis claves. No es la misma lista que `MensajeDelChatResource`: esa lleva `name` y no lleva `chat_id`, asi que no se podia reutilizar sin cambiar la respuesta.
     */
    private const CAMPOS = [
        'message_id',
        'chat_id',
        'user_id',
        'role_id',
        'content',
        'created_at',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return Arr::only($this->resource->toArray(), self::CAMPOS);
    }
}
