<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El estado de un mensaje.
 *
 * `content` va como arreglo y no como texto: lo que se manda es el estado, no
 * el cuerpo del mensaje.
 */
class MarcarMensajeRequest extends FormRequest
{
    /*
     * El permiso lo resuelve el middleware de la ruta, que es donde vive en
     * este backend. Repetirlo aqui seria una segunda fuente de verdad sobre
     * quien puede que, y la que se olvide de actualizar sera la que mande.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message_id' => 'required|exists:messages,message_id',
            'content' => 'required|array',
        ];
    }
}
