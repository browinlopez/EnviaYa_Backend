<?php

declare(strict_types=1);

namespace App\Http\Requests\Seguridad;

use App\Models\Seguridad\ChatReport;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo que manda el teléfono al denunciar a un comprador.
 *
 * Son dos campos y ya: el motivo y lo que el tendero quiera contar. Todo lo
 * demás —a quién se denuncia, de qué tienda, qué se dijo en el chat— lo
 * resuelve el servidor. No es desconfianza al teléfono en abstracto: es que
 * un reporte que el cliente pueda dirigir contra quien quiera no sirve de
 * prueba de nada.
 */
class ReportarChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|in:'.implode(',', ChatReport::MOTIVOS),
            'description' => 'sometimes|nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Dinos qué pasó.',
            'reason.in' => 'Ese motivo no lo conocemos.',
            'description.max' => 'Cuéntalo en menos de 1000 caracteres.',
        ];
    }
}
